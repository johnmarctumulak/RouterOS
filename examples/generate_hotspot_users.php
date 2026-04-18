<?php

declare(strict_types=1);

/**
 *  RouterOS - Hotspot User Bulk Generator
 *
 *  Generates large numbers of hotspot users using pipelined async
 *  tagged commands for maximum performance.
 *
 *  Usage:
 *    php examples/generate_hotspot_users.php           (generate 10,000)
 *    php examples/generate_hotspot_users.php --count=500
 *    php examples/generate_hotspot_users.php --cleanup  (remove generated)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\Sentence;
use RouterOS\Exception\TrapException;
use RouterOS\Exception\ConnectionException;

// ── Config ────────────────────────────────────────────────────────────────────
$HOST = '192.168.88.1';
$USERNAME = 'admin';
$PASSWORD = 'password';
$USE_SSL = false;

// ── Generation Settings ───────────────────────────────────────────────────────
$COUNT = 10_000;  // total users to generate
$PREFIX = 'hs-';   // username prefix  → hs-00001 … hs-10000
$PASS_PREFIX = 'pw-';  // password prefix  → pw-00001 … pw-10000
$PROFILE = 'default'; // hotspot user profile
$SERVER = '';      // hotspot server name — leave empty for "all servers"
$COMMENT = 'bulk-generated'; // comment tag for easy cleanup
$BATCH_SIZE = 25;      // commands in-flight before collecting
//   lower = gentler on router CPU
//   higher = faster but spikes CPU on embedded ARM
$DELAY_MS = 200;     // milliseconds to sleep BETWEEN batches
//   0 = no delay (max speed, max CPU)
//   200 = gentle on hEX/hAP (keeps CPU ≈40-50%)
//   500 = very gentle (keeps CPU ≈20-30%)

// ── Parse CLI args ────────────────────────────────────────────────────────────
$opts = getopt('', ['count:', 'cleanup', 'batch:', 'prefix:', 'delay:']);
if (isset($opts['count'])) {
    $COUNT = (int) $opts['count'];
}
if (isset($opts['batch'])) {
    $BATCH_SIZE = (int) $opts['batch'];
}
if (isset($opts['prefix'])) {
    $PREFIX = $opts['prefix'];
}
if (isset($opts['delay'])) {
    $DELAY_MS = (int) $opts['delay'];
}
$doCleanup = isset($opts['cleanup']);

// ── Helpers ───────────────────────────────────────────────────────────────────
function progress(int $done, int $total, float $startTime): void
{
    $pct = $total > 0 ? round($done / $total * 100) : 0;
    $elapsed = microtime(true) - $startTime;
    $rps = $elapsed > 0 ? round($done / $elapsed) : 0;
    $eta = ($rps > 0 && $done < $total)
        ? round(($total - $done) / $rps) . 's'
        : '—';

    $bar = str_repeat('█', (int) ($pct / 2)) . str_repeat('░', 50 - (int) ($pct / 2));
    echo "\r  [{$bar}] {$pct}%  {$done}/{$total}  {$rps} users/s  ETA:{$eta}   ";
}

// ─────────────────────────────────────────────────────────────────────────────
echo "-----------------------------------------------------------------" . PHP_EOL;
echo "  RouterOS Hotspot Bulk " . ($doCleanup ? 'Cleanup' : 'Generator') . "  ->  {$HOST}" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL . PHP_EOL;

// ── Connect ───────────────────────────────────────────────────────────────────
echo "  Connecting to {$HOST}… ";
try {
    $config = new Config(
        host: $HOST,
        username: $USERNAME,
        password: $PASSWORD,
        ssl: $USE_SSL,
        connectTimeout: 10.0,
        readTimeout: 30.0,  // longer timeout for bulk ops
    );
    $client = Client::connect($config);
    echo "\033[32mOK\033[0m\n";
} catch (ConnectionException $e) {
    echo "\033[31mFAILED\033[0m\n  ↳ " . $e->getMessage() . "\n\n";
    exit(1);
}

// ─────────────────────────────────────────────────────────────────────────────
if ($doCleanup) {
    // ── CLEANUP MODE: remove all users with matching comment ─────────────────
    echo "\n  Finding users with comment='{$COMMENT}'… ";
    $rows = $client->query('/ip/hotspot/user/print')
        ->proplist(['.id', 'name', 'comment'])
        ->where('comment', $COMMENT)
        ->fetch();
    echo count($rows) . " found\n\n";

    if (empty($rows)) {
        echo "  Nothing to remove.\n\n";
        $client->close();
        exit(0);
    }

    echo "  Removing " . count($rows) . " users in batches of {$BATCH_SIZE}…\n";
    $start = microtime(true);
    $done = 0;
    $errors = 0;
    $pending = [];  // [tag => id]

    foreach ($rows as $row) {
        $sentence = Sentence::command('/ip/hotspot/user/remove')
            ->attr('.id', $row['.id']);
        $tag = $client->sendAsync($sentence);
        $pending[$tag] = $row['name'];

        if (count($pending) >= $BATCH_SIZE) {
            foreach ($pending as $t => $name) {
                try {
                    $client->collectTag($t);
                    $done++;
                } catch (TrapException $e) {
                    $errors++;
                    echo "\n  \033[33mWARN: remove {$name}: " . $e->getMessage() . "\033[0m";
                }
            }
            $pending = [];
            // Inter-batch delay — protect router CPU during bulk remove too
            if ($DELAY_MS > 0) {
                usleep($DELAY_MS * 1000);
            }
            progress($done, count($rows), $start);
        }
    }

    // Flush remaining
    foreach ($pending as $t => $name) {
        try {
            $client->collectTag($t);
            $done++;
        } catch (TrapException $e) {
            $errors++;
        }
    }
    progress($done, count($rows), $start);

    $elapsed = round(microtime(true) - $start, 2);
    echo "\n\n  \033[32mDone!\033[0m  Removed {$done} users in {$elapsed}s";
    if ($errors) {
        echo "  (\033[33m{$errors} errors\033[0m)";
    }
    echo "\n\n";
    $client->close();
    exit(0);
}

// ─────────────────────────────────────────────────────────────────────────────
// ── GENERATE MODE ─────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────────

echo "  Settings:\n";
echo "    Users to generate : \033[1;33m{$COUNT}\033[0m\n";
echo "    Username format   : {$PREFIX}00001 … {$PREFIX}" . str_pad((string) $COUNT, strlen((string) $COUNT), '0', STR_PAD_LEFT) . "\n";
echo "    Password format   : {$PASS_PREFIX}00001 … {$PASS_PREFIX}" . str_pad((string) $COUNT, strlen((string) $COUNT), '0', STR_PAD_LEFT) . "\n";
echo "    Profile           : {$PROFILE}\n";
echo "    Comment tag       : {$COMMENT}  (use --cleanup to remove these later)\n";
echo "    Batch size        : {$BATCH_SIZE} commands in-flight\n";
echo "    Batch delay       : {$DELAY_MS} ms between batches";
if ($DELAY_MS === 0) {
    echo " \033[33m(no delay — max CPU load)\033[0m";
} elseif ($DELAY_MS <= 100) {
    echo " \033[33m(low delay — may spike CPU on ARM routers)\033[0m";
} else {
    echo " \033[32m(gentle on router CPU)\033[0m";
}
echo "\n";
echo "\n";

// Check hotspot server exists
$servers = $client->query('/ip/hotspot/print')
    ->proplist(['.id', 'name', 'disabled'])
    ->fetch();

if (empty($servers)) {
    echo "  \033[33mWARN: No hotspot server found on this router.\033[0m\n";
    echo "  Users will be added to the user database anyway.\n";
    echo "  If you need them on a specific server, set \$SERVER in the config.\n\n";
} else {
    $names = array_map(fn($s) => $s['name'] . ($s['disabled'] === 'true' ? ' (disabled)' : ''), $servers);
    echo "  Hotspot servers: " . implode(', ', $names) . "\n\n";
}

// Check for existing generated users to avoid duplicates
$existingCheck = $client->query('/ip/hotspot/user/print')
    ->proplist(['.id'])
    ->where('comment', $COMMENT)
    ->fetch();

if (!empty($existingCheck)) {
    echo "  \033[33mWARN: " . count($existingCheck) . " users with comment='{$COMMENT}' already exist.\033[0m\n";
    echo "  Run with --cleanup first to remove them, or they may conflict.\n\n";
}

echo "  Generating {$COUNT} users";
echo " (async pipeline, batch size {$BATCH_SIZE})…\n\n";

$start = microtime(true);
$done = 0;
$errors = 0;
$errorLog = [];
$pending = [];  // [tag => username]
$padLen = strlen((string) $COUNT);

for ($i = 1; $i <= $COUNT; $i++) {
    $name = $PREFIX . str_pad((string) $i, $padLen, '0', STR_PAD_LEFT);
    $pass = $PASS_PREFIX . str_pad((string) $i, $padLen, '0', STR_PAD_LEFT);

    // Build the add sentence
    $sentence = Sentence::command('/ip/hotspot/user/add')
        ->attr('name', $name)
        ->attr('password', $pass)
        ->attr('profile', $PROFILE)
        ->attr('comment', $COMMENT);

    if ($SERVER !== '') {
        $sentence->attr('server', $SERVER);
    }

    // Fire without waiting — async tagged command
    $tag = $client->sendAsync($sentence);
    $pending[$tag] = $name;

    // Every BATCH_SIZE commands, collect results then pause.
    // The pause (DELAY_MS) is the critical CPU protection:
    // without it, the router's config write queue backs up and
    // the ARM CPU hits 100% trying to flush entries to flash.
    if (count($pending) >= $BATCH_SIZE) {
        foreach ($pending as $t => $uname) {
            try {
                $client->collectTag($t);
                $done++;
            } catch (TrapException $e) {
                $errors++;
                if (count($errorLog) < 20) {
                    $errorLog[] = "{$uname}: " . $e->getMessage();
                }
            }
        }
        $pending = [];
        // Inter-batch delay — let the router's write queue drain
        if ($DELAY_MS > 0) {
            usleep($DELAY_MS * 1000);
        }
        progress($done, $COUNT, $start);
    }
}

// Flush the last partial batch
foreach ($pending as $t => $uname) {
    try {
        $client->collectTag($t);
        $done++;
    } catch (TrapException $e) {
        $errors++;
        if (count($errorLog) < 20) {
            $errorLog[] = "{$uname}: " . $e->getMessage();
        }
    }
}
progress($done, $COUNT, $start);

$elapsed = round(microtime(true) - $start, 2);
$rps = $elapsed > 0 ? round($done / $elapsed) : 0;

echo "\n\n";
echo "-----------------------------------------------------------------" . PHP_EOL;
echo "  Done!" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;
echo "  Created : {$done}" . PHP_EOL;
if ($errors > 0) {
    echo "  Errors  : {$errors}" . PHP_EOL;
}
echo "  Time    : {$elapsed}s" . PHP_EOL;
echo "  Speed   : {$rps} users/second" . PHP_EOL;
echo "  Batch   : {$BATCH_SIZE} in-flight commands" . PHP_EOL;
echo "-----------------------------------------------------------------" . PHP_EOL;

if (!empty($errorLog)) {
    echo "\n  \033[33mFirst " . count($errorLog) . " errors:\033[0m\n";
    foreach ($errorLog as $e) {
        echo "    ↳ {$e}\n";
    }
}

echo "\n  \033[2mTo remove all generated users:\033[0m\n";
echo "  \033[36mphp examples/generate_hotspot_users.php --cleanup\033[0m\n\n";

$client->close();

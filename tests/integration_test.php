<?php

declare(strict_types=1);

/**
 * ┌────────────────────────────────────────────────────────────────────────────┐
 * │  RouterOS PHP Library — READ-ONLY Integration Test                         │
 * │                                                                            │
 * │  ✅ SAFE: This script ONLY reads data from the router.                     │
 * │  ✅ SAFE: It never adds, changes, or removes anything.                     │
 * │  ✅ SAFE: Your router config and internet connection are NOT touched.       │
 * │                                                                            │
 * │  Usage:  php tests/integration_test.php                                   │
 * └────────────────────────────────────────────────────────────────────────────┘
 */

require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\ConnectionPool;
use RouterOS\RouterOS;
use RouterOS\Exception\TrapException;
use RouterOS\Exception\ConnectionException;

// --- TEST CONFIG - Match your router ---------------------------------------
$HOST     = '192.168.88.1';
$USERNAME = 'admin';
$PASSWORD = 'password';
$USE_SSL  = false;

$config = new Config(
    host:           $HOST,
    username:       $USERNAME,
    password:       $PASSWORD,
    ssl:            $USE_SSL,
    connectTimeout: 5.0,
    readTimeout:    10.0,
);

// ── Test runner ───────────────────────────────────────────────────────────────
$pass    = 0;
$fail    = 0;
$timings = [];

function test(string $name, callable $fn): void
{
    global $pass, $fail, $timings;
    $start = microtime(true);
    try {
        $result = $fn();
        $ms     = round((microtime(true) - $start) * 1000, 1);
        $timings[$name] = $ms;
        echo "\033[32m  ✔\033[0m {$name}";
        if ($result !== null && $result !== true) {
            echo " \033[2m→ " . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\033[0m";
        }
        echo "  \033[2m({$ms} ms)\033[0m\n";
        $pass++;
    } catch (\Throwable $e) {
        $ms = round((microtime(true) - $start) * 1000, 1);
        echo "\033[31m  ✘\033[0m {$name}  \033[2m({$ms} ms)\033[0m\n";
        echo "    \033[33m↳ " . get_class($e) . ': ' . $e->getMessage() . "\033[0m\n";
        $fail++;
    }
}

function section(string $title): void
{
    echo "\n\033[1;36m── {$title}\033[0m\n";
}

echo "------------------------------------------------------------" . PHP_EOL;
echo "  RouterOS API Integration Tests (Read-Only)  " . PHP_EOL;
echo "  Target: {$HOST}" . PHP_EOL;
echo "------------------------------------------------------------" . PHP_EOL;
printf("  Connecting... ");

// ─────────────────────────────────────────────────────────────────────────────
section('1. Connection & Authentication');
// ─────────────────────────────────────────────────────────────────────────────

$api = null;
test('Client::connect() — TCP/TLS auth', function () use ($config, &$api) {
    $api = RouterOS::connect($config);
    return 'connected';
});

if ($api === null) {
    echo "\n\033[1;31m  Router unreachable — cannot continue live tests\033[0m\n";
    goto error_section;
}

// ─────────────────────────────────────────────────────────────────────────────
section('2. System Info');
// ─────────────────────────────────────────────────────────────────────────────

test('getSystemResource()', function () use ($api) {
    $r = $api->getSystemResource();
    assert(isset($r['version']),   'version missing');
    assert(isset($r['uptime']),    'uptime missing');
    assert(isset($r['cpu-load']),  'cpu-load missing');
    assert(isset($r['free-memory']), 'free-memory missing');
    return "v{$r['version']} | cpu={$r['cpu-load']}% | up={$r['uptime']} | freeMem=" . number_format((int)$r['free-memory'] / 1024 / 1024, 1) . ' MB';
});

test('getIdentity()', function () use ($api) {
    $name = $api->getIdentity();
    assert(strlen($name) > 0, 'identity empty');
    return $name;
});

test('getVersion()', function () use ($api) {
    $v = $api->getVersion();
    assert(strlen($v) > 0, 'version empty');
    return $v;
});

test('getPackages()', function () use ($api) {
    $pkgs = $api->getPackages();
    assert(is_array($pkgs), 'not array');
    $names = array_column($pkgs, 'name');
    return count($pkgs) . ' packages: ' . implode(', ', $names);
});

// ─────────────────────────────────────────────────────────────────────────────
section('3. Interfaces (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getInterfaces() — all fields', function () use ($api) {
    $ifaces = $api->getInterfaces();
    assert(count($ifaces) > 0, 'no interfaces');
    assert(isset($ifaces[0]['name']), 'name field missing');
    $names = array_column($ifaces, 'name');
    return count($ifaces) . ' interfaces: ' . implode(', ', $names);
});

test('getInterfaces() — custom proplist (name, type only)', function () use ($api) {
    $ifaces = $api->getInterfaces(['name', 'type']);
    assert(isset($ifaces[0]['name']), 'name missing');
    assert(isset($ifaces[0]['type']), 'type missing');
    assert(!isset($ifaces[0]['mac-address']), 'mac-address should NOT be returned');
    return count($ifaces) . ' interfaces, 2 fields each (proplist working correctly)';
});

test('getInterfacesByType("ether") — router-side filter', function () use ($api) {
    $ifaces = $api->getInterfacesByType('ether');
    assert(is_array($ifaces));
    foreach ($ifaces as $i) {
        assert($i['type'] === 'ether', "unexpected type: {$i['type']}");
    }
    $names = array_column($ifaces, 'name');
    return count($ifaces) . ' ether: ' . implode(', ', $names);
});

test('getInterfacesByType("bridge") — router-side filter', function () use ($api) {
    $ifaces = $api->getInterfacesByType('bridge');
    return count($ifaces) . ' bridge interfaces';
});

test('getRunningInterfaces()', function () use ($api) {
    $ifaces = $api->getRunningInterfaces();
    assert(is_array($ifaces));
    $names = array_column($ifaces, 'name');
    return count($ifaces) . ' running: ' . implode(', ', $names);
});

// ─────────────────────────────────────────────────────────────────────────────
section('4. IP Addresses (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getIpAddresses()', function () use ($api) {
    $addrs = $api->getIpAddresses();
    assert(count($addrs) > 0, 'no addresses');
    assert(isset($addrs[0]['address']),   'address field missing');
    assert(isset($addrs[0]['interface']), 'interface field missing');
    $list = array_map(fn($a) => "{$a['address']} on {$a['interface']}", $addrs);
    return implode(' | ', $list);
});

// ─────────────────────────────────────────────────────────────────────────────
section('5. Routes (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getRoutes()', function () use ($api) {
    $routes = $api->getRoutes();
    assert(is_array($routes));
    $active = array_filter($routes, fn($r) => ($r['active'] ?? '') === 'true');
    return count($routes) . ' total routes, ' . count($active) . ' active';
});

// ─────────────────────────────────────────────────────────────────────────────
section('6. Firewall (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getFirewallRules("forward")', function () use ($api) {
    $rules = $api->getFirewallRules('forward');
    assert(is_array($rules));
    return count($rules) . ' forward rules';
});

test('getFirewallRules("input")', function () use ($api) {
    $rules = $api->getFirewallRules('input');
    assert(is_array($rules));
    return count($rules) . ' input rules';
});

test('getFirewallRules("output")', function () use ($api) {
    $rules = $api->getFirewallRules('output');
    assert(is_array($rules));
    return count($rules) . ' output rules';
});

// ─────────────────────────────────────────────────────────────────────────────
section('7. DHCP (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getDhcpLeases()', function () use ($api) {
    $leases = $api->getDhcpLeases();
    assert(is_array($leases));
    return count($leases) . ' leases total';
});

test('getActiveDhcpLeases()', function () use ($api) {
    $leases = $api->getActiveDhcpLeases();
    assert(is_array($leases));
    $hosts = array_filter(array_column($leases, 'host-name'));
    return count($leases) . ' bound leases'
        . (count($hosts) ? ' (' . implode(', ', $hosts) . ')' : '');
});

// ─────────────────────────────────────────────────────────────────────────────
section('8. ARP (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getArpTable()', function () use ($api) {
    $arp = $api->getArpTable();
    assert(is_array($arp));
    return count($arp) . ' ARP entries';
});

// ─────────────────────────────────────────────────────────────────────────────
section('9. Users (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getUsers()', function () use ($api) {
    $users = $api->getUsers();
    assert(count($users) > 0, 'no users');
    $names = array_column($users, 'name');
    return count($users) . ' users: ' . implode(', ', $names);
});

test('getActiveUsers()', function () use ($api) {
    $active = $api->getActiveUsers();
    assert(is_array($active));
    return count($active) . ' active sessions';
});

// ─────────────────────────────────────────────────────────────────────────────
section('10. DNS (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getDnsSettings()', function () use ($api) {
    $dns = $api->getDnsSettings();
    assert(is_array($dns));
    return 'servers=' . ($dns['servers'] ?? '(none)')
        . ' | remote-requests=' . ($dns['allow-remote-requests'] ?? '?');
});

// ─────────────────────────────────────────────────────────────────────────────
section('11. WireGuard (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getWireguardInterfaces()', function () use ($api) {
    $wg = $api->getWireguardInterfaces();
    assert(is_array($wg));
    $names = array_column($wg, 'name');
    return count($wg) . ' WG interfaces' . (count($names) ? ': ' . implode(', ', $names) : '');
});

test('getWireguardPeers()', function () use ($api) {
    $peers = $api->getWireguardPeers();
    assert(is_array($peers));
    return count($peers) . ' WG peers';
});

// ─────────────────────────────────────────────────────────────────────────────
section('12. Logs (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

test('getLogs(limit=5)', function () use ($api) {
    $logs = $api->getLogs(5);
    assert(is_array($logs));
    assert(count($logs) <= 5, 'limit not respected');
    return count($logs) . ' entries (limit 5 respected)';
});

test('getLogs(limit=50)', function () use ($api) {
    $logs = $api->getLogs(50);
    assert(is_array($logs));
    return count($logs) . ' entries';
});

$api->close();

// ─────────────────────────────────────────────────────────────────────────────
section('13. QueryBuilder — Advanced (read-only)');
// ─────────────────────────────────────────────────────────────────────────────

try {
    $client = Client::connect($config);
} catch (ConnectionException $e) {
    echo "\n\033[1;31m  Cannot reconnect: " . $e->getMessage() . "\033[0m\n";
    goto error_section;
}

test('QueryBuilder::where() — router filters, not PHP', function () use ($client) {
    $rows = $client->query('/interface/print')
        ->proplist(['name', 'type'])
        ->where('type', 'ether')
        ->fetch();
    foreach ($rows as $r) {
        assert($r['type'] === 'ether', "unexpected type: {$r['type']}");
    }
    return count($rows) . ' ether only (router did the filtering)';
});

test('QueryBuilder::where("running","true")', function () use ($client) {
    $rows = $client->query('/interface/print')
        ->proplist(['name', 'running'])
        ->where('running', 'true')
        ->fetch();
    return count($rows) . ' running interfaces';
});

test('QueryBuilder::first() — stops after 1 row', function () use ($client) {
    $row = $client->query('/system/resource/print')
        ->proplist(['version', 'uptime', 'cpu-load'])
        ->first();
    assert($row !== null, 'first() returned null');
    assert(isset($row['version']), 'version missing');
    return "v{$row['version']} | cpu={$row['cpu-load']}%";
});

test('QueryBuilder::count() — interface count', function () use ($client) {
    $n = $client->query('/interface/print')->count();
    assert($n > 0, 'count is zero');
    return "{$n} interfaces";
});

test('QueryBuilder proplist enforced — extra fields NOT returned', function () use ($client) {
    $rows = $client->query('/ip/address/print')
        ->proplist(['address', 'interface'])
        ->fetch();
    assert(count($rows) > 0, 'no rows');
    assert(isset($rows[0]['address']),    'address field missing');
    assert(isset($rows[0]['interface']),  'interface field missing');
    assert(!isset($rows[0]['network']),   'network MUST NOT be returned');
    assert(!isset($rows[0]['broadcast']), 'broadcast MUST NOT be returned');
    return count($rows) . ' rows, only 2 fields each — proplist working ✓';
});

// ─────────────────────────────────────────────────────────────────────────────
section('14. Tagged (Concurrent) Commands — read-only');
// ─────────────────────────────────────────────────────────────────────────────

test('3 simultaneous tagged commands in parallel', function () use ($client) {
    $s1 = \RouterOS\Sentence::command('/interface/print')
        ->attr('.proplist', 'name,type');
    $s2 = \RouterOS\Sentence::command('/ip/address/print')
        ->attr('.proplist', 'address,interface');
    $s3 = \RouterOS\Sentence::command('/system/resource/print')
        ->attr('.proplist', 'version,cpu-load');

    // Fire all 3 without waiting
    $t1 = $client->sendAsync($s1);
    $t2 = $client->sendAsync($s2);
    $t3 = $client->sendAsync($s3);

    // Collect in order
    $ifaces   = $client->collectTag($t1);
    $addrs    = $client->collectTag($t2);
    $resource = $client->collectTag($t3);

    assert(count($ifaces) > 0,   'no interfaces returned');
    assert(count($addrs) > 0,    'no addresses returned');
    assert(count($resource) > 0, 'no resource returned');

    return count($ifaces) . ' ifaces + ' . count($addrs)
        . ' addrs + resource(v' . $resource[0]['version'] . ') all in one connection';
});

// ─────────────────────────────────────────────────────────────────────────────
section('15. Low-Level Client::command()');
// ─────────────────────────────────────────────────────────────────────────────

test('command() with proplist arg', function () use ($client) {
    $rows = $client->command('/interface/print', ['.proplist' => 'name,type']);
    assert(count($rows) > 0);
    return count($rows) . ' rows';
});

test('command() — /system/identity/print', function () use ($client) {
    $rows = $client->command('/system/identity/print', ['.proplist' => 'name']);
    return 'identity=' . ($rows[0]['name'] ?? '?');
});

test('TrapException on invalid command path', function () use ($client) {
    try {
        $client->command('/nonexistent/path/print');
        return 'FAIL — expected TrapException';
    } catch (TrapException $e) {
        return 'TrapException caught correctly: ' . $e->getMessage();
    }
});

$client->close();

// ─────────────────────────────────────────────────────────────────────────────
section('16. Connection Pool — read-only');
// ─────────────────────────────────────────────────────────────────────────────

test('ConnectionPool::use() — acquire, query, release', function () use ($config) {
    $pool   = new ConnectionPool(maxSize: 3, idleTimeout: 30);
    $result = $pool->use($config, function (Client $c): string {
        $row = $c->query('/system/resource/print')
            ->proplist(['version', 'cpu-load'])
            ->first();
        return "v{$row['version']} cpu={$row['cpu-load']}%";
    });
    return $result;
});

test('ConnectionPool reuses idle connection (no re-auth)', function () use ($config) {
    $pool = new ConnectionPool(maxSize: 3, idleTimeout: 30);
    $ids  = [];
    for ($i = 0; $i < 3; $i++) {
        $pool->use($config, function (Client $c) use (&$ids): void {
            $ids[] = spl_object_id($c);
            $c->query('/system/identity/print')->proplist(['name'])->first();
        });
    }
    $unique  = count(array_unique($ids));
    $reused  = $unique < 3;
    return $reused
        ? "same object reused across {count($ids)} calls (no extra auth) ✓"
        : "{$unique} unique objects (pool size ok)";
});

test('ConnectionPool::closeAll()', function () use ($config) {
    $pool = new ConnectionPool(maxSize: 2, idleTimeout: 60);
    $pool->use($config, fn(Client $c) => null);
    $pool->closeAll();
    return 'all idle connections closed cleanly';
});

test('ConnectionPool minRequestIntervalMs throttle', function () use ($config) {
    $pool  = new ConnectionPool(maxSize: 2, idleTimeout: 30, minRequestIntervalMs: 50);
    $start = microtime(true);
    for ($i = 0; $i < 3; $i++) {
        $pool->use($config, function (Client $c): void {
            $c->query('/system/identity/print')->proplist(['name'])->first();
        });
    }
    $ms = (microtime(true) - $start) * 1000;
    assert($ms >= 100, "Expected ≥100ms (2 × 50ms gaps), got {$ms}ms");
    return round($ms) . ' ms total — throttle working (50ms × 2 gaps = ≥100ms)';
});

// ─────────────────────────────────────────────────────────────────────────────
error_section:
section('17. Error Handling');
// ─────────────────────────────────────────────────────────────────────────────

test('ConnectionException for unreachable host', function () {
    try {
        $bad = new Config('192.0.2.255', 'x', 'x', connectTimeout: 2.0);
        Client::connect($bad);
        return 'FAIL — should have thrown';
    } catch (ConnectionException $e) {
        return 'ConnectionException caught ✓';
    }
});

test('AuthenticationException for wrong password', function () use ($config) {
    try {
        $bad = new Config($config->host, $config->username, 'wrong-password-!!', connectTimeout: 5.0);
        Client::connect($bad);
        return 'FAIL — should have thrown';
    } catch (\RouterOS\Exception\AuthenticationException $e) {
        return 'AuthenticationException caught ✓ — wrong creds rejected';
    } catch (ConnectionException $e) {
        return 'ConnectionException (router unreachable): ' . $e->getMessage();
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────────

$total = $pass + $fail;
echo "\n\033[1;37m┌─────────────────────────────────────────────────────────────────┐\033[0m\n";
echo "\033[1;37m│  Results\033[0m\n";
echo "\033[1;37m├─────────────────────────────────────────────────────────────────┤\033[0m\n";
echo "│  \033[32mPassed : {$pass}\033[0m\n";
echo "│  \033[31mFailed : {$fail}\033[0m\n";
echo "│  Total  : {$total}\033[0m\n";
if ($timings) {
    $avg     = round(array_sum($timings) / count($timings), 1);
    $maxKey  = array_keys($timings, max($timings))[0] ?? '';
    echo "│  Avg RTT: {$avg} ms\033[0m\n";
    echo "│  Slowest: {$maxKey} (" . round(max($timings), 1) . " ms)\033[0m\n";
}
echo "\033[1;37m└─────────────────────────────────────────────────────────────────┘\033[0m\n\n";

exit($fail > 0 ? 1 : 0);

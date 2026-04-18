<?php

declare(strict_types=1);

/**
 * RouterOS API — integration demo.
 * Edit the CONFIG block below, then run:
 *   php examples/demo.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

use RouterOS\Client;
use RouterOS\Config;
use RouterOS\ConnectionPool;
use RouterOS\RouterOS;
use RouterOS\Sentence;
use RouterOS\Exception\AuthenticationException;
use RouterOS\Exception\ConnectionException;
use RouterOS\Exception\TrapException;

// --- CONFIG - Match your router --------------------------------------------
$HOST = '192.168.88.1';
$USERNAME = 'admin';
$PASSWORD = 'password';
$USE_SSL = false;   // change to true once you enable /ip service api-ssl on the router

$config = new Config(
    host: $HOST,
    username: $USERNAME,
    password: $PASSWORD,
    ssl: $USE_SSL,
    connectTimeout: 5.0,
    readTimeout: 10.0,
    // ── Enable these once you have API-SSL configured on the router ──────────
    // ssl:           true,
    // sslVerifyPeer: true,
    // sslCaFile:     __DIR__ . '/../certs/router-ca.crt',
);

echo "------------------------------------------------------------" . PHP_EOL;
echo "  RouterOS API Client Demo  ->  {$HOST}" . PHP_EOL;
echo "------------------------------------------------------------" . PHP_EOL . PHP_EOL;

// ──────────────────────────────────────────────────────────────────────────────
//  ① System info + interfaces + addresses
// ──────────────────────────────────────────────────────────────────────────────
echo '[ 1 ] System Info' . PHP_EOL;
try {
    $api = RouterOS::connect($config);

    // System resource
    $res = $api->getSystemResource();
    printf("  Identity : %s\n", $api->getIdentity());
    printf("  Version  : %s\n", $res['version'] ?? '-');
    printf("  Uptime   : %s\n", $res['uptime'] ?? '-');
    printf("  CPU load : %s%%\n", $res['cpu-load'] ?? '-');
    printf("  Free mem : %s\n", $res['free-memory'] ?? '-');
    echo PHP_EOL;

    // Ethernet interfaces
    echo '[ 2 ] Ethernet Interfaces' . PHP_EOL;
    foreach ($api->getInterfacesByType('ether') as $iface) {
        printf(
            "  %-20s running=%-5s mac=%s\n",
            $iface['name'] ?? '?',
            $iface['running'] ?? '?',
            $iface['mac-address'] ?? '?',
        );
    }
    echo PHP_EOL;

    // IP addresses
    echo '[ 3 ] IP Addresses' . PHP_EOL;
    foreach ($api->getIpAddresses() as $addr) {
        printf("  %-22s on %s\n", $addr['address'] ?? '?', $addr['interface'] ?? '?');
    }
    echo PHP_EOL;

    // Fluent query builder
    echo '[ 4 ] Query Builder — running interfaces (proplist)' . PHP_EOL;
    $rows = $api->client()
        ->query('/interface/print')
        ->proplist(['name', 'type', 'running'])
        ->where('running', 'true')
        ->fetch();
    foreach ($rows as $row) {
        printf("  [%s] %s\n", $row['type'] ?? '?', $row['name'] ?? '?');
    }
    echo PHP_EOL;

    // DHCP leases (first 5)
    echo '[ 5 ] DHCP Leases (first 5)' . PHP_EOL;
    $leases = $api->getDhcpLeases();
    if (empty($leases)) {
        echo "  (none / DHCP server not configured)\n";
    }
    foreach (array_slice($leases, 0, 5) as $lease) {
        printf(
            "  %-17s → %-15s  %s\n",
            $lease['mac-address'] ?? '?',
            $lease['address'] ?? '?',
            $lease['host-name'] ?? '',
        );
    }
    echo PHP_EOL;

    $api->close();

} catch (AuthenticationException $e) {
    echo '  [AUTH ERROR] ' . $e->getMessage() . PHP_EOL . PHP_EOL;
} catch (ConnectionException $e) {
    echo '  [CONNECT ERROR] ' . $e->getMessage() . PHP_EOL . PHP_EOL;
} catch (TrapException $e) {
    echo "  [TRAP cat={$e->getTrapCategory()}] " . $e->getMessage() . PHP_EOL . PHP_EOL;
}

// ──────────────────────────────────────────────────────────────────────────────
//  ② Simultaneous (tagged) commands
// ──────────────────────────────────────────────────────────────────────────────
echo '[ 6 ] Simultaneous (tagged) Commands' . PHP_EOL;
try {
    $client = Client::connect($config);

    $tag1 = $client->sendAsync(Sentence::command('/interface/print'));
    $tag2 = $client->sendAsync(
        Sentence::command('/ip/address/print')->attr('.proplist', 'address,interface')
    );

    $interfaces = $client->collectTag($tag1);
    $addresses = $client->collectTag($tag2);

    echo "  Interfaces returned : " . count($interfaces) . PHP_EOL;
    echo "  Addresses returned  : " . count($addresses) . PHP_EOL;
    $client->close();
} catch (\Throwable $e) {
    echo '  [ERROR] ' . $e->getMessage() . PHP_EOL;
}
echo PHP_EOL;

// ──────────────────────────────────────────────────────────────────────────────
//  ③ Streaming listener (waits for up to 3 interface-change events then exits)
// ──────────────────────────────────────────────────────────────────────────────
echo '[ 7 ] Interface Change Listener  (waiting for up to 3 events…)' . PHP_EOL;
try {
    $client = Client::connect($config);
    $count = 0;

    $client->listen('/interface/listen', function (array $row) use (&$count): bool {
        printf(
            "  Change event: %-20s dead=%s\n",
            $row['name'] ?? '?',
            $row['.dead'] ?? 'no',
        );
        return ++$count < 3; // false → cancel & exit
    });

    echo "  Listener finished (received {$count} event(s)).\n";
    $client->close();
} catch (ConnectionException $e) {
    echo '  [LISTEN ERROR] ' . $e->getMessage() . PHP_EOL;
}
echo PHP_EOL;

// ──────────────────────────────────────────────────────────────────────────────
//  ④ Connection pool
// ──────────────────────────────────────────────────────────────────────────────
echo '[ 8 ] Connection Pool' . PHP_EOL;
$pool = new ConnectionPool(maxSize: 3, idleTimeout: 30);

try {
    $result = $pool->use($config, fn(Client $c) => $c->command('/system/resource/print'));
    echo "  Router {$HOST}: version=" . ($result[0]['version'] ?? '?') . PHP_EOL;
} catch (\Throwable $e) {
    echo "  Pool error: " . $e->getMessage() . PHP_EOL;
}

$pool->closeAll();
echo PHP_EOL;

// ------------------------------------------------------------
echo "------------------------------------------------------------" . PHP_EOL;
echo "  All tests finished successfully." . PHP_EOL;
echo "------------------------------------------------------------" . PHP_EOL;

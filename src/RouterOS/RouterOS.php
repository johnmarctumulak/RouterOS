<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\ConnectionException;
use RouterOS\Exception\TrapException;

/**
 * High-level RouterOS API facade — engineered for MINIMAL router load.
 *
 * Every read method sends a strict `.proplist` so the router only serializes
 * and transmits the fields you actually need. Without `.proplist`, RouterOS
 * serializes EVERY property (including slow ones like file contents and
 * performance counters), wasting both router CPU and network bandwidth.
 *
 * RouterOS API docs warning:
 *   "The omission of .proplist may have a high-performance penalty if the
 *    'detail' argument is set."
 *
 * Design rules used here:
 *   1. Always send .proplist — router only computes the fields you request
 *   2. Always send query words (?) — router filters rows, not PHP
 *   3. Reuse one connection per session — no repeated TCP + auth overhead
 *   4. Use ->first() for singleton commands — stops reading after 1 row
 */
final class RouterOS
{
    private Client $client;

    private function __construct(Client $client)
    {
        $this->client = $client;
    }

    // --------------------------------------------------------------------------
    // Factory
    // --------------------------------------------------------------------------

    /**
     * Connect and authenticate to a router.
     *
     * @throws ConnectionException
     * @throws \RouterOS\Exception\AuthenticationException
     */
    public static function connect(Config $config): self
    {
        return new self(Client::connect($config));
    }

    public function close(): void
    {
        $this->client->close();
    }

    /**
     * Direct access to the underlying Client for custom queries.
     */
    public function client(): Client
    {
        return $this->client;
    }

    // --------------------------------------------------------------------------
    // SYSTEM - tight proplists: only common monitoring fields
    // --------------------------------------------------------------------------

    /**
     * Returns system resource counters.
     * Proplist: only the 8 most-used fields — avoids slow counters.
     *
     * @return array<string, string>
     */
    public function getSystemResource(): array
    {
        return $this->client->query('/system/resource/print')
            ->proplist([
                'uptime', 'version', 'build-time',
                'cpu-load', 'cpu-count', 'cpu-frequency',
                'free-memory', 'total-memory',
                'free-hdd-space', 'total-hdd-space',
                'architecture-name', 'board-name',
            ])
            ->first() ?? [];
    }

    /**
     * Returns router identity (name).
     * Proplist: only 'name' — the router doesn't send anything else anyway,
     * but being explicit protects against future field additions.
     */
    public function getIdentity(): string
    {
        $row = $this->client->query('/system/identity/print')
            ->proplist(['name'])
            ->first();
        return $row['name'] ?? '';
    }

    /**
     * Sets the router identity / hostname.
     */
    public function setIdentity(string $name): void
    {
        $this->client->execute('/system/identity/set', ['name' => $name]);
    }

    /**
     * Returns installed packages — only name + version + disabled state.
     *
     * @return array<int, array<string, string>>
     */
    public function getPackages(): array
    {
        return $this->client->query('/system/package/getall')
            ->proplist(['.id', 'name', 'version', 'disabled', 'scheduled'])
            ->fetch();
    }

    /**
     * Returns current RouterOS version string.
     * Uses cached system resource if already called this session.
     */
    public function getVersion(): string
    {
        return $this->getSystemResource()['version'] ?? '';
    }

    /**
     * Reboots the router. Use with extreme caution.
     */
    public function reboot(): void
    {
        $this->client->execute('/system/reboot');
    }

    // --------------------------------------------------------------------------
    // INTERFACES - only operational fields by default
    // --------------------------------------------------------------------------

    /**
     * Returns all interfaces with only the most common fields.
     *
     * Without proplist, RouterOS sends 20+ fields per interface including
     * slow ones — this sends only 8 essential fields.
     *
     * @param string[]|null $proplist  Override fields if you need more/fewer
     * @return array<int, array<string, string>>
     */
    public function getInterfaces(?array $proplist = null): array
    {
        return $this->client->query('/interface/print')
            ->proplist($proplist ?? [
                '.id', 'name', 'type', 'mtu',
                'running', 'disabled', 'comment', 'mac-address',
            ])
            ->fetch();
    }

    /**
     * Returns interfaces of a specific type.
     * Query word `?type=X` runs on the router — it filters before sending.
     *
     * @return array<int, array<string, string>>
     */
    public function getInterfacesByType(string $type): array
    {
        return $this->client->query('/interface/print')
            ->proplist(['.id', 'name', 'type', 'running', 'disabled', 'mac-address'])
            ->where('type', $type)
            ->fetch();
    }

    /**
     * Returns only running interfaces.
     *
     * @return array<int, array<string, string>>
     */
    public function getRunningInterfaces(): array
    {
        return $this->client->query('/interface/print')
            ->proplist(['.id', 'name', 'type', 'mac-address'])
            ->where('running', 'true')
            ->fetch();
    }

    /**
     * Enables or disables an interface.
     */
    public function setInterfaceDisabled(string $id, bool $disabled): void
    {
        $this->client->execute('/interface/set', [
            '.id'      => $id,
            'disabled' => $disabled ? 'yes' : 'no',
        ]);
    }

    // --------------------------------------------------------------------------
    // IP ADDRESSES
    // --------------------------------------------------------------------------

    /**
     * Returns all IP addresses. Only 5 fields — router omits the rest.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpAddresses(): array
    {
        return $this->client->query('/ip/address/print')
            ->proplist(['.id', 'address', 'network', 'interface', 'disabled'])
            ->fetch();
    }

    /**
     * Adds an IP address to an interface.
     */
    public function addIpAddress(string $address, string $interface, string $comment = ''): void
    {
        $args = ['address' => $address, 'interface' => $interface];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/ip/address/add', $args);
    }

    /**
     * Removes an IP address by .id.
     */
    public function removeIpAddress(string $id): void
    {
        $this->client->execute('/ip/address/remove', ['.id' => $id]);
    }

    // --------------------------------------------------------------------------
    // ROUTING
    // --------------------------------------------------------------------------

    /**
     * Returns IP routes. Without proplist every route sends ~15 fields.
     * This sends only 6.
     *
     * @return array<int, array<string, string>>
     */
    public function getRoutes(): array
    {
        return $this->client->query('/ip/route/print')
            ->proplist(['.id', 'dst-address', 'gateway', 'distance', 'active', 'comment'])
            ->fetch();
    }

    /**
     * Adds a static route.
     *
     * @param array<string, string> $extra
     */
    public function addRoute(string $dstAddress, string $gateway, array $extra = []): void
    {
        $this->client->execute('/ip/route/add', array_merge([
            'dst-address' => $dstAddress,
            'gateway'     => $gateway,
        ], $extra));
    }

    // ══════════════════════════════════════════════════════════════════════════
    //  FIREWALL
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Returns firewall filter rules for a chain.
     * Uses router-side `?chain=X` filter — router doesn't send other chains.
     * Proplist cuts fields from ~25 to 9.
     *
     * @return array<int, array<string, string>>
     */
    public function getFirewallRules(string $chain = 'forward'): array
    {
        return $this->client->query('/ip/firewall/filter/print')
            ->proplist([
                '.id', 'chain', 'action', 'src-address', 'dst-address',
                'protocol', 'dst-port', 'disabled', 'comment',
            ])
            ->where('chain', $chain)
            ->fetch();
    }

    /**
     * Adds a firewall filter rule.
     *
     * @param array<string, string> $extra
     */
    public function addFirewallRule(string $chain, string $action, array $extra = []): void
    {
        $this->client->execute('/ip/firewall/filter/add', array_merge([
            'chain'  => $chain,
            'action' => $action,
        ], $extra));
    }

    /**
     * Removes a firewall rule by .id.
     */
    public function removeFirewallRule(string $id): void
    {
        $this->client->execute('/ip/firewall/filter/remove', ['.id' => $id]);
    }

    // --------------------------------------------------------------------------
    // DHCP
    // --------------------------------------------------------------------------

    /**
     * Returns DHCP server leases. Proplist reduces fields from ~15 to 6.
     *
     * @return array<int, array<string, string>>
     */
    public function getDhcpLeases(): array
    {
        return $this->client->query('/ip/dhcp-server/lease/print')
            ->proplist([
                '.id', 'mac-address', 'address',
                'host-name', 'status', 'expires-after',
            ])
            ->fetch();
    }

    /**
     * Returns only active DHCP leases (router filters, not PHP).
     *
     * @return array<int, array<string, string>>
     */
    public function getActiveDhcpLeases(): array
    {
        return $this->client->query('/ip/dhcp-server/lease/print')
            ->proplist(['.id', 'mac-address', 'address', 'host-name', 'expires-after'])
            ->where('status', 'bound')
            ->fetch();
    }

    /**
     * Adds a static DHCP lease.
     */
    public function addStaticLease(string $macAddress, string $ipAddress, string $comment = ''): void
    {
        $args = ['mac-address' => $macAddress, 'address' => $ipAddress];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/ip/dhcp-server/lease/add', $args);
    }

    // --------------------------------------------------------------------------
    // ARP
    // --------------------------------------------------------------------------

    /**
     * Returns the ARP table. Only 4 fields vs the router's default 7+.
     *
     * @return array<int, array<string, string>>
     */
    public function getArpTable(): array
    {
        return $this->client->query('/ip/arp/print')
            ->proplist(['.id', 'address', 'mac-address', 'interface'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // USERS
    // --------------------------------------------------------------------------

    /**
     * Returns configured users. Proplist: 4 fields instead of all 8.
     *
     * @return array<int, array<string, string>>
     */
    public function getUsers(): array
    {
        return $this->client->query('/user/print')
            ->proplist(['.id', 'name', 'group', 'disabled'])
            ->fetch();
    }

    /**
     * Returns active sessions — router-side, always a small list.
     *
     * @return array<int, array<string, string>>
     */
    public function getActiveUsers(): array
    {
        return $this->client->query('/user/active/print')
            ->proplist(['.id', 'name', 'address', 'via', 'when'])
            ->fetch();
    }

    /**
     * Adds a user.
     */
    public function addUser(string $name, string $password, string $group = 'read'): void
    {
        $this->client->execute('/user/add', [
            'name'     => $name,
            'password' => $password,
            'group'    => $group,
        ]);
    }

    /**
     * Removes a user by .id or name.
     */
    public function removeUser(string $id): void
    {
        $this->client->execute('/user/remove', ['.id' => $id]);
    }

    // --------------------------------------------------------------------------
    // DNS
    // --------------------------------------------------------------------------

    /**
     * Returns DNS configuration. Only 3 key fields.
     *
     * @return array<string, string>
     */
    public function getDnsSettings(): array
    {
        return $this->client->query('/ip/dns/print')
            ->proplist(['servers', 'dynamic-servers', 'allow-remote-requests'])
            ->first() ?? [];
    }

    /**
     * Sets DNS servers.
     *
     * @param string[] $servers  e.g. ['1.1.1.1', '8.8.8.8']
     */
    public function setDnsServers(array $servers): void
    {
        $this->client->execute('/ip/dns/set', [
            'servers' => implode(',', $servers),
        ]);
    }

    // --------------------------------------------------------------------------
    // WireGuard (RouterOS 7+)
    // --------------------------------------------------------------------------

    /**
     * Returns WireGuard interfaces. Only 5 key fields.
     *
     * @return array<int, array<string, string>>
     */
    public function getWireguardInterfaces(): array
    {
        return $this->client->query('/interface/wireguard/print')
            ->proplist(['.id', 'name', 'listen-port', 'public-key', 'running'])
            ->fetch();
    }

    /**
     * Returns WireGuard peers. Only 5 key fields.
     *
     * @return array<int, array<string, string>>
     */
    public function getWireguardPeers(): array
    {
        return $this->client->query('/interface/wireguard/peers/print')
            ->proplist([
                '.id', 'interface', 'public-key',
                'allowed-address', 'endpoint-address', 'current-endpoint-address',
            ])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // LOGGING
    // --------------------------------------------------------------------------

    /**
     * Returns the router log.
     *
     * Note: RouterOS sends log entries oldest-first, slice from the end
     * to get the most recent. Proplist limits to 3 fields.
     *
     * @return array<int, array<string, string>>
     */
    public function getLogs(int $limit = 50): array
    {
        $rows = $this->client->query('/log/print')
            ->proplist(['time', 'topics', 'message'])
            ->fetch();

        // Return the last $limit entries (most recent)
        return array_slice($rows, -$limit);
    }

    // --------------------------------------------------------------------------
    // PPP / PPPOE
    // --------------------------------------------------------------------------

    /**
     * Returns PPP secrets (PPPoE/L2TP/PPTP user accounts).
     *
     * @return array<int, array<string, string>>
     */
    public function getPppSecrets(): array
    {
        return $this->client->query('/ppp/secret/print')
            ->proplist(['.id', 'name', 'service', 'profile', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Adds a PPP secret (PPPoE/L2TP/PPTP account).
     *
     * @param string $service  pppoe | l2tp | pptp | any
     */
    public function addPppSecret(
        string $name,
        string $password,
        string $service = 'pppoe',
        string $profile = 'default',
        string $comment = ''
    ): void {
        $args = [
            'name'     => $name,
            'password' => $password,
            'service'  => $service,
            'profile'  => $profile,
        ];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/ppp/secret/add', $args);
    }

    /**
     * Removes a PPP secret by .id or name.
     */
    public function removePppSecret(string $id): void
    {
        $this->client->execute('/ppp/secret/remove', ['.id' => $id]);
    }

    /**
     * Returns currently active PPP sessions.
     *
     * @return array<int, array<string, string>>
     */
    public function getPppActive(): array
    {
        return $this->client->query('/ppp/active/print')
            ->proplist(['.id', 'name', 'service', 'address', 'uptime', 'caller-id'])
            ->fetch();
    }

    /**
     * Returns PPP profiles.
     *
     * @return array<int, array<string, string>>
     */
    public function getPppProfiles(): array
    {
        return $this->client->query('/ppp/profile/print')
            ->proplist(['.id', 'name', 'local-address', 'remote-address', 'rate-limit', 'dns-server'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // HOTSPOT
    // --------------------------------------------------------------------------

    /**
     * Returns hotspot servers.
     *
     * @return array<int, array<string, string>>
     */
    public function getHotspotServers(): array
    {
        return $this->client->query('/ip/hotspot/print')
            ->proplist(['.id', 'name', 'interface', 'address-pool', 'profile', 'disabled'])
            ->fetch();
    }

    /**
     * Returns hotspot user accounts.
     *
     * @return array<int, array<string, string>>
     */
    public function getHotspotUsers(): array
    {
        return $this->client->query('/ip/hotspot/user/print')
            ->proplist(['.id', 'name', 'profile', 'limit-uptime', 'limit-bytes-total', 'comment', 'disabled'])
            ->fetch();
    }

    /**
     * Adds a hotspot user.
     */
    public function addHotspotUser(
        string $name,
        string $password,
        string $profile = 'default',
        string $comment = ''
    ): void {
        $args = ['name' => $name, 'password' => $password, 'profile' => $profile];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/ip/hotspot/user/add', $args);
    }

    /**
     * Removes a hotspot user by .id or name.
     */
    public function removeHotspotUser(string $id): void
    {
        $this->client->execute('/ip/hotspot/user/remove', ['.id' => $id]);
    }

    /**
     * Returns currently active hotspot sessions.
     *
     * @return array<int, array<string, string>>
     */
    public function getHotspotActive(): array
    {
        return $this->client->query('/ip/hotspot/active/print')
            ->proplist(['.id', 'user', 'address', 'mac-address', 'uptime', 'bytes-in', 'bytes-out'])
            ->fetch();
    }

    /**
     * Returns hotspot user profiles.
     *
     * @return array<int, array<string, string>>
     */
    public function getHotspotProfiles(): array
    {
        return $this->client->query('/ip/hotspot/user/profile/print')
            ->proplist(['.id', 'name', 'rate-limit', 'session-timeout', 'shared-users'])
            ->fetch();
    }

    /**
     * Returns hotspot IP bindings (MAC → IP address locks).
     *
     * @return array<int, array<string, string>>
     */
    public function getHotspotIpBindings(): array
    {
        return $this->client->query('/ip/hotspot/ip-binding/print')
            ->proplist(['.id', 'mac-address', 'address', 'type', 'comment'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // QUEUES
    // --------------------------------------------------------------------------

    /**
     * Returns simple queues (per-client bandwidth limits).
     *
     * @return array<int, array<string, string>>
     */
    public function getSimpleQueues(): array
    {
        return $this->client->query('/queue/simple/print')
            ->proplist(['.id', 'name', 'target', 'max-limit', 'burst-limit', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Adds a simple queue.
     *
     * @param string $maxLimit  upload/download e.g. "10M/20M"
     */
    public function addSimpleQueue(
        string $name,
        string $target,
        string $maxLimit = '10M/10M',
        string $comment = ''
    ): void {
        $args = ['name' => $name, 'target' => $target, 'max-limit' => $maxLimit];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/queue/simple/add', $args);
    }

    /**
     * Removes a simple queue by .id.
     */
    public function removeSimpleQueue(string $id): void
    {
        $this->client->execute('/queue/simple/remove', ['.id' => $id]);
    }

    /**
     * Returns queue tree entries.
     *
     * @return array<int, array<string, string>>
     */
    public function getQueueTree(): array
    {
        return $this->client->query('/queue/tree/print')
            ->proplist(['.id', 'name', 'parent', 'packet-mark', 'max-limit', 'priority', 'disabled'])
            ->fetch();
    }

    /**
     * Returns queue types.
     *
     * @return array<int, array<string, string>>
     */
    public function getQueueTypes(): array
    {
        return $this->client->query('/queue/type/print')
            ->proplist(['.id', 'name', 'kind'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // IP POOL
    // --------------------------------------------------------------------------

    /**
     * Returns IP pools.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpPools(): array
    {
        return $this->client->query('/ip/pool/print')
            ->proplist(['.id', 'name', 'ranges', 'next-pool'])
            ->fetch();
    }

    /**
     * Adds an IP pool.
     */
    public function addIpPool(string $name, string $ranges, string $nextPool = ''): void
    {
        $args = ['name' => $name, 'ranges' => $ranges];
        if ($nextPool !== '') {
            $args['next-pool'] = $nextPool;
        }
        $this->client->execute('/ip/pool/add', $args);
    }

    /**
     * Returns IP pool usage (which IPs are used and by whom).
     *
     * @return array<int, array<string, string>>
     */
    public function getIpPoolUsed(): array
    {
        return $this->client->query('/ip/pool/used/print')
            ->proplist(['.id', 'pool', 'address', 'info'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // BRIDGE
    // --------------------------------------------------------------------------

    /**
     * Returns bridges.
     *
     * @return array<int, array<string, string>>
     */
    public function getBridges(): array
    {
        return $this->client->query('/interface/bridge/print')
            ->proplist(['.id', 'name', 'mtu', 'vlan-filtering', 'running', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Returns bridge ports.
     *
     * @return array<int, array<string, string>>
     */
    public function getBridgePorts(): array
    {
        return $this->client->query('/interface/bridge/port/print')
            ->proplist(['.id', 'interface', 'bridge', 'pvid', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Returns bridge VLAN table entries.
     *
     * @return array<int, array<string, string>>
     */
    public function getBridgeVlans(): array
    {
        return $this->client->query('/interface/bridge/vlan/print')
            ->proplist(['.id', 'bridge', 'vlan-ids', 'tagged', 'untagged'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // VLAN
    // --------------------------------------------------------------------------

    /**
     * Returns VLAN interfaces.
     *
     * @return array<int, array<string, string>>
     */
    public function getVlans(): array
    {
        return $this->client->query('/interface/vlan/print')
            ->proplist(['.id', 'name', 'vlan-id', 'interface', 'mtu', 'running', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Adds a VLAN interface.
     */
    public function addVlan(string $name, int $vlanId, string $interface, string $comment = ''): void
    {
        $args = ['name' => $name, 'vlan-id' => (string) $vlanId, 'interface' => $interface];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/interface/vlan/add', $args);
    }

    // --------------------------------------------------------------------------
    // IPSEC
    // --------------------------------------------------------------------------

    /**
     * Returns IPsec peers.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpsecPeers(): array
    {
        return $this->client->query('/ip/ipsec/peer/print')
            ->proplist(['.id', 'name', 'address', 'profile', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Returns active IPsec connections.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpsecActive(): array
    {
        return $this->client->query('/ip/ipsec/active-peers/print')
            ->proplist(['.id', 'remote-address', 'local-address', 'state', 'uptime', 'rx-bytes', 'tx-bytes'])
            ->fetch();
    }

    /**
     * Returns IPsec policies.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpsecPolicies(): array
    {
        return $this->client->query('/ip/ipsec/policy/print')
            ->proplist(['.id', 'src-address', 'dst-address', 'action', 'disabled', 'comment'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // CERTIFICATES
    // --------------------------------------------------------------------------

    /**
     * Returns installed certificates.
     *
     * @return array<int, array<string, string>>
     */
    public function getCertificates(): array
    {
        return $this->client->query('/certificate/print')
            ->proplist(['.id', 'name', 'common-name', 'subject-alt-name', 'invalid-after', 'trusted', 'fingerprint'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // SCHEDULER
    // --------------------------------------------------------------------------

    /**
     * Returns scheduler jobs.
     *
     * @return array<int, array<string, string>>
     */
    public function getScheduler(): array
    {
        return $this->client->query('/system/scheduler/print')
            ->proplist(['.id', 'name', 'start-time', 'interval', 'on-event', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Adds a scheduler job.
     *
     * @param string $interval  e.g. "1h", "00:05:00"
     */
    public function addSchedulerJob(
        string $name,
        string $onEvent,
        string $startTime = 'startup',
        string $interval = '1h',
        string $comment = ''
    ): void {
        $args = [
            'name'       => $name,
            'on-event'   => $onEvent,
            'start-time' => $startTime,
            'interval'   => $interval,
        ];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/system/scheduler/add', $args);
    }

    // --------------------------------------------------------------------------
    // SCRIPTS
    // --------------------------------------------------------------------------

    /**
     * Returns saved scripts.
     *
     * @return array<int, array<string, string>>
     */
    public function getScripts(): array
    {
        return $this->client->query('/system/script/print')
            ->proplist(['.id', 'name', 'policy', 'last-started', 'run-count', 'comment'])
            ->fetch();
    }

    /**
     * Runs a script by name.
     */
    public function runScript(string $name): void
    {
        $this->client->execute('/system/script/run', ['number' => $name]);
    }

    // --------------------------------------------------------------------------
    // NETWATCH
    // --------------------------------------------------------------------------

    /**
     * Returns Netwatch hosts (uptime monitoring probes).
     *
     * @return array<int, array<string, string>>
     */
    public function getNetwatch(): array
    {
        return $this->client->query('/tool/netwatch/print')
            ->proplist(['.id', 'host', 'interval', 'timeout', 'status', 'since', 'comment'])
            ->fetch();
    }

    /**
     * Adds a Netwatch probe.
     *
     * @param string $interval  e.g. "00:00:10" (10 seconds)
     */
    public function addNetwatchHost(
        string $host,
        string $interval = '00:00:10',
        string $upScript = '',
        string $downScript = '',
        string $comment = ''
    ): void {
        $args = ['host' => $host, 'interval' => $interval];
        if ($upScript !== '')   { $args['up-script']   = $upScript; }
        if ($downScript !== '') { $args['down-script']  = $downScript; }
        if ($comment !== '')    { $args['comment']      = $comment; }
        $this->client->execute('/tool/netwatch/add', $args);
    }

    // --------------------------------------------------------------------------
    // RADIUS
    // --------------------------------------------------------------------------

    /**
     * Returns RADIUS server configurations.
     *
     * @return array<int, array<string, string>>
     */
    public function getRadiusServers(): array
    {
        return $this->client->query('/radius/print')
            ->proplist(['.id', 'service', 'address', 'port', 'timeout', 'disabled', 'comment'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // SNMP
    // --------------------------------------------------------------------------

    /**
     * Returns SNMP configuration.
     *
     * @return array<string, string>
     */
    public function getSnmpSettings(): array
    {
        return $this->client->query('/snmp/print')
            ->proplist(['enabled', 'contact', 'location', 'engine-id'])
            ->first() ?? [];
    }

    /**
     * Returns SNMP communities.
     *
     * @return array<int, array<string, string>>
     */
    public function getSnmpCommunities(): array
    {
        return $this->client->query('/snmp/community/print')
            ->proplist(['.id', 'name', 'addresses', 'security', 'read-access', 'write-access'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // NTP
    // --------------------------------------------------------------------------

    /**
     * Returns NTP client configuration and sync status.
     *
     * @return array<string, string>
     */
    public function getNtpClient(): array
    {
        return $this->client->query('/system/ntp/client/print')
            ->proplist(['enabled', 'mode', 'servers', 'status', 'synced-server', 'last-adjustment'])
            ->first() ?? [];
    }

    // --------------------------------------------------------------------------
    // CAPSMAN (Wireless AP Controller)
    // --------------------------------------------------------------------------

    /**
     * Returns CAPsMAN managed access points.
     *
     * @return array<int, array<string, string>>
     */
    public function getCapsmanAps(): array
    {
        return $this->client->query('/caps-man/remote-cap/print')
            ->proplist(['.id', 'name', 'address', 'board', 'version', 'state', 'radio-count'])
            ->fetch();
    }

    /**
     * Returns CAPsMAN wireless registrations (connected clients).
     *
     * @return array<int, array<string, string>>
     */
    public function getCapsmanRegistrations(): array
    {
        return $this->client->query('/caps-man/registration-table/print')
            ->proplist(['.id', 'mac-address', 'interface', 'ssid', 'rx-signal', 'uptime', 'bytes'])
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // FIREWALL - NAT & MANGLE
    // --------------------------------------------------------------------------

    /**
     * Returns NAT rules.
     *
     * @param string $chain  srcnat | dstnat
     * @return array<int, array<string, string>>
     */
    public function getNatRules(string $chain = 'srcnat'): array
    {
        return $this->client->query('/ip/firewall/nat/print')
            ->proplist([
                '.id', 'chain', 'action', 'src-address', 'dst-address',
                'protocol', 'dst-port', 'to-addresses', 'to-ports', 'disabled', 'comment',
            ])
            ->where('chain', $chain)
            ->fetch();
    }

    /**
     * Adds a NAT rule (e.g. masquerade for internet sharing).
     *
     * @param array<string, string> $extra
     */
    public function addNatRule(string $chain, string $action, array $extra = []): void
    {
        $this->client->execute('/ip/firewall/nat/add', array_merge([
            'chain'  => $chain,
            'action' => $action,
        ], $extra));
    }

    /**
     * Returns mangle rules.
     *
     * @return array<int, array<string, string>>
     */
    public function getMangleRules(): array
    {
        return $this->client->query('/ip/firewall/mangle/print')
            ->proplist([
                '.id', 'chain', 'action', 'new-packet-mark', 'passthrough',
                'src-address', 'dst-address', 'disabled', 'comment',
            ])
            ->fetch();
    }

    /**
     * Returns address lists.
     *
     * @return array<int, array<string, string>>
     */
    public function getAddressLists(): array
    {
        return $this->client->query('/ip/firewall/address-list/print')
            ->proplist(['.id', 'list', 'address', 'timeout', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Adds an address to a firewall address list.
     */
    public function addToAddressList(string $list, string $address, string $comment = ''): void
    {
        $args = ['list' => $list, 'address' => $address];
        if ($comment !== '') {
            $args['comment'] = $comment;
        }
        $this->client->execute('/ip/firewall/address-list/add', $args);
    }

    // --------------------------------------------------------------------------
    // TOOLS
    // --------------------------------------------------------------------------

    /**
     * Sends a ping from the router and returns results.
     *
     * @return array<int, array<string, string>>
     */
    public function ping(string $address, int $count = 4): array
    {
        return $this->client->command('/ping', [
            'address' => $address,
            'count'   => (string) $count,
        ]);
    }

    /**
     * Returns current traffic on an interface (one sample).
     * Uses /interface/monitor-traffic — runs once and stops.
     *
     * @return array<string, string>|null
     */
    public function getInterfaceTraffic(string $interface): ?array
    {
        return $this->client->query('/interface/monitor-traffic')
            ->attr('interface', $interface)
            ->attr('once', '')
            ->proplist(['name', 'rx-bits-per-second', 'tx-bits-per-second',
                        'rx-packets-per-second', 'tx-packets-per-second'])
            ->first();
    }

    // --------------------------------------------------------------------------
    // IPV6
    // --------------------------------------------------------------------------

    /**
     * Returns IPv6 addresses.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpv6Addresses(): array
    {
        return $this->client->query('/ipv6/address/print')
            ->proplist(['.id', 'address', 'interface', 'advertise', 'disabled', 'comment'])
            ->fetch();
    }

    /**
     * Returns IPv6 routes.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpv6Routes(): array
    {
        return $this->client->query('/ipv6/route/print')
            ->proplist(['.id', 'dst-address', 'gateway', 'distance', 'active', 'comment'])
            ->fetch();
    }

    /**
     * Returns IPv6 firewall filter rules.
     *
     * @return array<int, array<string, string>>
     */
    public function getIpv6FirewallRules(string $chain = 'forward'): array
    {
        return $this->client->query('/ipv6/firewall/filter/print')
            ->proplist(['.id', 'chain', 'action', 'src-address', 'dst-address', 'disabled', 'comment'])
            ->where('chain', $chain)
            ->fetch();
    }

    // --------------------------------------------------------------------------
    // FILES
    // --------------------------------------------------------------------------

    /**
     * Returns files on the router filesystem.
     * Note: actual file contents are NOT fetched (they would be very large).
     *
     * @return array<int, array<string, string>>
     */
    public function getFiles(): array
    {
        return $this->client->query('/file/print')
            ->proplist(['.id', 'name', 'type', 'size', 'creation-time'])
            ->fetch();
    }

    public function __destruct()
    {
        $this->close();
    }
}

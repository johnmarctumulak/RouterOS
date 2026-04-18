<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\AuthenticationException;
use RouterOS\Exception\ConnectionException;
use RouterOS\Exception\TrapException;

/**
 * Main RouterOS API client.
 *
 * Handles authentication, command dispatch, and response collection.
 * Supports simultaneous (tagged) commands and streaming listeners.
 *
 * Quick-start:
 *
 *   $client = Client::connect(new Config('192.168.88.1', 'api-user', 'secret'));
 *
 *   // Simple query
 *   $rows = $client->query('/ip/address/print')
 *                   ->proplist(['address', 'interface'])
 *                   ->fetch();                    // returns array of attribute arrays
 *
 *   // One-liner helpers
 *   $client->command('/interface/set', ['disabled' => 'yes', '.id' => '*1']);
 *
 *   $client->close();
 */
final class Client
{
    private Transport $transport;

    /** Monotonically increasing tag counter used for tagged commands */
    private int $tagCounter = 0;

    /** Buffered sentences keyed by tag (for concurrent command support) */
    /** @var array<int, Response[]> */
    private array $tagBuffers = [];

    private function __construct(Transport $transport)
    {
        $this->transport = $transport;
    }

    // --- Factory / lifecycle --------------------------------------------------

    /**
     * Opens a connection and logs in with the supplied credentials.
     *
     * @throws ConnectionException
     * @throws AuthenticationException
     */
    public static function connect(Config $config): self
    {
        $transport = new Transport($config);
        $transport->connect();

        $client = new self($transport);
        $client->login($config->username, $config->password);

        return $client;
    }

    /**
     * Gracefully closes the socket.
     */
    public function close(): void
    {
        $this->transport->disconnect();
    }

    /**
     * Returns true if the underlying socket is still open.
     * Used by ConnectionPool to check liveness before reuse.
     */
    public function isConnected(): bool
    {
        return $this->transport->isConnected();
    }

    /**
     * Returns a fluent query builder scoped to this client.
     *
     * @example
     *   $client->query('/ip/address/print')
     *          ->proplist(['address', 'interface'])
     *          ->where('type', 'ether')
     *          ->fetch();
     */
    public function query(string $command): QueryBuilder
    {
        return new QueryBuilder($this, $command);
    }

    // --- Direct command helpers -----------------------------------------------

    /**
     * Sends a command and collects all replied rows until !done / !trap.
     *
     * @param array<string, string> $args   Key-value attribute pairs (=name=value)
     * @param string[]              $queries  Query words (with or without leading '?')
     * @return array<int, array<string, string>>  Array of attribute maps
     *
     * @throws TrapException
     * @throws ConnectionException
     */
    public function command(
        string $command,
        array  $args    = [],
        array  $queries = [],
    ): array {
        $sentence = Sentence::command($command);

        foreach ($args as $key => $value) {
            $sentence->attr($key, $value);
        }

        foreach ($queries as $q) {
            $sentence->query($q);
        }

        return $this->sendAndCollect($sentence);
    }

    /**
     * Sends a command expecting no data rows (e.g. set / add / remove).
     *
     * @param array<string, string> $args
     * @throws TrapException
     * @throws ConnectionException
     */
    public function execute(string $command, array $args = []): void
    {
        $this->command($command, $args);
    }

    // --- Async command engine (Tagged commands) -------------------------------

    /**
     * Sends a tagged sentence asynchronously (non-blocking send).
     * Caller must later call collectTag() to retrieve replies.
     *
     * @return int  The auto-assigned tag number
     * @throws ConnectionException
     */
    public function sendAsync(Sentence $sentence): int
    {
        $tag = ++$this->tagCounter;
        $sentence->tag($tag);
        $this->transport->writeSentence($sentence->toWords());
        $this->tagBuffers[$tag] = [];
        return $tag;
    }

    /**
     * Collects all replies for a given tag (blocks until !done / !trap).
     *
     * @return array<int, array<string, string>>
     * @throws TrapException
     * @throws ConnectionException
     */
    public function collectTag(int $tag): array
    {
        $rows = [];

        while (true) {
            // Drain any already-buffered responses for this tag first
            while (!empty($this->tagBuffers[$tag])) {
                $response = array_shift($this->tagBuffers[$tag]);

                if ($response->isTrap()) {
                    unset($this->tagBuffers[$tag]);
                    throw new TrapException($response->getAttributes());
                }

                if ($response->isDone() || $response->isEmpty()) {
                    unset($this->tagBuffers[$tag]);
                    return $rows;
                }

                if ($response->isData()) {
                    $rows[] = $response->getAttributes();
                }
            }

            // Read next sentence from the wire and route it by tag
            $words    = $this->transport->readSentence();
            $response = new Response($words);
            $rTag     = $response->getTag();

            $slot = $rTag !== null ? $rTag : $tag;
            $this->tagBuffers[$slot][] = $response;
        }
    }

    /**
     * Cancels a running tagged command.
     *
     * @throws TrapException
     * @throws ConnectionException
     */
    public function cancel(int $tag): void
    {
        $this->command('/cancel', ['tag' => (string) $tag]);
        unset($this->tagBuffers[$tag]);
    }

    // --- Connection Pool management -------------------------------------------

    /**
     * Executes a listen command, calling $callback for every !re sentence
     * received until $callback explicitly returns false or the connection drops.
     *
     * Sentences are cancelled cleanly on exit.
     *
     * @param callable(array<string, string>): (bool|void) $callback
     * @throws ConnectionException
     * @throws TrapException
     */
    public function listen(string $command, callable $callback, array $args = []): void
    {
        $sentence = Sentence::command($command);
        foreach ($args as $k => $v) {
            $sentence->attr($k, $v);
        }

        $tag = $this->sendAsync($sentence);

        try {
            while (true) {
                $words    = $this->transport->readSentence();
                $response = new Response($words);

                if ($response->getTag() !== $tag) {
                    // Buffer sentences belonging to other tags
                    $this->tagBuffers[$response->getTag() ?? 0][] = $response;
                    continue;
                }

                $response->throwIfTrap();

                if ($response->isTerminal()) {
                    break;
                }

                if ($response->isData()) {
                    $result = $callback($response->getAttributes());
                    if ($result === false) {
                        $this->cancel($tag);
                        break;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Attempt clean cancel before re-throwing
            try {
                $this->cancel($tag);
            } catch (\Throwable) {
            }

            throw $e;
        }
    }

    // --- Internal command engine (Synchronous) --------------------------------

    /**
     * Sends a sentence and collects all !re rows, throwing on !trap.
     *
     * @return array<int, array<string, string>>
     * @throws TrapException
     * @throws ConnectionException
     */
    public function sendAndCollect(Sentence $sentence): array
    {
        $this->transport->writeSentence($sentence->toWords());

        $rows = [];

        while (true) {
            $words    = $this->transport->readSentence();
            $response = new Response($words);

            $response->throwIfTrap();

            if ($response->isData()) {
                $rows[] = $response->getAttributes();
                continue;
            }

            if ($response->isTerminal()) {
                break;
            }
        }

        return $rows;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Authentication (post-RouterOS 6.43 plain-text method)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Authenticates using the post-v6.43 plain-text login method.
     * Password is sent over the wire, so always pair with SSL/TLS.
     *
     * @throws AuthenticationException
     * @throws ConnectionException
     */
    private function login(string $username, string $password): void
    {
        $sentence = Sentence::command('/login')
            ->attr('name', $username)
            ->attr('password', $password);

        $this->transport->writeSentence($sentence->toWords());

        while (true) {
            $words    = $this->transport->readSentence();
            $response = new Response($words);

            if ($response->isTrap()) {
                throw new AuthenticationException(
                    'Authentication failed: ' . ($response->getAttribute('message') ?? 'unknown error')
                );
            }

            if ($response->isDone()) {
                return;
            }
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}

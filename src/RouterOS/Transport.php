<?php

declare(strict_types=1);

namespace RouterOS;

use RouterOS\Exception\ConnectionException;

/**
 * Low-level socket transport with buffered I/O for the RouterOS API binary protocol.
 *
 * PERFORMANCE: Read Buffer
 * This library uses a 4KB internal read buffer to minimize system calls.
 * Without a buffer, parsing headers would require multiple fread() calls per word.
 * The buffer serves subsequent bytes from PHP memory, which is significantly faster.
 *
 * Note: Data compression is not supported by the RouterOS API protocol.
 * The binary framing is already near-optimal; use .proplist to reduce data volume.
 *
 * Word length encoding (big-endian, network order):
 *   0x00-0x7F         -> 1 byte  (len)
 *   0x80-0x3FFF       -> 2 bytes (len | 0x8000)
 *   0x4000-0x1FFFFF   -> 3 bytes (len | 0xC00000)
 *   0x200000-0xFFFFFFF -> 4 bytes (len | 0xE0000000)
 *   >= 0x10000000      -> 5 bytes (0xF0 + 4-byte big-endian)
 */
final class Transport
{
    /** @var resource|null */
    private $socket = null;

    private Config $config;

    // --- Read buffer management ----------------------------------------------

    /**
     * In-memory read buffer (pre-fetched socket bytes not yet consumed).
     * Stored as a string; consumed from the left via substr().
     */
    private string $readBuffer = '';

    /**
     * How many bytes to read per fread() syscall.
     * 4096 is the standard OS page size — reads align with kernel I/O pages.
     */
    private const READ_CHUNK = 4096;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    // --- Connection lifecycle -------------------------------------------------

    /**
     * Opens the TCP (or TLS) socket to the router.
     *
     * @throws ConnectionException
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        $context = $this->createStreamContext();
        $scheme  = $this->config->ssl ? 'ssl' : 'tcp';
        $address = "{$scheme}://{$this->config->host}:{$this->config->port}";

        $errorCode    = 0;
        $errorMessage = '';

        $flags  = STREAM_CLIENT_CONNECT;
        $flags |= $this->config->persistent ? STREAM_CLIENT_PERSISTENT : 0;

        $socket = @stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            $this->config->connectTimeout,
            $flags,
            $context,
        );

        if ($socket === false) {
            throw new ConnectionException(
                "Cannot connect to {$address}: [{$errorCode}] {$errorMessage}"
            );
        }

        stream_set_timeout(
            $socket,
            (int) $this->config->readTimeout,
            (int) (fmod($this->config->readTimeout, 1.0) * 1_000_000)
        );

        // Disable PHP's own stream buffering — we manage our own 4 KB buffer.
        // This prevents double-buffering and ensures our buffer is authoritative.
        stream_set_read_buffer($socket, 0);

        $this->socket     = $socket;
        $this->readBuffer = ''; // clear any stale buffer from a previous connection
    }

    /**
     * Closes the socket if open.
     */
    public function disconnect(): void
    {
        if ($this->socket !== null) {
            @fclose($this->socket);
            $this->socket     = null;
            $this->readBuffer = '';
        }
    }

    public function isConnected(): bool
    {
        return $this->socket !== null && !feof($this->socket);
    }

    // --- Network I/O ----------------------------------------------------------

    /**
     * Sends an API sentence (list of words) as a single write() call.
     *
     * All words are encoded and concatenated in PHP memory first,
     * then flushed to the socket in ONE write — minimizing syscall count
     * on the send path as well.
     *
     * @param string[] $words
     * @throws ConnectionException
     */
    public function writeSentence(array $words): void
    {
        $this->assertConnected();

        // Build the entire sentence payload in one PHP string
        // so the kernel gets it in one fwrite() call.
        $parts = [];
        foreach ($words as $word) {
            $parts[] = $this->encodeLength(strlen($word));
            $parts[] = $word;
        }
        $parts[] = "\x00"; // zero-length word = end of sentence

        $this->write(implode('', $parts));
    }

    /**
     * Reads one complete API sentence (until a zero-length word).
     * Uses the internal buffer to minimize fread() syscalls.
     *
     * @return string[]
     * @throws ConnectionException
     */
    public function readSentence(): array
    {
        $this->assertConnected();

        $words = [];

        while (true) {
            $length = $this->decodeLength();

            if ($length === 0) {
                break; // end-of-sentence marker
            }

            $words[] = $this->readExactly($length);
        }

        return $words;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Encoding / decoding
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Encodes a word length as per the RouterOS API specification.
     */
    public function encodeLength(int $len): string
    {
        if ($len < 0x80) {
            return chr($len);
        }

        if ($len < 0x4000) {
            return pack('n', $len | 0x8000);          // 2 bytes
        }

        if ($len < 0x200000) {
            $len |= 0xC00000;
            return chr($len >> 16) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF); // 3 bytes
        }

        if ($len < 0x10000000) {
            return pack('N', $len | 0xE0000000);      // 4 bytes
        }

        return chr(0xF0) . pack('N', $len);           // 5 bytes
    }

    /**
     * Decodes a variable-length word length from the buffer.
     *
     * All byte reads go through bufferByte() — no fread() per byte.
     *
     * @throws ConnectionException
     */
    private function decodeLength(): int
    {
        $first = $this->bufferByte();

        if ($first < 0x80) {
            $len = $first;
        } elseif ($first < 0xC0) {
            $len = (($first & ~0x80) << 8) | $this->bufferByte();
        } elseif ($first < 0xE0) {
            $b1 = $this->bufferByte();
            $b2 = $this->bufferByte();
            $len = (($first & ~0xC0) << 16) | ($b1 << 8) | $b2;
        } elseif ($first < 0xF0) {
            $b1 = $this->bufferByte();
            $b2 = $this->bufferByte();
            $b3 = $this->bufferByte();
            $len = (($first & ~0xE0) << 24) | ($b1 << 16) | ($b2 << 8) | $b3;
        } elseif ($first === 0xF0) {
            [, $len] = unpack('N', $this->readExactly(4));
        } else {
            throw new ConnectionException(
                sprintf('Reserved control byte 0x%02X — connection aborted.', $first)
            );
        }

        if ($len > $this->config->maxWordLength) {
            throw new ConnectionException(
                sprintf('Word length %d exceeds safety limit of %d. Connection aborted to prevent memory exhaustion.', $len, $this->config->maxWordLength)
            );
        }

        return $len;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Buffered read primitives
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Returns a single byte from the buffer (0–255).
     * Refills the buffer with a READ_CHUNK fread() when empty.
     *
     * With typical API responses, this is called ~30 times per sentence
     * but triggers at most 1 fread() — the rest are served from $readBuffer.
     *
     * @throws ConnectionException
     */
    private function bufferByte(): int
    {
        if ($this->readBuffer === '') {
            $this->refillBuffer();
        }

        $byte            = ord($this->readBuffer[0]);
        $this->readBuffer = substr($this->readBuffer, 1);
        return $byte;
    }

    /**
     * Reads exactly $length bytes, using the buffer first, then the socket.
     *
     * For large word bodies (e.g. long firewall comments), we consume from
     * the buffer first to avoid wasting pre-fetched data, then call fread()
     * only for any remaining bytes we still need.
     *
     * @throws ConnectionException
     */
    private function readExactly(int $length): string
    {
        // Fast path: buffer already has everything we need
        if (strlen($this->readBuffer) >= $length) {
            $data            = substr($this->readBuffer, 0, $length);
            $this->readBuffer = substr($this->readBuffer, $length);
            return $data;
        }

        // Consume whatever's in the buffer, then read the rest directly
        $data            = $this->readBuffer;
        $this->readBuffer = '';
        $remaining        = $length - strlen($data);

        while ($remaining > 0) {
            $chunk = @fread($this->socket, max($remaining, self::READ_CHUNK));

            if ($chunk === false || $chunk === '') {
                $this->checkTimeout();
                throw new ConnectionException(
                    'Socket read failed — connection closed by remote host.'
                );
            }

            $got = strlen($chunk);

            if ($got <= $remaining) {
                // All bytes go to the result
                $data      .= $chunk;
                $remaining -= $got;
            } else {
                // We over-read — split: needed bytes → result, rest → buffer
                $data            .= substr($chunk, 0, $remaining);
                $this->readBuffer = substr($chunk, $remaining);
                $remaining        = 0;
            }
        }

        return $data;
    }

    /**
     * Fills the read buffer with one READ_CHUNK fread() call.
     *
     * @throws ConnectionException
     */
    private function refillBuffer(): void
    {
        $chunk = @fread($this->socket, self::READ_CHUNK);

        if ($chunk === false || $chunk === '') {
            $this->checkTimeout();
            throw new ConnectionException(
                'Socket read failed — connection closed by remote host.'
            );
        }

        $this->readBuffer = $chunk;
    }

    /**
     * Checks for a socket timeout and throws the appropriate exception.
     *
     * @throws ConnectionException
     */
    private function checkTimeout(): void
    {
        if ($this->socket !== null) {
            $meta = stream_get_meta_data($this->socket);
            if ($meta['timed_out']) {
                throw new ConnectionException(
                    "Socket read timed out after {$this->config->readTimeout}s."
                );
            }
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Raw write
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Writes raw bytes to the socket, retrying partial writes.
     *
     * @throws ConnectionException
     */
    private function write(string $data): void
    {
        $total = strlen($data);
        $sent  = 0;

        while ($sent < $total) {
            $written = @fwrite($this->socket, substr($data, $sent));

            if ($written === false || $written === 0) {
                throw new ConnectionException('Socket write failed — connection may be lost.');
            }

            $sent += $written;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Stream context (TLS)
    // ──────────────────────────────────────────────────────────────────────────

    /** @return resource */
    private function createStreamContext(): mixed
    {
        $ssl = [
            'verify_peer'      => $this->config->sslVerifyPeer,
            'verify_peer_name' => $this->config->sslVerifyPeer,
        ];

        if ($this->config->sslCaFile !== null) {
            $ssl['cafile'] = $this->config->sslCaFile;
        }

        if ($this->config->sslCertFile !== null) {
            $ssl['local_cert'] = $this->config->sslCertFile;
        }

        if ($this->config->sslKeyFile !== null) {
            $ssl['local_pk'] = $this->config->sslKeyFile;
        }

        return stream_context_create(['ssl' => $ssl]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Guards
    // ──────────────────────────────────────────────────────────────────────────

    /** @throws ConnectionException */
    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw new ConnectionException('Not connected. Call connect() first.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}

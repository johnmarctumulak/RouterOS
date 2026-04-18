<?php

declare(strict_types=1);

namespace RouterOS;

/**
 * Immutable value object representing a RouterOS API connection configuration.
 */
final class Config
{
    public readonly string  $host;
    public readonly int     $port;
    public readonly string  $username;
    public readonly string  $password;
    public readonly bool    $ssl;
    public readonly float   $connectTimeout;
    public readonly float   $readTimeout;
    public readonly bool    $sslVerifyPeer;
    public readonly ?string $sslCaFile;
    public readonly ?string $sslCertFile;
    public readonly ?string $sslKeyFile;
    public readonly bool    $persistent;
    public readonly int     $maxWordLength;

    /**
     * @param string      $host           Router hostname or IP
     * @param string      $username       API username (use least-privilege account)
     * @param string      $password       API password
     * @param bool        $ssl            Use API-SSL port (strongly recommended in production)
     * @param int|null    $port           Override port (defaults: 8728 plain / 8729 SSL)
     * @param float       $connectTimeout TCP connect timeout in seconds
     * @param float       $readTimeout    Socket read timeout in seconds
     * @param bool        $sslVerifyPeer  Verify router TLS certificate (set false only in dev)
     * @param string|null $sslCaFile      Path to CA bundle for peer verification
     * @param string|null $sslCertFile    Path to client certificate (mutual TLS)
     * @param string|null $sslKeyFile     Path to client private key (mutual TLS)
     * @param bool        $persistent     Attempt to reuse persistent socket connections
     */
    public function __construct(
        string  $host,
        string  $username,
        string  $password,
        bool    $ssl            = true,
        ?int    $port           = null,
        float   $connectTimeout = 5.0,
        float   $readTimeout    = 15.0,
        bool    $sslVerifyPeer  = true,
        ?string $sslCaFile      = null,
        ?string $sslCertFile    = null,
        ?string $sslKeyFile     = null,
        bool    $persistent     = false,
        int     $maxWordLength  = 10485760, // 10MB default safety limit
    ) {
        $this->host           = $host;
        $this->username       = $username;
        $this->password       = $password;
        $this->ssl            = $ssl;
        $this->port           = $port ?? ($ssl ? 8729 : 8728);
        $this->connectTimeout = $connectTimeout;
        $this->readTimeout    = $readTimeout;
        $this->sslVerifyPeer  = $sslVerifyPeer;
        $this->sslCaFile      = $sslCaFile;
        $this->sslCertFile    = $sslCertFile;
        $this->sslKeyFile     = $sslKeyFile;
        $this->persistent     = $persistent;
        $this->maxWordLength  = $maxWordLength;
    }
}

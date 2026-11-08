<?php

namespace App\Domain\DataSources;

/**
 * SSRF defense for outbound connections (database and REST sources).
 *
 * Resolves the hostname and rejects loopback, private, link-local (incl.
 * cloud metadata 169.254.169.254) and reserved addresses unless the host is
 * explicitly allow-listed via ORBIT_ALLOWED_SOURCE_HOSTS. The resolved IP is
 * returned so callers connect to exactly the address that was checked
 * (prevents DNS rebinding between check and connect).
 */
class HostGuard
{
    /** @param list<string> $allowedHosts */
    public function __construct(private readonly array $allowedHosts = []) {}

    public static function fromConfig(): self
    {
        return new self(array_map('strtolower', config('orbit.sources.allowed_hosts', [])));
    }

    /**
     * @throws ConnectionRefused
     */
    public function resolve(string $host): string
    {
        $host = strtolower(trim($host));

        if ($host === '' || preg_match('/^[a-z0-9.\-:\[\]]+$/', $host) !== 1) {
            throw new ConnectionRefused('The host name contains invalid characters.');
        }

        $bare = trim($host, '[]');
        $ips = filter_var($bare, FILTER_VALIDATE_IP) !== false ? [$bare] : (gethostbynamel($bare) ?: []);

        if ($ips === []) {
            throw new ConnectionRefused("The host \"{$host}\" could not be resolved.");
        }

        $allowListed = in_array($host, $this->allowedHosts, true) || in_array($bare, $this->allowedHosts, true);

        foreach ($ips as $ip) {
            if (! $allowListed && ! $this->isPublic($ip)) {
                throw new ConnectionRefused(
                    "Connections to private or internal addresses are blocked ({$host}). ".
                    'An administrator can allow this host with ORBIT_ALLOWED_SOURCE_HOSTS.'
                );
            }
        }

        return $ips[0];
    }

    public function isPublic(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }
}

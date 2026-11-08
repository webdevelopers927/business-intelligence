<?php

namespace App\Domain\DataSources;

use App\Enums\DataSourceType;
use App\Models\DataSource;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PDO;
use PDOException;

/**
 * Real connection checks for database and REST sources. Nothing here is
 * simulated: a source is marked active only if the check below succeeds.
 *
 * Database checks open a read-only session with a short timeout and run
 * `SELECT 1`; REST checks issue a GET and verify the records path exists.
 */
final class ConnectionTester
{
    public function __construct(private readonly HostGuard $guard) {}

    /**
     * @param  array<string, string>  $secret
     * @return string Human-readable success detail.
     *
     * @throws ConnectionRefused
     */
    public function test(DataSource $source, array $secret): string
    {
        return match (true) {
            $source->type->isDatabase() => $this->testDatabase($source, $secret),
            $source->type === DataSourceType::Rest => $this->testRest($source, $secret),
            default => 'File sources receive data through uploads; there is nothing to connect to.',
        };
    }

    /** @param array<string, string> $secret */
    private function testDatabase(DataSource $source, array $secret): string
    {
        $config = $source->config ?? [];
        $ip = $this->guard->resolve((string) ($config['host'] ?? ''));
        $port = (int) ($config['port'] ?? $source->type->defaultPort());
        $database = (string) ($config['database'] ?? '');
        $timeout = (int) config('orbit.sources.connect_timeout', 5);

        $dsn = $source->type === DataSourceType::Mysql
            ? "mysql:host={$ip};port={$port};dbname={$database};charset=utf8mb4"
            : "pgsql:host={$ip};port={$port};dbname={$database};connect_timeout={$timeout}";

        try {
            $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), $secret['password'] ?? '', [
                PDO::ATTR_TIMEOUT => $timeout,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec($source->type === DataSourceType::Mysql
                ? 'SET SESSION TRANSACTION READ ONLY'
                : 'SET SESSION CHARACTERISTICS AS TRANSACTION READ ONLY');
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            throw new ConnectionRefused('Could not connect: '.$this->sanitize($e->getMessage(), $secret));
        }

        return "Connected to {$database} on {$config['host']}:{$port} (read-only session).";
    }

    /** @param array<string, string> $secret */
    private function testRest(DataSource $source, array $secret): string
    {
        $config = $source->config ?? [];
        $url = (string) ($config['url'] ?? '');
        $parts = parse_url($url);

        if (! is_array($parts) || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            throw new ConnectionRefused('Enter a full http(s) URL.');
        }

        $ip = $this->guard->resolve($parts['host']);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);

        $request = Http::timeout((int) config('orbit.sources.http_timeout', 15))
            ->connectTimeout((int) config('orbit.sources.connect_timeout', 5))
            ->acceptJson()
            ->withOptions([
                'allow_redirects' => false,
                // Pin the checked IP so DNS can't change between check and request.
                'curl' => [CURLOPT_RESOLVE => ["{$parts['host']}:{$port}:{$ip}"]],
            ]);

        if (! empty($secret['token'])) {
            $header = (string) ($config['auth_header'] ?? 'Authorization');
            $value = $header === 'Authorization' ? 'Bearer '.$secret['token'] : $secret['token'];
            $request = $request->withHeaders([$header => $value]);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException $e) {
            throw new ConnectionRefused('Could not reach the API: '.$this->sanitize($e->getMessage(), $secret));
        }

        if (! $response->successful()) {
            throw new ConnectionRefused("The API responded with HTTP {$response->status()}.");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new ConnectionRefused('The API did not return JSON.');
        }

        $path = trim((string) ($config['records_path'] ?? ''));
        $records = $path === '' ? $json : data_get($json, $path);
        if (! is_array($records) || ! array_is_list($records)) {
            throw new ConnectionRefused($path === ''
                ? 'The response is not a list of records. Set a records path such as "data".'
                : "No list of records was found at \"{$path}\".");
        }

        return 'Reached the API and found '.number_format(count($records)).' record(s) in the first response.';
    }

    /**
     * Never echo secrets back, even inside driver error messages.
     *
     * @param  array<string, string>  $secret
     */
    private function sanitize(string $message, array $secret): string
    {
        foreach ($secret as $value) {
            if ($value !== '') {
                $message = str_replace($value, '[redacted]', $message);
            }
        }

        return mb_substr($message, 0, 300);
    }
}

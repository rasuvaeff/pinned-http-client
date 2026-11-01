<?php

declare(strict_types=1);

// What the policy lets through and what the client refuses before any socket is opened. No network: names are resolved
// by a fixed table, as a stand-in for DNS.

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PublicAddresses;
use Rasuvaeff\PinnedHttpClient\RequestRefused;
use Rasuvaeff\PinnedHttpClient\ResolutionFailed;
use Rasuvaeff\PinnedHttpClient\Resolver;

$policy = new PublicAddresses();

foreach (['93.184.216.34', '2606:4700:4700::1111', '::ffff:8.8.8.8', '127.0.0.1', '10.0.0.5', '169.254.169.254', '::ffff:10.0.0.1', 'fd12::1', '100.64.0.1'] as $address) {
    echo str_pad($address, 24), $policy->allows($address) ? 'allowed' : 'refused', "\n";
}

echo "\n";

$dns = ['public.test' => ['93.184.216.34'], 'rebound.test' => ['127.0.0.1'], 'mixed.test' => ['8.8.8.8', '10.0.0.5']];
$client = new PinnedClient(policy: $policy, resolver: new class ($dns) implements Resolver {
    /** @param array<string, list<string>> $dns */
    public function __construct(private readonly array $dns) {}

    public function resolve(string $host): array
    {
        return $this->dns[$host] ?? [];
    }
});

foreach (['http://rebound.test/', 'http://mixed.test/', 'http://nowhere.test/', 'http://169.254.169.254/latest/meta-data/', 'ftp://public.test/'] as $url) {
    try {
        $client->sendRequest(new Request('GET', $url));
        echo str_pad($url, 44), "sent\n";
    } catch (RequestRefused $refused) {
        echo str_pad($url, 44), 'refused (', $refused->reason->name, '): ', $refused->getMessage(), "\n";
    } catch (ResolutionFailed $unresolved) {
        echo str_pad($url, 44), 'not resolved (may be temporary): ', $unresolved->getMessage(), "\n";
    }
}

<?php

declare(strict_types=1);

// What the policy lets through and what the client refuses before any socket is opened. No network: names are resolved
// by a fixed table, as a stand-in for DNS.

require __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Psr7\Request;
use Rasuvaeff\PinnedHttpClient\PinnedClient;
use Rasuvaeff\PinnedHttpClient\PinningFailed;
use Rasuvaeff\PinnedHttpClient\PublicAddresses;

$policy = new PublicAddresses();

foreach (['93.184.216.34', '2606:4700:4700::1111', '::ffff:8.8.8.8', '127.0.0.1', '10.0.0.5', '169.254.169.254', '::ffff:10.0.0.1', 'fd12::1', '100.64.0.1'] as $address) {
    echo str_pad($address, 24), $policy->allows($address) ? 'allowed' : 'refused', "\n";
}

echo "\n";

$dns = ['public.test' => ['93.184.216.34'], 'rebound.test' => ['127.0.0.1'], 'mixed.test' => ['8.8.8.8', '10.0.0.5']];
$client = new PinnedClient(policy: $policy, resolver: static fn(string $host): array => $dns[$host] ?? []);

foreach (['http://rebound.test/', 'http://mixed.test/', 'http://nowhere.test/', 'http://169.254.169.254/latest/meta-data/', 'ftp://public.test/'] as $url) {
    try {
        $client->sendRequest(new Request('GET', $url));
        echo str_pad($url, 44), "sent\n";
    } catch (PinningFailed $refused) {
        echo str_pad($url, 44), 'refused: ', $refused->getMessage(), "\n";
    }
}

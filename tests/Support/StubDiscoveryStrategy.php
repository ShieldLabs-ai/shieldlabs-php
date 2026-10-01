<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

use Http\Discovery\Strategy\DiscoveryStrategy;
use Psr\Http\Client\ClientInterface;

/**
 * Discovery strategy that offers {@see MockHttpClient} as an installed PSR-18 client.
 */
final class StubDiscoveryStrategy implements DiscoveryStrategy
{
    /**
     * @return list<array{class: class-string, condition: class-string}>
     */
    public static function getCandidates($type): array
    {
        return $type === ClientInterface::class
            ? [['class' => MockHttpClient::class, 'condition' => MockHttpClient::class]]
            : [];
    }
}

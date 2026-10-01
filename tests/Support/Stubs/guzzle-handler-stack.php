<?php

declare(strict_types=1);

/*
 * Stand-in for the HandlerStack of guzzlehttp/guzzle 7, loaded next to the client
 * stand-in. Only tests that run in a separate process load it.
 */

namespace GuzzleHttp;

final class HandlerStack
{
    public static function create(): self
    {
        return new self();
    }
}

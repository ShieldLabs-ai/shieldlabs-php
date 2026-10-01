<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Support;

final class Fixtures
{
    public static function path(string $name): string
    {
        return \dirname(__DIR__) . '/data/' . $name;
    }

    public static function raw(string $name): string
    {
        $contents = file_get_contents(self::path($name));
        if ($contents === false) {
            throw new \RuntimeException('Missing fixture ' . $name);
        }

        return $contents;
    }

    /**
     * @return array<mixed>
     */
    public static function json(string $name): array
    {
        $decoded = json_decode(self::raw($name), true, 512, \JSON_THROW_ON_ERROR);
        if (!\is_array($decoded)) {
            throw new \RuntimeException('Fixture ' . $name . ' is not a JSON object');
        }

        return $decoded;
    }
}

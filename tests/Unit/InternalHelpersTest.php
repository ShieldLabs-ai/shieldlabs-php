<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use ShieldLabs\Internal\Env;
use ShieldLabs\Internal\Options;
use ShieldLabs\Internal\ResponseErrors;
use ShieldLabs\Internal\SystemClock;
use ShieldLabs\Internal\Time;
use ShieldLabs\Model\DomainProfile;
use ShieldLabs\Model\HistoryPage;

final class InternalHelpersTest extends TestCase
{
    public function testSystemClockIsMonotonicAndSleeps(): void
    {
        $clock = new SystemClock();
        $start = $clock->now();
        $clock->sleep(0.02);
        $clock->sleep(0.0);
        $clock->sleep(-1.0);

        self::assertGreaterThanOrEqual(0.015, $clock->now() - $start);
        self::assertLessThan(5.0, $clock->now() - $start);
    }

    public function testSystemClockSleepsWholeSeconds(): void
    {
        $clock = new SystemClock();
        $start = $clock->now();
        $clock->sleep(1.01);

        self::assertGreaterThanOrEqual(1.0, $clock->now() - $start);
    }

    public function testEnvFallsBackToSuperglobals(): void
    {
        putenv('SHIELDLABS_TEST_VALUE');
        $_ENV['SHIELDLABS_TEST_VALUE'] = 'from-env-array';
        try {
            self::assertSame('from-env-array', Env::get('SHIELDLABS_TEST_VALUE'));
            unset($_ENV['SHIELDLABS_TEST_VALUE']);
            $_SERVER['SHIELDLABS_TEST_VALUE'] = 'from-server-array';
            self::assertSame('from-server-array', Env::get('SHIELDLABS_TEST_VALUE'));
            $_SERVER['SHIELDLABS_TEST_VALUE'] = '';
            self::assertNull(Env::get('SHIELDLABS_TEST_VALUE'));
        } finally {
            unset($_ENV['SHIELDLABS_TEST_VALUE'], $_SERVER['SHIELDLABS_TEST_VALUE']);
        }
    }

    public function testOptionsStringIsNullWhenMissing(): void
    {
        self::assertNull(Options::string([], 'base_url'));
        self::assertNull(Options::get(['x' => null], 'x'));
    }

    public function testErrorBodiesThatCannotBeReadBecomeEmpty(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('__toString')->willThrowException(new \RuntimeException('stream closed'));
        $response = (new Response(500))->withBody($stream);

        self::assertSame('', ResponseErrors::body($response));
        self::assertSame('', ResponseErrors::fromResponse($response)->getRawBody());
    }

    public function testWholeNumberFloatsBecomeIntegers(): void
    {
        $profile = DomainProfile::fromArray(json_decode('{"Weight": 12.0}', true, 512, \JSON_THROW_ON_ERROR));
        $page = HistoryPage::fromArray(json_decode('{"data": [], "total": 2.0}', true, 512, \JSON_THROW_ON_ERROR));

        self::assertSame(12, $profile->remaining_identifications);
        self::assertSame(2, $page->total);
    }

    public function testTimeBuildRejectsInvalidParts(): void
    {
        self::assertNull(Time::build('2026-09-30', '12:00:00', '', 'Z'));
        self::assertNull(Time::build('2026-09-30', '25:00:00', '', '+00:00'));
        self::assertNull(Time::format(null));
        self::assertSame(1790769720.5, Time::unix(new \DateTimeImmutable('2026-09-30T12:02:00.5Z')));
    }
}

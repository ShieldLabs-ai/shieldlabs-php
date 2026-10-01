<?php

declare(strict_types=1);

namespace ShieldLabs\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShieldLabs\Internal\Normalizer;
use ShieldLabs\Model\DomainProfile;
use ShieldLabs\Model\HistoryPage;
use ShieldLabs\Model\Identification;
use ShieldLabs\Risk;
use ShieldLabs\RiskBand;
use ShieldLabs\Tests\Support\Fixtures;

/**
 * The shared test fixtures: every ShieldLabs server SDK produces exactly these values.
 */
final class ContractFixturesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<mixed>, array<mixed>}>
     */
    public static function normalizationCases(): iterable
    {
        foreach (Fixtures::json('normalization-cases.json')['cases'] as $case) {
            \assert(\is_array($case) && \is_string($case['name']) && \is_string($case['source']));
            \assert(\is_array($case['input']) && \is_array($case['expected']));

            yield $case['name'] => [$case['source'], $case['input'], $case['expected']];
        }
    }

    /**
     * @param array<mixed> $input
     * @param array<mixed> $expected
     */
    #[DataProvider('normalizationCases')]
    public function testNormalizesToTheExpectedIdentification(string $source, array $input, array $expected): void
    {
        $identification = $source === 'history'
            ? Identification::fromHistoryRow($input)
            : Identification::fromWebhookData($input);

        self::assertSame($expected, $identification->toArray());
        self::assertSame($input, $identification->raw);
        self::assertSame($source, $identification->source);
        self::assertNotNull($identification->observed_at);
        self::assertSame('UTC', $identification->observed_at->getTimezone()->getName());
    }

    public function testCoversBothSources(): void
    {
        $sources = array_count_values(array_map(static fn(array $case): string => $case[0], iterator_to_array(self::normalizationCases())));

        self::assertSame(['history' => 5, 'webhook' => 3], $sources);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function slugCases(): iterable
    {
        foreach (Fixtures::json('signal-slug-cases.json')['cases'] as $index => $case) {
            \assert(\is_array($case) && \is_string($case['description']) && \is_string($case['slug']));

            yield $index . ': ' . $case['description'] => [$case['description'], $case['slug']];
        }
    }

    #[DataProvider('slugCases')]
    public function testDerivesTheSignalSlug(string $description, string $slug): void
    {
        self::assertSame($slug, Normalizer::signalSlug($description));
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function bandCases(): iterable
    {
        foreach (Fixtures::json('risk-band-cases.json')['cases'] as $case) {
            \assert(\is_array($case) && \is_int($case['score']) && \is_string($case['band']));

            yield 'score ' . $case['score'] => [$case['score'], $case['band']];
        }
    }

    #[DataProvider('bandCases')]
    public function testMapsTheScoreToItsBand(int $score, string $band): void
    {
        self::assertSame($band, Risk::band($score)->value);
        self::assertSame(RiskBand::from($band), RiskBand::fromScore($score));
        self::assertSame($band === 'rate_limited', Risk::isRateLimited($score));
    }

    public function testParsesTheHistoryPage(): void
    {
        $body = Fixtures::json('history-page.json');
        $page = HistoryPage::fromArray($body);

        self::assertSame(37, $page->total);
        self::assertCount(5, $page);
        \assert(\is_array($body['data']));
        foreach ($page->data as $index => $identification) {
            self::assertSame($body['data'][$index], $identification->raw);
            self::assertSame('history', $identification->source);
        }

        $expected = [];
        foreach (self::normalizationCases() as [$source, , $case]) {
            if ($source === 'history') {
                $expected[] = $case;
            }
        }
        self::assertSame($expected, array_map(static fn(Identification $item): array => $item->toArray(), $page->data));
    }

    public function testParsesTheEmptyHistoryPage(): void
    {
        $page = HistoryPage::fromArray(Fixtures::json('history-empty.json'));

        self::assertSame([], $page->data);
        self::assertSame(0, $page->total);
        self::assertCount(0, $page);
    }

    public function testNormalizesTheManagementProfile(): void
    {
        $body = Fixtures::json('management-profile.json');
        $profile = DomainProfile::fromArray($body);

        self::assertSame(Fixtures::json('management-profile-expected.json'), $profile->toArray());
        self::assertSame($body, $profile->raw);
        self::assertSame('', $profile->raw['Callback']);
    }
}

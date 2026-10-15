<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedImageService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFingerprintService;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Prüft, dass die Dienste mit Zwischenspeicher zwischen zwei Nachrichten eines Workers geleert werden.
 */
#[CoversClass(ShippingCostCalculatorService::class)]
#[CoversClass(ShippingFingerprintService::class)]
#[CoversClass(FeedImageService::class)]
#[CoversClass(ConfigurationService::class)]
final class ResetBetweenMessagesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<ResetInterface>, string, mixed, mixed}>
     */
    public static function services(): iterable
    {
        yield 'Versandkosten' => [ShippingCostCalculatorService::class, 'countryCache', ['DE' => null], []];
        yield 'Prüfwert' => [ShippingFingerprintService::class, 'configurationStamp', 'stale', null];
        yield 'Feedbilder' => [FeedImageService::class, 'resolved', ['product' => '/media/a.jpg'], null];
        yield 'Einstellungen' => [ConfigurationService::class, 'fallbackPerCountry', ['channel' => ['DE' => 4.9]], []];
    }

    /**
     * Was: ein gefüllter Zwischenspeicher, danach reset().
     * Warum: Ein langlebiger Worker hielte sonst Versandarten, Länder und Einstellungen aus dem vorigen Lauf.
     * Erwartet: der Speicher ist wieder leer.
     *
     * @param class-string<ResetInterface> $class
     */
    #[DataProvider('services')]
    public function testResetEmptiesTheCache(string $class, string $property, mixed $filled, mixed $empty): void
    {
        $service = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        $field = new \ReflectionProperty($class, $property);
        $field->setValue($service, $filled);

        self::assertInstanceOf(ResetInterface::class, $service);
        $service->reset();

        self::assertSame($empty, $field->getValue($service));
    }
}

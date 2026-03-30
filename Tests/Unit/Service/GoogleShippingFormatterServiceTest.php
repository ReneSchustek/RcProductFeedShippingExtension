<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\GoogleShippingFormatterService;

class GoogleShippingFormatterServiceTest extends TestCase
{
    private GoogleShippingFormatterService $service;

    protected function setUp(): void
    {
        $this->service = new GoogleShippingFormatterService();
    }

    public function testFormatsSingleCountry(): void
    {
        $result = $this->service->format(['DE' => 4.95]);

        self::assertSame('DE:::4.95 EUR', $result);
    }

    public function testFormatsMultipleCountries(): void
    {
        $result = $this->service->format(['DE' => 4.95, 'AT' => 9.90, 'CH' => 14.90]);

        self::assertSame('DE:::4.95 EUR,AT:::9.90 EUR,CH:::14.90 EUR', $result);
    }

    public function testFormatsDecimalPlacesCorrectly(): void
    {
        $result = $this->service->format(['DE' => 4.9]);

        self::assertSame('DE:::4.90 EUR', $result);
    }

    public function testFormatsZeroCostCorrectly(): void
    {
        $result = $this->service->format(['DE' => 0.0]);

        self::assertSame('DE:::0.00 EUR', $result);
    }

    public function testUsesCustomCurrency(): void
    {
        $result = $this->service->format(['CH' => 14.90], 'CHF');

        self::assertSame('CH:::14.90 CHF', $result);
    }
}

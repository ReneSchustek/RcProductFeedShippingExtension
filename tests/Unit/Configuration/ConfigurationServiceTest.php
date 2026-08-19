<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Configuration;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class ConfigurationServiceTest extends TestCase
{
    private SystemConfigService&MockObject $systemConfigService;
    private LoggerInterface&MockObject $logger;
    private ConfigurationService $service;

    protected function setUp(): void
    {
        $this->systemConfigService = $this->createMock(SystemConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new ConfigurationService($this->systemConfigService, $this->logger);
    }

    public function testIsEnabledReturnsTrueByDefault(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        self::assertTrue($this->service->isEnabled('channel-id'));
    }

    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $this->systemConfigService->method('get')->willReturn(false);

        self::assertFalse($this->service->isEnabled('channel-id'));
    }

    public function testGetCountriesReturnsUppercaseIsoCodes(): void
    {
        $this->systemConfigService->method('getString')->willReturn('de,at,ch');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesTrimsWhitespace(): void
    {
        $this->systemConfigService->method('getString')->willReturn(' DE , AT , CH ');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesIgnoresEmptyEntries(): void
    {
        $this->systemConfigService->method('getString')->willReturn('DE,,AT,,,CH');

        $result = $this->service->getCountries('channel-id');

        self::assertSame(['DE', 'AT', 'CH'], $result);
    }

    public function testGetCountriesReturnsEmptyArrayForEmptyString(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getCountries('channel-id');

        self::assertSame([], $result);
    }

    public function testGetFallbackShippingCostNormalizesNegativeToZero(): void
    {
        $this->systemConfigService->method('get')->willReturn(-5.0);

        $result = $this->service->getFallbackShippingCost('channel-id');

        self::assertSame(0.0, $result);
    }

    public function testGetFallbackShippingCostReturnsZeroForNull(): void
    {
        $this->systemConfigService->method('get')->willReturn(null);

        $result = $this->service->getFallbackShippingCost('channel-id');

        self::assertSame(0.0, $result);
    }

    public function testGetFallbackShippingCostForCountryReturnsCountrySpecificValue(): void
    {
        $this->systemConfigService->method('getString')
            ->willReturn('DE:4.95,AT:9.90,CH:14.90');

        $result = $this->service->getFallbackShippingCostForCountry('AT', 'channel-id');

        self::assertSame(9.90, $result);
    }

    public function testGetFallbackShippingCostForCountryIsCaseInsensitive(): void
    {
        $this->systemConfigService->method('getString')
            ->willReturn('de:4.95');

        $result = $this->service->getFallbackShippingCostForCountry('DE', 'channel-id');

        self::assertSame(4.95, $result);
    }

    /**
     * Was: Die Einträge sind mit Semikolon getrennt statt mit Komma.
     * Warum: Genau so stand es auf live-clone — `DE:7.95;AT:14.95;CH:21.95`. Weil nur am Komma
     *        getrennt wurde, war die Zeichenkette ein einziger Eintrag: DE traf zufällig zu, AT und
     *        CH fielen still auf den globalen Ersatzwert zurück. 1040 Kombinationen Speditionsware
     *        standen dadurch mit 7,95 € statt 14,95 € bzw. 21,95 € im Warenstrom — zu niedrige
     *        Versandkosten sind bei Google ein Richtlinienverstoß.
     * Erwartet: Jedes Land bekommt seinen eigenen Betrag.
     */
    public function testSemicolonSeparatedEntriesAreReadAsWell(): void
    {
        $this->systemConfigService->method('getString')
            ->willReturn('DE:7.95;AT:14.95;CH:21.95');

        self::assertSame(7.95, $this->service->getFallbackShippingCostForCountry('DE', 'channel-id'));
        self::assertSame(14.95, $this->service->getFallbackShippingCostForCountry('AT', 'channel-id'));
        self::assertSame(21.95, $this->service->getFallbackShippingCostForCountry('CH', 'channel-id'));
    }

    /**
     * Was: Ein Eintrag ohne Zahl hinter dem Doppelpunkt.
     * Warum: `(float) 'frei'` ist 0.0 — kostenloser Versand im Warenstrom, entstanden aus einem
     *        Tippfehler. Der Eintrag muss verworfen **und gemeldet** werden, sonst bleibt der
     *        teuerste denkbare Wert die stille Auslegung.
     * Erwartet: der globale Ersatzwert, dazu genau eine Meldung.
     */
    public function testAnUnreadableEntryIsReportedInsteadOfBecomingZero(): void
    {
        $this->systemConfigService->method('getString')->willReturn('DE:frei');
        $this->systemConfigService->method('get')->willReturn(7.95);

        $this->logger->expects(self::once())->method('warning');

        self::assertSame(7.95, $this->service->getFallbackShippingCostForCountry('DE', 'channel-id'));
    }

    /**
     * Was: Zwei Abfragen auf denselben Kanal.
     * Warum: Der Warmup fragt je Kombination — bei mehreren tausend Ersatzwerten stünde dieselbe
     *        Meldung tausendfach im Protokoll und die Zuordnung würde tausendfach neu gebaut.
     * Erwartet: eine einzige Meldung.
     */
    public function testAnUnreadableEntryIsReportedOnlyOncePerChannel(): void
    {
        $this->systemConfigService->method('getString')->willReturn('DE:frei');
        $this->systemConfigService->method('get')->willReturn(7.95);

        $this->logger->expects(self::once())->method('warning');

        $this->service->getFallbackShippingCostForCountry('DE', 'channel-id');
        $this->service->getFallbackShippingCostForCountry('AT', 'channel-id');
    }

    /**
     * Was: Leere Einträge zwischen den Trennzeichen — `DE:4.95;;AT:9.90;`.
     * Warum: Ein Semikolon am Ende oder ein doppeltes in der Mitte ist ein Tippfehler ohne
     *        Aussage. Würde er wie ein unlesbarer Eintrag gemeldet, stünde bei jedem Speichern
     *        eine Warnung im Protokoll, die nichts bedeutet — und eine Warnung, die nichts
     *        bedeutet, liest bald niemand mehr.
     * Erwartet: beide Länder werden gelesen, keine Meldung.
     */
    public function testEmptyEntriesBetweenSeparatorsAreIgnoredSilently(): void
    {
        $this->systemConfigService->method('getString')->willReturn('DE:4.95;;AT:9.90;');

        $this->logger->expects(self::never())->method('warning');

        self::assertSame(4.95, $this->service->getFallbackShippingCostForCountry('DE', 'channel-id'));
        self::assertSame(9.90, $this->service->getFallbackShippingCostForCountry('AT', 'channel-id'));
    }

    /**
     * Was: Die Einstellung „Land weglassen".
     * Warum: An dieser einen Zeichenkette hängt, ob der Warenstrom für Ware ohne Versandart
     *        schweigt oder den Ersatzwert nennt. Ein Tippfehler im Vergleichswert kippt die
     *        Voreinstellung stillschweigend in die andere Richtung.
     * Erwartet: nur der Wert `omit` schaltet um.
     */
    public function testOnlyTheOmitValueSwitchesTheBehaviour(): void
    {
        $this->systemConfigService->method('getString')->willReturn('omit');

        self::assertTrue($this->service->shouldOmitCountryWithoutShippingMethod('channel-id'));
    }

    /**
     * Was: Derselbe Schalter mit dem Vorgabewert.
     * Warum: Eine bestehende Installation darf ihr Verhalten nicht von selbst ändern.
     * Erwartet: der Warenstrom nennt weiterhin den Ersatzwert.
     */
    public function testTheFallbackBehaviourIsTheDefault(): void
    {
        $this->systemConfigService->method('getString')->willReturn('fallback');

        self::assertFalse($this->service->shouldOmitCountryWithoutShippingMethod('channel-id'));
    }

    public function testGetExcludedShippingMethodsReturnsDefaultsForEmptyConfig(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getExcludedShippingMethods('channel-id');

        self::assertSame(['Selbstabholung', 'Abholung', 'Pickup'], $result);
    }

    public function testGetExcludedShippingMethodsReturnsConfiguredValues(): void
    {
        $this->systemConfigService->method('getString')->willReturn('Express,Spedition');

        $result = $this->service->getExcludedShippingMethods('channel-id');

        self::assertSame(['Express', 'Spedition'], $result);
    }

    public function testGetCalculationSalesChannelIdReturnsNullForEmptyValue(): void
    {
        $this->systemConfigService->method('getString')->willReturn('');

        $result = $this->service->getCalculationSalesChannelId('channel-id');

        self::assertNull($result);
    }

    public function testGetCalculationSalesChannelIdReturnsTrimmedValue(): void
    {
        $this->systemConfigService->method('getString')->willReturn('  abc-123  ');

        $result = $this->service->getCalculationSalesChannelId('channel-id');

        self::assertSame('abc-123', $result);
    }
}

<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Struct;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingContextProvider;

class ShippingContextProviderTest extends TestCase
{
    private ShippingCostCalculatorService&MockObject $calculator;
    private ShippingFallbackService&MockObject $fallbackService;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->calculator = $this->createMock(ShippingCostCalculatorService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    /**
     * Der wichtigste Test der Klasse.
     *
     * Rechnet der Provider aus der Vorlage heraus, scheitert er auf Shopware 6.7 bei jedem Produkt:
     * Twig sperrt während des Renderns interne Entity-Felder, und die Warenkorb-Regelauswertung
     * braucht mit `RuleEntity::payload` genau so eines. Der Ausfall bleibt unsichtbar,
     * weil der Ersatzwert einspringt. Deshalb hier festgeschrieben: **`calculate()` wird nie
     * aufgerufen.**
     */
    public function testTheProviderNeverCalculatesItself(): void
    {
        $this->calculator->expects(self::never())->method('calculate');
        $this->calculator->method('lookupCached')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 7.5, 'EUR', false));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(7.5, $provider->get('product-id', 'DE'));
    }

    public function testGetReturnsNullForUnconfiguredCountry(): void
    {
        $provider = $this->buildProvider(['DE', 'AT']);

        self::assertNull($provider->get('product-id', 'CH'));
    }

    public function testGetReturnsPriceForConfiguredCountry(): void
    {
        $this->calculator->method('lookupCached')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(4.95, $provider->get('product-id', 'DE'));
    }

    public function testGetIsCaseInsensitive(): void
    {
        $this->calculator->expects(self::once())
            ->method('lookupCached')
            ->with('product-id', 'DE', 'sales-channel-id')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 4.95, 'EUR', false));

        $provider = $this->buildProvider(['DE']);

        self::assertSame(4.95, $provider->get('product-id', 'de'));
    }

    /**
     * Fehlt der vorberechnete Wert, kommt der Ersatzwert — aber **nicht stillschweigend**.
     * Ein Notnagel, der niemandem auffällt, war die Ursache dafür, dass der Ausfall monatelang unentdeckt blieb.
     */
    public function testWithoutPrecomputedValue_FallbackIsUsed_WithWarning(): void
    {
        $this->calculator->method('lookupCached')->willReturn(null);
        $this->fallbackService->method('getFallbackResult')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 9.99, 'EUR', true));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'RcProductFeedShipping: kein vorberechneter Versandpreis, Ersatzwert wird ausgeliefert.',
                self::callback(function (array $context): bool {
                    self::assertSame('feed_fallback_used', $context['metric']);
                    self::assertSame('DE', $context['countryIso']);
                    self::assertArrayHasKey('hint', $context);

                    return true;
                }),
            );

        $provider = $this->buildProvider(['DE']);

        self::assertSame(9.99, $provider->get('product-id', 'DE'));
    }

    /**
     * Ein kalter Speicher betrifft alle Produkte gleichzeitig. Eine Zeile je Produkt und Land wären
     * bei diesem Shop über 6000 gleichlautende Meldungen — die Warnung ginge im Rauschen unter.
     */
    public function testTheWarningIsEmittedOncePerCountry(): void
    {
        $this->calculator->method('lookupCached')->willReturn(null);
        $this->fallbackService->method('getFallbackResult')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 9.99, 'EUR', true));

        $this->logger->expects(self::exactly(2))->method('warning');

        $provider = $this->buildProvider(['DE', 'AT']);

        foreach (['p1', 'p2', 'p3'] as $productId) {
            $provider->get($productId, 'DE');
            $provider->get($productId, 'AT');
        }
    }

    /**
     * Die Einzelwarnungen sagen „es fehlt etwas", nicht „wie viel". Ohne die Zusammenfassung bliebe
     * offen, ob drei Produkte betroffen sind oder der ganze Feed.
     */
    public function testTheSummaryNamesMatchesAndFallbacks(): void
    {
        $this->calculator->method('lookupCached')->willReturnCallback(
            static fn (string $productId): ?ShippingCalculationResult => $productId === 'vorhanden'
                ? new ShippingCalculationResult($productId, 'DE', 7.5, 'EUR', false)
                : null
        );
        $this->fallbackService->method('getFallbackResult')
            ->willReturn(new ShippingCalculationResult('fehlt', 'DE', 9.99, 'EUR', true));

        $provider = $this->buildProvider(['DE']);
        $provider->get('vorhanden', 'DE');
        $provider->get('fehlt-1', 'DE');
        $provider->get('fehlt-2', 'DE');

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'RcProductFeedShipping: Feed mit Ersatzwerten ausgeliefert.',
                self::callback(function (array $context): bool {
                    self::assertSame('feed_fallback_summary', $context['metric']);
                    self::assertSame(1, $context['precomputed']);
                    self::assertSame(2, $context['fallback']);

                    return true;
                }),
            );

        $provider->logSummary();
    }

    /**
     * Ein vollständig warmer Lauf darf nichts report — sonst gewöhnt man sich die Warnung ab.
     */
    public function testWithoutFallbacks_TheSummaryStaysSilent(): void
    {
        $this->calculator->method('lookupCached')
            ->willReturn(new ShippingCalculationResult('product-id', 'DE', 7.5, 'EUR', false));

        $provider = $this->buildProvider(['DE']);
        $provider->get('product-id', 'DE');

        $this->logger->expects(self::never())->method('warning');

        $provider->logSummary();
    }

    public function testGetCountriesReturnsConfiguredCountries(): void
    {
        $provider = $this->buildProvider(['DE', 'AT', 'CH']);

        self::assertSame(['DE', 'AT', 'CH'], $provider->getCountries());
    }

    /**
     * @return iterable<string, array{0:string}>
     */
    public static function invalidIsoCodes(): iterable
    {
        yield 'uuid' => ['018f3a0b-b6f2-7a7e-9c00-1234567890ab'];
        yield 'mit-zahlen' => ['DE1'];
        yield 'mit-sonderzeichen' => ['DE-AT'];
        yield 'empty' => [''];
        yield 'whitespace' => [' DE '];
        yield 'einzelner-buchstabe' => ['D'];
        yield 'zu-lang' => ['DEUT'];
        yield 'newline' => ["DE\n"];
    }

    #[DataProvider('invalidIsoCodes')]
    public function testGetReturnsNullAndLogsWarningForInvalidIsoFormat(string $iso): void
    {
        $this->logger->expects(self::once())
            ->method('warning')
            ->with(
                'RcProductFeedShipping: ungültiges ISO-Code-Format ignoriert.',
                self::callback(function (array $context): bool {
                    self::assertSame('invalid_iso_code', $context['metric']);
                    self::assertArrayHasKey('countryIso', $context);

                    return true;
                }),
            );

        $this->calculator->expects(self::never())->method('lookupCached');

        $provider = $this->buildProvider(['DE', 'AT']);
        self::assertNull($provider->get('product-id', $iso));
    }

    public function testGetReturnsNullSilentlyForUnconfiguredButValidIso(): void
    {
        $this->logger->expects(self::never())->method('warning');
        $this->calculator->expects(self::never())->method('lookupCached');

        $provider = $this->buildProvider(['DE', 'AT']);
        self::assertNull($provider->get('product-id', 'CH'));
    }

    /**
     * Der Kern der Sache: Gibt es für ein Land keine Versandart, darf der Feed keinen
     * Preis nennen. Ein weggelassener `g:shipping`-Block heißt bei Google „nimm die
     * Kontoeinstellung" — für Ware, die nur auf Anfrage geht, ist das die einzige wahre Aussage.
     */
    public function testWithoutShippingMethod_NoPriceIsEmitted_WhenConfigured(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'CH',
            4.95,
            'EUR',
            true,
            FallbackReason::NoShippingMethod,
        ));

        $provider = $this->buildProvider(['CH'], omitCountryWithoutShippingMethod: true);

        self::assertNull($provider->get('product-id', 'CH'));
    }

    /**
     * Die Gegenprobe, ohne die die Einstellung gefährlich wäre: Ein kalter Speicher weiß nicht,
     * dass es keine Versandart gibt — er weiß gar nichts. Würde auch dieser Fall den Block
     * weglassen, verlöre ein Feed nach jedem `cache:clear` schlagartig alle Versandangaben.
     */
    public function testColdCache_StillReturnsTheFallback(): void
    {
        $this->calculator->method('lookupCached')->willReturn(null);
        $this->fallbackService->method('getFallbackResult')->willReturn(
            new ShippingCalculationResult('product-id', 'CH', 4.95, 'EUR', true, FallbackReason::NotPrecomputed),
        );

        $provider = $this->buildProvider(['CH'], omitCountryWithoutShippingMethod: true);

        self::assertSame(4.95, $provider->get('product-id', 'CH'));
    }

    /**
     * Eine fehlgeschlagene Berechnung ist kein Beleg dafür, dass nicht versendet wird — sie ist ein
     * Betriebsfehler und steht als solcher im Protokoll. Den Block deshalb wegzulassen, würde einen
     * Fehler als Geschäftsregel ausgeben.
     */
    public function testFailedCalculation_KeepsTheFallback(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'CH',
            4.95,
            'EUR',
            true,
            FallbackReason::CalculationFailed,
        ));

        $provider = $this->buildProvider(['CH'], omitCountryWithoutShippingMethod: true);

        self::assertSame(4.95, $provider->get('product-id', 'CH'));
    }

    public function testWithoutTheSetting_TheFallbackRemains(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'CH',
            4.95,
            'EUR',
            true,
            FallbackReason::NoShippingMethod,
        ));

        $provider = $this->buildProvider(['CH']);

        self::assertSame(4.95, $provider->get('product-id', 'CH'));
    }

    /**
     * Die beiden Zahlen dürfen nicht verschmelzen: „kein Versand dorthin" ist ein Ergebnis,
     * „nicht vorberechnet" ein Betriebszustand. Eine gemeinsame Zahl kostet bei der Fehlersuche Tage
     * gekostet.
     */
    public function testTheSummarySeparatesBothCounts(): void
    {
        $this->calculator->method('lookupCached')->willReturnCallback(
            static fn (string $productId, string $country): ?ShippingCalculationResult => $country === 'CH'
                ? new ShippingCalculationResult($productId, 'CH', 4.95, 'EUR', true, FallbackReason::NoShippingMethod)
                : null,
        );
        $this->fallbackService->method('getFallbackResult')->willReturn(
            new ShippingCalculationResult('product-id', 'AT', 4.95, 'EUR', true, FallbackReason::NotPrecomputed),
        );

        $this->logger->expects(self::atLeastOnce())
            ->method('warning')
            ->with(self::anything(), self::callback(static function (array $context): bool {
                if (($context['metric'] ?? '') !== 'feed_fallback_summary') {
                    return true;
                }

                self::assertSame(1, $context['withoutShippingMethod']);
                self::assertSame(1, $context['fallback']);

                return true;
            }));

        $provider = $this->buildProvider(['AT', 'CH'], omitCountryWithoutShippingMethod: true);
        $provider->get('product-id', 'CH');
        $provider->get('product-id', 'AT');
        $provider->logSummary();
    }

    /**
     * @param array<int, string> $countries
     */
    private function buildProvider(array $countries, bool $omitCountryWithoutShippingMethod = false): ShippingContextProvider
    {
        return new ShippingContextProvider(
            $this->calculator,
            $this->fallbackService,
            $countries,
            'sales-channel-id',
            'EUR',
            $this->logger,
            $omitCountryWithoutShippingMethod,
        );
    }

    /**
     * Was: Ein Artikel, der sich nicht in den Warenkorb legen lässt — bei Trummer sind das die
     *      Hauptartikel mit Varianten.
     * Warum: Für ihn gibt es keinen Versandpreis. Jede Zahl wäre erfunden, und der Ersatzwert
     *        von 4,95 € ist bei einem Artikel, dessen Varianten 238 € Versand kosten, die
     *        schlechteste davon — zu niedrig angegebene Versandkosten sind bei Google ein
     *        Richtlinienverstoß. Auf live-clone betraf das 309 Artikel.
     * Erwartet: kein Block, unabhängig von der Einstellung.
     */
    public function testNotPurchasableProductEmitsNoPrice(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'DE',
            4.95,
            'EUR',
            true,
            FallbackReason::NotPurchasable,
        ));

        $provider = $this->buildProvider(['DE']);

        self::assertNull($provider->get('product-id', 'DE'));
    }

    /**
     * Was: Dasselbe mit eingeschalteter Weglass-Einstellung.
     * Warum: Die Einstellung entscheidet, ob „der Shop versendet dorthin nicht" als Schweigen
     *        oder als Ersatzwert im Feed steht — eine Aussage über das Sortiment. „Nicht
     *        bestellbar" ist keine solche Aussage, sondern schlicht Unwissen. Die Einstellung
     *        darf daran nichts ändern, sonst hängt eine Richtigkeit an einem Häkchen.
     * Erwartet: ebenfalls kein Block.
     */
    public function testNotPurchasableIgnoresTheOmitSetting(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'DE',
            4.95,
            'EUR',
            true,
            FallbackReason::NotPurchasable,
        ));

        $provider = $this->buildProvider(['DE'], omitCountryWithoutShippingMethod: true);

        self::assertNull($provider->get('product-id', 'DE'));
    }

    /**
     * Was: Die Zusammenfassung nennt nicht bestellbare Artikel gesondert.
     * Warum: Sonst verschwänden sie ganz — sie liefern keinen Block und keinen Ersatzwert. Ein
     *        Sortiment, das plötzlich zur Hälfte nicht bestellbar ist, wäre dann unsichtbar.
     * Erwartet: eine Meldung mit eigener Zahl.
     */
    public function testSummaryCountsNotPurchasableSeparately(): void
    {
        $this->calculator->method('lookupCached')->willReturn(new ShippingCalculationResult(
            'product-id',
            'DE',
            4.95,
            'EUR',
            true,
            FallbackReason::NotPurchasable,
        ));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::anything(), self::callback(static function (array $kontext): bool {
                self::assertSame(1, $kontext['notPurchasable']);
                self::assertSame(0, $kontext['fallback']);

                return true;
            }));

        $provider = $this->buildProvider(['DE']);
        $provider->get('product-id', 'DE');
        $provider->logSummary();
    }
}

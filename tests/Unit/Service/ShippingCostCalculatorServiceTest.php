<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Ruhrcoder\RcProductFeedShippingExtension\Storage\ShippingPriceStore;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingAddressProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingCostCalculatorService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingFallbackService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\VirtualCartBuilderService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\FallbackReason;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingAddress;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\ShippingCalculationResult;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryInformation;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceCollection;
use Shopware\Core\Checkout\Shipping\Aggregate\ShippingMethodPrice\ShippingMethodPriceEntity;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\Price;
use Shopware\Core\Framework\DataAbstractionLayer\Pricing\PriceCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class ShippingCostCalculatorServiceTest extends TestCase
{
    private const PRODUCT_ID = 'product-id';
    private const SALES_CHANNEL_ID = 'channel-id';
    private const CURRENCY_ID = 'currency-id';

    private VirtualCartBuilderService&MockObject $cartBuilder;
    private ShippingFallbackService&MockObject $fallbackService;
    private ShippingPriceStore&MockObject $priceStore;
    private ConfigurationService&MockObject $configurationService;
    private AbstractSalesChannelContextFactory&MockObject $contextFactory;
    private ShippingAddressProviderService&MockObject $addressProvider;
    private EntityRepository&MockObject $countryRepository;
    private EntityRepository&MockObject $shippingMethodRepository;
    private LoggerInterface&MockObject $logger;
    private ShippingCostCalculatorService $service;

    protected function setUp(): void
    {
        $this->cartBuilder = $this->createMock(VirtualCartBuilderService::class);
        $this->fallbackService = $this->createMock(ShippingFallbackService::class);
        $this->priceStore = $this->createMock(ShippingPriceStore::class);
        $this->configurationService = $this->createMock(ConfigurationService::class);
        $this->contextFactory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $this->addressProvider = $this->createMock(ShippingAddressProviderService::class);
        $this->countryRepository = $this->createMock(EntityRepository::class);
        $this->shippingMethodRepository = $this->createMock(EntityRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ShippingCostCalculatorService(
            $this->cartBuilder,
            $this->fallbackService,
            $this->priceStore,
            $this->configurationService,
            $this->contextFactory,
            $this->addressProvider,
            $this->countryRepository,
            $this->shippingMethodRepository,
            $this->logger,
        );
    }

    public function testReturnsCachedResultWithoutCalculating(): void
    {
        $cached = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 4.95, 'EUR', false);

        $this->priceStore->method('get')->willReturn($cached);
        $this->cartBuilder->expects($this->never())->method('buildCalculatedCart');

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame($cached, $result);
    }

    public function testSuccessfulCalculationReturnsFalseIsFallback(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(4.95, $result->shippingCost);
    }

    public function testFreeShippingZeroIsValidResult(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Kostenlos', null, null, 0.0),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(0.0, $result->shippingCost);
    }

    public function testExceptionTriggersFallback(): void
    {
        $this->setUpCacheMiss();
        $this->contextFactory->method('create')
            ->willThrowException(new \RuntimeException('DB error'));

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 9.99, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
    }

    public function testExceptionIsLogged(): void
    {
        $this->setUpCacheMiss();
        $this->contextFactory->method('create')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())->method('error');

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 0.0, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);
    }

    public function testNoShippingMethodsTriggersFallback(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([]);

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 4.95, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
    }

    public function testExcludedMethodIsSkipped(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], ['Selbstabholung']);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Selbstabholung Filiale', null, null, 0.0),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testCheapestMethodWins(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Express', null, null, 9.90),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testMethodWithNonMatchingAvailabilityRuleIsSkipped(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext(['rule-1']);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Spedition', 'rule-99', null, 2.00),
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testHeavyProductGetsMatchingWeightTierNotCheapest(): void
    {
        // 15 kg -> muss den 5-30-kg-Tier (19,95) treffen, nicht den 0-5-kg-Tier (4,95).
        // Genau das war der Bug: vorher wurde blind das Minimum über alle Tiers genommen.
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], $this->buildCartWithWeight(15.0));
        $this->setUpShippingMethods([
            $this->buildWeightTieredMethod('Spedition', [
                [0.0, 5.0, 4.95],
                [5.0, 30.0, 19.95],
            ]),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(19.95, $result->shippingCost);
    }

    public function testLightProductGetsLowWeightTier(): void
    {
        // 2 kg -> 0-5-kg-Tier (4,95). Beweist, dass die Band-Auswahl in beide Richtungen greift.
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], $this->buildCartWithWeight(2.0));
        $this->setUpShippingMethods([
            $this->buildWeightTieredMethod('Spedition', [
                [0.0, 5.0, 4.95],
                [5.0, 30.0, 19.95],
            ]),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(4.95, $result->shippingCost);
    }

    public function testProductAboveAllTiersMatchesNoBandAndFallsBack(): void
    {
        // 40 kg liegt über allen definierten Bändern -> kein Tier matcht -> Methode liefert keinen
        // Preis -> Fallback. (Früher hätte sie fälschlich 4,95 zurückgegeben.)
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], $this->buildCartWithWeight(40.0));
        $this->setUpShippingMethods([
            $this->buildWeightTieredMethod('Spedition', [
                [0.0, 5.0, 4.95],
                [5.0, 30.0, 19.95],
            ]),
        ]);

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 29.95, 'EUR', true);
        $this->fallbackService->method('getFallbackResult')->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
    }

    public function testResultIsCached(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Standard', null, null, 4.95),
        ]);

        $this->priceStore->expects($this->once())->method('set');

        $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);
    }

    /**
     * @param array<int, string> $matchedRuleIds
     * @param array<int, string> $excludedMethods
     */
    private function setUpSuccessfulContext(
        array $matchedRuleIds,
        array $excludedMethods = [],
        ?Cart $cart = null,
        string $taxState = CartPrice::TAX_STATE_GROSS,
    ): void
    {
        $currency = $this->createMock(CurrencyEntity::class);
        $currency->method('getId')->willReturn(self::CURRENCY_ID);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getRuleIds')->willReturn($matchedRuleIds);
        $context->method('getCurrency')->willReturn($currency);
        $context->method('getContext')->willReturn(Context::createDefaultContext());

        $this->contextFactory->method('create')->willReturn($context);

        $this->addressProvider->method('getReferenceAddress')
            ->willReturn(new ShippingAddress('DE', 'Kassel', '34117', 'Königsplatz 1'));

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());

        $countryResult = $this->createMock(EntitySearchResult::class);
        $countryResult->method('first')->willReturn($country);
        $this->countryRepository->method('search')->willReturn($countryResult);

        $cart ??= $this->buildCartWithWeight(1.0);

        // Der Steuerzustand steht am **Warenkorb**, nicht am Kontext: Der Kern setzt ihn dort für
        // die Dauer der Berechnung und stellt den Kontext danach zurück. Ein Test, der ihn am
        // Kontext setzte, prüfte eine Quelle, die es so nicht gibt.
        $cart->setPrice(new CartPrice(
            0.0,
            0.0,
            0.0,
            new CalculatedTaxCollection(),
            new TaxRuleCollection(),
            $taxState,
        ));

        $this->cartBuilder->method('buildCalculatedCart')->willReturn($cart);

        $this->configurationService->method('getExcludedShippingMethods')
            ->willReturn($excludedMethods);
    }

    private function setUpCacheMiss(): void
    {
        $this->priceStore->method('get')->willReturn(null);
    }

    /** @param array<int, ShippingMethodEntity> $methods */
    private function setUpShippingMethods(array $methods): void
    {
        $result = $this->createMock(EntitySearchResult::class);
        $result->method('getElements')->willReturn($methods);
        $this->shippingMethodRepository->method('search')->willReturn($result);
    }

    private function buildShippingMethod(
        string $name,
        ?string $availabilityRuleId,
        ?string $priceRuleId,
        float $grossPrice,
    ): ShippingMethodEntity {
        $method = new ShippingMethodEntity();
        $method->setId(Uuid::randomHex());
        $method->setName($name);
        $method->setTranslated(['name' => $name]);
        $method->setActive(true);
        $method->setAvailabilityRuleId($availabilityRuleId);

        $priceEntity = new ShippingMethodPriceEntity();
        $priceEntity->setId(Uuid::randomHex());
        if ($priceRuleId !== null) {
            $priceEntity->setRuleId($priceRuleId);
        }
        $priceEntity->setCurrencyPrice(new PriceCollection([
            new Price(self::CURRENCY_ID, $grossPrice, $grossPrice, false),
        ]));

        $method->setPrices(new ShippingMethodPriceCollection([$priceEntity]));

        return $method;
    }

    /**
     * Baut eine Versandmethode mit mehreren gewichtsbasierten Preis-Tiers.
     *
     * @param array<int, array{0: float, 1: float, 2: float}> $tiers Liste [quantityStart, quantityEnd, brutto]
     */
    private function buildWeightTieredMethod(string $name, array $tiers): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId(Uuid::randomHex());
        $method->setName($name);
        $method->setTranslated(['name' => $name]);
        $method->setActive(true);
        $method->setAvailabilityRuleId(null);

        $prices = [];
        foreach ($tiers as [$start, $end, $brutto]) {
            $priceEntity = new ShippingMethodPriceEntity();
            $priceEntity->setId(Uuid::randomHex());
            $priceEntity->setCalculation(DeliveryCalculator::CALCULATION_BY_WEIGHT);
            $priceEntity->setQuantityStart($start);
            $priceEntity->setQuantityEnd($end);
            $priceEntity->setCurrencyPrice(new PriceCollection([
                new Price(self::CURRENCY_ID, $brutto, $brutto, false),
            ]));
            $prices[] = $priceEntity;
        }

        $method->setPrices(new ShippingMethodPriceCollection($prices));

        return $method;
    }

    /**
     * Baut einen berechneten Warenkorb mit genau einer Lieferposition des angegebenen Gewichts (kg).
     */
    private function buildCartWithWeight(float $weight): Cart
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID, 1);
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, $weight, false));

        $price = new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection());
        $date = new DeliveryDate(new \DateTimeImmutable(), new \DateTimeImmutable());
        $position = new DeliveryPosition('li-1', $lineItem, 1, $price, $date);

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(Uuid::randomHex());

        $delivery = new Delivery(
            new DeliveryPositionCollection([$position]),
            $date,
            $shippingMethod,
            ShippingLocation::createFromCountry($country),
            $price,
        );

        $cart = new Cart('test');
        $cart->add($lineItem);
        $cart->setDeliveries(new DeliveryCollection([$delivery]));

        return $cart;
    }

    /**
     * Ein Warenkorb, aus dem Shopware die Position entfernt hat — so sieht er bei einem
     * Hauptartikel mit Varianten, bei Closeout ohne Bestand und bei Unsichtbarkeit im
     * Berechnungs-Kanal aus.
     */
    private function buildEmptiedCart(): Cart
    {
        return new Cart('test');
    }

    /** Ein Warenkorb mit Position, aber ohne zu versendende Ware — versandkostenfreie Position. */
    private function buildShippingFreeCart(): Cart
    {
        $lineItem = new LineItem('li-1', LineItem::PRODUCT_LINE_ITEM_TYPE, self::PRODUCT_ID, 1);
        $lineItem->setDeliveryInformation(new DeliveryInformation(100, 1.0, true));

        $price = new CalculatedPrice(10.0, 10.0, new CalculatedTaxCollection(), new TaxRuleCollection());
        $date = new DeliveryDate(new \DateTimeImmutable(), new \DateTimeImmutable());
        $position = new DeliveryPosition('li-1', $lineItem, 1, $price, $date);

        $country = new CountryEntity();
        $country->setId(Uuid::randomHex());

        $shippingMethod = new ShippingMethodEntity();
        $shippingMethod->setId(Uuid::randomHex());

        $delivery = new Delivery(
            new DeliveryPositionCollection([$position]),
            $date,
            $shippingMethod,
            ShippingLocation::createFromCountry($country),
            $price,
        );

        $cart = new Cart('test');
        $cart->add($lineItem);
        $cart->setDeliveries(new DeliveryCollection([$delivery]));

        return $cart;
    }

    /**
     * Versandmethode mit zwei regelbasierten Preisen — für die Frage, welcher gewinnt.
     *
     * @param array<int, array{0: string, 1: float}> $rulePrices Liste [ruleId, Preis]
     */
    private function buildRuleBasedMethod(string $name, array $rulePrices): ShippingMethodEntity
    {
        $method = new ShippingMethodEntity();
        $method->setId(Uuid::randomHex());
        $method->setName($name);
        $method->setTranslated(['name' => $name]);
        $method->setActive(true);
        $method->setAvailabilityRuleId(null);

        $prices = [];
        foreach ($rulePrices as [$ruleId, $value]) {
            $priceEntity = new ShippingMethodPriceEntity();
            $priceEntity->setId(Uuid::randomHex());
            $priceEntity->setRuleId($ruleId);
            $priceEntity->setCurrencyPrice(new PriceCollection([
                new Price(self::CURRENCY_ID, $value, $value, false),
            ]));
            $prices[] = $priceEntity;
        }

        $method->setPrices(new ShippingMethodPriceCollection($prices));

        return $method;
    }

    /**
     * Was: Der Artikel lässt sich nicht in den Warenkorb legen.
     * Warum: **Der schwerste Fund.** Shopware entfernt Hauptartikel mit Varianten grundsätzlich
     *        (`ProductCartProcessor::validateParents`), dazu Closeout ohne Bestand und im
     *        Berechnungs-Kanal unsichtbare Ware. Das Plugin rechnete danach mit einem leeren
     *        Warenkorb weiter: Gewicht und Preis sind 0, also traf das billigste Band — und der
     *        erfundene Preis wurde mit `isFallback = false` abgelegt und ausgeliefert. Ein
     *        40-kg-Artikel bekam so den 0-5-kg-Preis, ohne Meldung, ohne Kennzeichnung.
     * Erwartet: Ersatzwert mit der Begründung „nicht bestellbar" — nicht der billigste Tarif.
     */
    public function testProductRemovedFromCartYieldsFallbackInsteadOfCheapestTier(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], $this->buildEmptiedCart());
        $this->setUpShippingMethods([
            $this->buildWeightTieredMethod('Spedition', [
                [0.0, 5.0, 4.95],
                [5.0, 30.0, 19.95],
            ]),
        ]);

        $fallback = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 29.95, 'EUR', true, FallbackReason::NotPurchasable);
        $this->fallbackService->expects(self::once())
            ->method('getFallbackResult')
            ->with(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID, 'EUR', FallbackReason::NotPurchasable)
            ->willReturn($fallback);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertTrue($result->isFallback);
        self::assertSame(FallbackReason::NotPurchasable, $result->fallbackReason);
    }

    /**
     * Was: Ein Warenkorb, in dem nur versandkostenfreie Ware liegt.
     * Warum: Auch hier waren früher alle Kennwerte 0 und das billigste Band traf. Der Kern setzt
     *        für diesen Fall 0,00 € an — das ist ein Ergebnis, kein Ersatzwert.
     * Erwartet: 0,00 € als gerechneter Wert.
     */
    public function testShippingFreeCartCostsNothingInsteadOfTheCheapestTier(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], $this->buildShippingFreeCart());
        $this->setUpShippingMethods([
            $this->buildWeightTieredMethod('Spedition', [
                [0.0, 5.0, 4.95],
                [5.0, 30.0, 19.95],
            ]),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertFalse($result->isFallback);
        self::assertSame(0.0, $result->shippingCost);
    }

    /**
     * Was: Zwei Regeln passen, mit unterschiedlichen Preisen.
     * Warum: Der Kern geht die Regeln des Kontexts der Reihe nach durch — sie sind nach
     *        Priorität absteigend sortiert — und bricht bei der ersten mit passendem Preis ab.
     *        Das Plugin nahm den billigsten über alle Regeln hinweg. Bei „Sperrgut" (hohe
     *        Priorität, 49,90 €) neben „Standardkunde" (niedrige, 5,90 €) verlangte die Kasse
     *        49,90 € und der Feed nannte 5,90 €.
     * Erwartet: 49,90 € — der Preis der höher priorisierten Regel.
     */
    public function testHigherPriorityRuleWinsEvenWhenItIsMoreExpensive(): void
    {
        $this->setUpCacheMiss();
        // Die Reihenfolge dieser Liste ist die Prioritätsreihenfolge des Kerns.
        $this->setUpSuccessfulContext(['rule-sperrgut', 'rule-standard']);
        $this->setUpShippingMethods([
            $this->buildRuleBasedMethod('Spedition', [
                ['rule-standard', 5.90],
                ['rule-sperrgut', 49.90],
            ]),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(49.90, $result->shippingCost);
    }

    /**
     * Was: Innerhalb **einer** Regel gibt es zwei passende Preise.
     * Warum: Hier gewinnt sehr wohl der günstigere — auch das ist der Kern
     *        (`getMatchingPriceOfRule` sortiert aufsteigend und nimmt den ersten Treffer). Ohne
     *        diesen Test läse sich die Korrektur oben als „teuer gewinnt immer".
     * Erwartet: der günstigere Preis derselben Regel.
     */
    public function testWithinOneRuleTheCheaperPriceWins(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext(['rule-standard']);
        $this->setUpShippingMethods([
            $this->buildRuleBasedMethod('Spedition', [
                ['rule-standard', 12.90],
                ['rule-standard', 7.90],
            ]),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID);

        self::assertSame(7.90, $result->shippingCost);
    }

    /**
     * Was: Ein Land ohne Steuer — etwa die Schweiz.
     * Warum: Das Plugin nahm immer den Bruttopreis. Der Kern nimmt bei `TAX_STATE_FREE` und
     *        `TAX_STATE_NET` den Nettopreis. Für die Schweiz — ein voreingestelltes Land —
     *        nannte der Feed damit einen höheren Betrag, als die Kasse verlangt.
     * Erwartet: der Nettopreis.
     */
    public function testTaxFreeCountryUsesTheNetPrice(): void
    {
        $this->setUpCacheMiss();
        $this->setUpSuccessfulContext([], [], null, CartPrice::TAX_STATE_FREE);

        $method = new ShippingMethodEntity();
        $method->setId(Uuid::randomHex());
        $method->setName('Standard');
        $method->setTranslated(['name' => 'Standard']);
        $method->setActive(true);

        $priceEntity = new ShippingMethodPriceEntity();
        $priceEntity->setId(Uuid::randomHex());
        $priceEntity->setCurrencyPrice(new PriceCollection([
            new Price(self::CURRENCY_ID, 25.13, 29.90, false),
        ]));
        $method->setPrices(new ShippingMethodPriceCollection([$priceEntity]));

        $this->setUpShippingMethods([$method]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'CH', self::SALES_CHANNEL_ID);

        self::assertSame(25.13, $result->shippingCost);
    }

    /**
     * Was: Der Warmup-Weg.
     * Warum: **Der zweitschwerste Fund.** Rief der Warmup `calculate()` mit Speicher-Kurzschluss
     *        auf, füllte er nur Lücken, statt zu erneuern — ein Eintrag wurde erst neu gerechnet,
     *        nachdem er verfallen war. Bei sechs Stunden Takt und 24 Stunden Haltbarkeit lief der
     *        Feed dadurch täglich rund sechs Stunden komplett auf dem Ersatzwert.
     * Erwartet: Es wird gerechnet, obwohl ein gültiger Wert im Speicher liegt.
     */
    public function testRecalculationIgnoresTheStoredValue(): void
    {
        $stored = new ShippingCalculationResult(self::PRODUCT_ID, 'DE', 4.95, 'EUR', false);
        $this->priceStore->method('get')->willReturn($stored);

        $this->setUpSuccessfulContext([]);
        $this->setUpShippingMethods([
            $this->buildShippingMethod('Standard', null, null, 19.95),
        ]);

        $result = $this->service->calculate(self::PRODUCT_ID, 'DE', self::SALES_CHANNEL_ID, useStoredValue: false);

        self::assertSame(19.95, $result->shippingCost);
    }
}

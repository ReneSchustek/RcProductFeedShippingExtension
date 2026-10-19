<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcProductFeedShippingExtension\Service\CalculationContextBuilder;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ShippingAddressProviderService;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\Country\CountryCollection;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Der Kontext, in dem der Rechner einen Warenkorb für ein Zielland bewertet.
 */
#[CoversClass(CalculationContextBuilder::class)]
final class CalculationContextBuilderTest extends TestCase
{
    /**
     * Was: Kontext für Deutschland, zweimal für denselben Kanal.
     * Warum: Die Fabrik ist teuer; je Kanal genügt ein Basiskontext, der je Berechnung geklont wird. Ohne die
     *        Referenzadresse griffen Regeln auf die Postleitzahl nicht.
     * Erwartet: eine Fabrik-Anfrage, zwei verschiedene Kontexte mit Land, Postleitzahl und Kunde.
     */
    public function testTheContextCarriesTheReferenceAddressAndIsBuiltOncePerChannel(): void
    {
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->expects(self::once())->method('create')->willReturn($this->createStub(SalesChannelContext::class));

        $builder = $this->builder($factory, $this->country('DE'));
        $first = $builder->contextFor('kanal', 'de');
        $second = $builder->contextFor('kanal', 'DE');

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertNotSame($first, $second);

        $location = (new \ReflectionProperty(SalesChannelContext::class, 'shippingLocation'))->getValue($first);
        self::assertInstanceOf(ShippingLocation::class, $location);
        self::assertSame('DE', $location->getCountry()->getIso());

        $customer = (new \ReflectionProperty(SalesChannelContext::class, 'customer'))->getValue($first);
        self::assertInstanceOf(CustomerEntity::class, $customer);
        self::assertSame('34117', $customer->getActiveShippingAddress()?->getZipcode());
    }

    /**
     * Was: Ein Land ohne Referenzadresse und ein Land, das in der Datenbank fehlt.
     * Warum: Beides ist kein Absturz, sondern ein Fall für den Ersatzwert; der Rechner braucht dafür `null`.
     * Erwartet: `null` in beiden Fällen.
     */
    public function testWithoutAddressOrCountryThereIsNoContext(): void
    {
        $factory = $this->createStub(AbstractSalesChannelContextFactory::class);
        $factory->method('create')->willReturn($this->createStub(SalesChannelContext::class));

        self::assertNull($this->builder($factory, $this->country('DE'))->contextFor('kanal', 'XX'));
        self::assertNull($this->builder($factory, null)->contextFor('kanal', 'DE'));
    }

    /**
     * Was: Nach dem Zurücksetzen wird der Basiskontext neu gebaut.
     * Warum: Ein langlebiger Arbeiter rechnete sonst mit Einstellungen aus dem Lauf davor.
     * Erwartet: zwei Fabrik-Anfragen.
     */
    public function testResetBuildsTheBaseContextAgain(): void
    {
        $factory = $this->createMock(AbstractSalesChannelContextFactory::class);
        $factory->expects(self::exactly(2))->method('create')->willReturn($this->createStub(SalesChannelContext::class));

        $builder = $this->builder($factory, $this->country('DE'));
        $builder->contextFor('kanal', 'DE');
        $builder->reset();
        $builder->contextFor('kanal', 'DE');
    }

    private function builder(AbstractSalesChannelContextFactory $factory, ?CountryEntity $country): CalculationContextBuilder
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturnCallback(static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
            'country',
            $country === null ? 0 : 1,
            new CountryCollection($country === null ? [] : [$country]),
            null,
            $criteria,
            $context,
        ));

        return new CalculationContextBuilder($factory, new ShippingAddressProviderService(), $repository, new NullLogger());
    }

    private function country(string $iso): CountryEntity
    {
        $country = new CountryEntity();
        $country->setId(str_repeat('a', 32));
        $country->setUniqueIdentifier(str_repeat('a', 32));
        $country->setIso($iso);

        return $country;
    }
}

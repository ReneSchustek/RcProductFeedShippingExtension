<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Prüft ob SalesChannelContext die per Reflection beschriebenen Properties noch besitzt.
 *
 * ShippingCostCalculatorService::injectShippingLocation() setzt shippingLocation und customer
 * per Reflection, weil SalesChannelContext keine public Setter dafür anbietet. Shopware
 * könnte diese Properties in einem Minor Release umbenennen — dieser Test schlägt dann
 * sofort fehl und macht das Problem sichtbar, bevor Produktion bricht.
 */
final class SalesChannelContextContractTest extends TestCase
{
    public function testShippingLocationPropertyExists(): void
    {
        $ref = new \ReflectionClass(SalesChannelContext::class);

        $this->assertTrue(
            $ref->hasProperty('shippingLocation'),
            'SalesChannelContext::$shippingLocation existiert nicht mehr — injectShippingLocation() in ShippingCostCalculatorService muss angepasst werden.'
        );
    }

    public function testCustomerPropertyExists(): void
    {
        $ref = new \ReflectionClass(SalesChannelContext::class);

        $this->assertTrue(
            $ref->hasProperty('customer'),
            'SalesChannelContext::$customer existiert nicht mehr — injectShippingLocation() in ShippingCostCalculatorService muss angepasst werden.'
        );
    }

    public function testShippingLocationPropertyIsWritable(): void
    {
        $ref = new \ReflectionClass(SalesChannelContext::class);
        $prop = $ref->getProperty('shippingLocation');

        $this->assertFalse(
            $prop->isReadOnly(),
            'SalesChannelContext::$shippingLocation ist jetzt readonly — Reflection-Schreiben nicht mehr möglich. injectShippingLocation() muss angepasst werden.'
        );
    }

    public function testCustomerPropertyIsWritable(): void
    {
        $ref = new \ReflectionClass(SalesChannelContext::class);
        $prop = $ref->getProperty('customer');

        $this->assertFalse(
            $prop->isReadOnly(),
            'SalesChannelContext::$customer ist jetzt readonly — Reflection-Schreiben nicht mehr möglich. injectShippingLocation() muss angepasst werden.'
        );
    }
}

<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedChannelProviderService;
use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Content\ProductExport\ProductExportEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Die Antwort auf „welche Kanäle zählen" — die Größe, an der die halbe Arbeitsmenge hängt.
 *
 * Warum das getestet gehört: Der Produktexport trägt **zwei** Kanäle. Wer den falschen nimmt, wärmt
 * einen Kanal vor, den der Warenstrom nie ausliest, und liest zur Laufzeit einen, der nie gewärmt
 * wurde. Beides ist einzeln richtig und zusammen wirkungslos — und beides fällt erst am kalten
 * Warenstrom auf.
 */
class FeedChannelProviderServiceTest extends TestCase
{
    private EntityRepository&MockObject $productExportRepository;

    protected function setUp(): void
    {
        $this->productExportRepository = $this->createMock(EntityRepository::class);
    }

    /**
     * Was: Ein Export mit Vergleichs-Kanal „Google Shopping" und Storefront-Kanal „Trummer".
     * Warum: Der Kern baut den Rendering-Kontext aus `storefrontSalesChannelId`
     *        (`ProductExportGenerator`, 6.7.13.0) — genau diesen Kanal bekommt der Subscriber zu
     *        sehen, und genau für ihn muss vorgewärmt sein.
     * Erwartet: der Storefront-Kanal, nicht der Vergleichs-Kanal.
     */
    public function testTheStorefrontChannelCountsNotTheComparisonChannel(): void
    {
        $this->givenExports([['storefront' => 'trummer', 'comparison' => 'google']]);

        $channelIds = $this->createService()->loadReadingChannelIds(Context::createDefaultContext());

        self::assertSame(['trummer' => true], $channelIds);
    }

    /**
     * Was: Drei Exporte auf denselben Storefront-Kanal.
     * Warum: Auf live-clone sind es genau drei — Produktivlauf, Rauchprobe und Vergleich. Der
     *        Aufrufer schlägt je Kombination nach; eine Liste mit Dubletten wäre die falsche Form.
     * Erwartet: eine Kennung, einmal.
     */
    public function testTheSameChannelAppearsOnlyOnce(): void
    {
        $this->givenExports([
            ['storefront' => 'trummer', 'comparison' => 'google'],
            ['storefront' => 'trummer', 'comparison' => 'google'],
            ['storefront' => 'trummer', 'comparison' => 'google'],
        ]);

        self::assertSame(['trummer' => true], $this->createService()->loadReadingChannelIds(Context::createDefaultContext()));
    }

    /**
     * Was: Kein einziger Produktexport.
     * Warum: Dann gibt es nichts vorzuwärmen. Ein Warmup, der hier trotzdem loslegte, rechnete
     *        stundenlang für einen Speicher, den niemand ausliest.
     * Erwartet: leere Menge.
     */
    public function testWithoutAnyExportNoChannelCounts(): void
    {
        $this->givenExports([]);

        self::assertSame([], $this->createService()->loadReadingChannelIds(Context::createDefaultContext()));
    }

    /** @param array<int, array{storefront: string, comparison: string}> $exports */
    private function givenExports(array $exports): void
    {
        $entities = [];
        foreach ($exports as $index => $export) {
            $entity = new ProductExportEntity();
            $entity->setUniqueIdentifier('export-' . $index);
            $entity->setId('export-' . $index);
            $entity->setSalesChannelId($export['comparison']);
            $entity->setStorefrontSalesChannelId($export['storefront']);
            $entities['export-' . $index] = $entity;
        }

        $ergebnis = $this->createMock(EntitySearchResult::class);
        $ergebnis->method('getEntities')->willReturn(new ProductExportCollection($entities));
        $this->productExportRepository->method('search')->willReturn($ergebnis);
    }

    private function createService(): FeedChannelProviderService
    {
        return new FeedChannelProviderService($this->productExportRepository);
    }
}

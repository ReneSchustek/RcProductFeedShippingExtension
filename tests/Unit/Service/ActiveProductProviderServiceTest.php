<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Service\ActiveProductProviderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;

class ActiveProductProviderServiceTest extends TestCase
{
    private EntityRepository&MockObject $productRepository;
    private ActiveProductProviderService $service;

    protected function setUp(): void
    {
        $this->productRepository = $this->createMock(EntityRepository::class);
        $this->service = new ActiveProductProviderService($this->productRepository);
    }

    /**
     * Der Kern der Sache: Eine Variante erbt ihr `active` vom Elternartikel und hat in der eigenen
     * Spalte NULL. Ein bloßes `EqualsFilter('active', true)` sieht sie deshalb nicht — auf
     * live-clone waren das 1970 von 2739 Produkten. Wer diesen Filter verengt, verkleinert
     * stillschweigend die Datenbasis von Warmup und Diagnose.
     */
    public function testVariantenMitGeerbtemActiveWerdenMitgeladen(): void
    {
        $criteria = $this->captureCriteria();

        $this->service->loadActiveProductIds(Context::createDefaultContext());

        $filters = $criteria->getFilters();
        self::assertCount(1, $filters);

        $orFilter = $filters[0];
        self::assertInstanceOf(MultiFilter::class, $orFilter);
        self::assertSame(MultiFilter::CONNECTION_OR, $orFilter->getOperator());

        $queries = $orFilter->getQueries();
        self::assertCount(2, $queries);

        self::assertEquals(new EqualsFilter('active', true), $queries[0]);
        self::assertEquals(new MultiFilter(MultiFilter::CONNECTION_AND, [
            new EqualsFilter('active', null),
            new EqualsFilter('parent.active', true),
        ]), $queries[1]);
    }

    public function testGefundeneIdsWerdenDurchgereicht(): void
    {
        $this->captureCriteria(['0af1', '0af2']);

        $ids = $this->service->loadActiveProductIds(Context::createDefaultContext());

        self::assertSame(['0af1', '0af2'], $ids);
    }

    /**
     * Hält die Criteria fest, mit der der Dienst sucht, und beantwortet die Suche mit den
     * übergebenen IDs.
     *
     * @param array<int, string> $ids
     */
    private function captureCriteria(array $ids = []): Criteria
    {
        $criteria = new Criteria();

        $this->productRepository
            ->method('searchIds')
            ->willReturnCallback(
                function (Criteria $passed, Context $context) use ($criteria, $ids): IdSearchResult {
                    foreach ($passed->getFilters() as $filter) {
                        $criteria->addFilter($filter);
                    }

                    return new IdSearchResult(
                        count($ids),
                        array_map(static fn (string $id): array => ['primaryKey' => $id, 'data' => []], $ids),
                        $passed,
                        $context,
                    );
                },
            );

        return $criteria;
    }
}

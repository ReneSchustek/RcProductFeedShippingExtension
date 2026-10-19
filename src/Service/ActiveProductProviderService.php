<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Shopware\Core\Content\Product\ProductCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;

/**
 * Liefert die Produkte, die im Shop sichtbar sind, einschließlich Varianten, die ihr `active`
 * vom Elternartikel erben.
 *
 * Ein bloßes `EqualsFilter('active', true)` vergleicht die Spalte und sieht bei einer Variante mit
 * `active = NULL` nichts, und in einem Sortiment mit Varianten sind das oft die meisten Produkte.
 * Ein Warmup, der die falschen Produkte wärmt, ist genauso wirkungslos wie gar keiner, meldet aber
 * Erfolg, und eine Diagnose, die dieselbe Verengung wiederholt, meldet einen Bruchteil der
 * Wirklichkeit als Ganzes.
 *
 * Die Regel steht deshalb an genau einer Stelle: Warmup und Diagnose müssen dieselbe Menge sehen,
 * sonst widersprechen sich ihre Zahlen. Abfragen in SQL nutzen {@see self::SQL_ACTIVE_OR_INHERITED}.
 *
 * Bewusst nicht `final`: Tests ersetzen diese Klasse durch ein Test-Double, und PHPUnit kann finale
 * Klassen nicht nachbilden.
 */
class ActiveProductProviderService
{
    /**
     * Dieselbe Regel für Abfragen in SQL. Erwartet den Artikel als `p` und seinen Elternartikel als `parent`.
     */
    public const SQL_ACTIVE_OR_INHERITED = 'COALESCE(p.active, parent.active) = 1';

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(private readonly EntityRepository $productRepository)
    {
    }

    /** @return array<int, string> */
    public function loadActiveProductIds(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
            new EqualsFilter('active', true),
            new MultiFilter(MultiFilter::CONNECTION_AND, [
                new EqualsFilter('active', null),
                new EqualsFilter('parent.active', true),
            ]),
        ]));

        /** @var array<int, string> $ids */
        $ids = $this->productRepository->searchIds($criteria, $context)->getIds();

        return $ids;
    }
}

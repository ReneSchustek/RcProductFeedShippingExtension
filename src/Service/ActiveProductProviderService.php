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
 * Liefert die Produkte, die im Shop sichtbar sind — **einschließlich Varianten, die ihr `active`
 * vom Elternartikel erben.**
 *
 * Ein bloßes `EqualsFilter('active', true)` vergleicht die Spalte und sieht bei einer Variante mit
 * `active = NULL` nichts. Auf `live-clone` sind das 1970 von 2739 Produkten. Ein Warmup, der die
 * falschen Produkte wärmt, ist genauso wirkungslos wie gar keiner, meldet aber Erfolg — und eine
 * Diagnose, die dieselbe Verengung wiederholt, meldet ein Viertel der Wirklichkeit als Ganzes.
 *
 * Die Abfrage steht deshalb an genau einer Stelle: Warmup und Diagnose müssen dieselbe Menge sehen,
 * sonst widersprechen sich ihre Zahlen.
 */
class ActiveProductProviderService
{
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

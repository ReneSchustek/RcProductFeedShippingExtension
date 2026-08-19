<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Service;

use Shopware\Core\Content\ProductExport\ProductExportCollection;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;

/**
 * Beantwortet die eine Frage, an der die Arbeitsmenge des Plugins hängt:
 * **Welche Verkaufskanäle liest überhaupt ein Warenstrom aus?**
 *
 * Warum das eine eigene Klasse wert ist: Bis zum 2026-08-19 wärmte der Warmup jeden aktiven Kanal
 * mit eingeschaltetem Plugin vor — und eingeschaltet ist es standardmäßig überall. Auf live-clone
 * traf das den Kanal „Headless", der 20 statt 206 Versandarten trägt. Ergebnis: 5223 Einträge
 * „keine Versandart" für einen Kanal, den kein Warenstrom ausliest. Sie waren nicht nur nutzlos,
 * sie haben die Fehlersuche zweimal in die falsche Richtung geschickt — fünf Sechstel aller
 * Meldungen kamen aus diesem Kanal.
 *
 * Maßgeblich ist der **Storefront-Kanal** des Produktexports, nicht sein Vergleichs-Kanal: Der Kern
 * baut den Rendering-Kontext aus `storefrontSalesChannelId` (`ProductExportGenerator`, 6.7.13.0),
 * und genau diesen Kanal bekommt der `ProductFeedSubscriber` zu sehen.
 */
class FeedChannelProviderService
{
    /**
     * @param EntityRepository<ProductExportCollection> $productExportRepository
     */
    public function __construct(private readonly EntityRepository $productExportRepository)
    {
    }

    /**
     * Liefert die Kennungen der Kanäle, für die ein Produktexport Versandpreise abruft.
     *
     * Als Menge (Kennung => true), weil die Aufrufer damit nur nachschlagen — bei einem Warmup über
     * mehrere tausend Kombinationen ist `in_array` über eine Liste die falsche Zugriffsart.
     *
     * @return array<string, true>
     */
    public function loadReadingChannelIds(Context $context): array
    {
        // Erst suchen, dann durchlaufen: Steht der Repository-Aufruf im Kopf der Schleife, meldet
        // die Shopware-Regel für PHPStan eine Abfrage in der Schleife. Sie liegt sachlich falsch —
        // der Kopf wird einmal ausgewertet —, aber die getrennte Zeile liest sich ohnehin besser.
        $exports = $this->productExportRepository->search(new Criteria(), $context)->getEntities();

        $channelIds = [];

        foreach ($exports as $export) {
            $channelIds[$export->getStorefrontSalesChannelId()] = true;
        }

        return $channelIds;
    }
}

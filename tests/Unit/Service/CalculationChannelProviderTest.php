<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcProductFeedShippingExtension\Configuration\ConfigurationService;
use Ruhrcoder\RcProductFeedShippingExtension\Service\CalculationChannelProvider;
use Ruhrcoder\RcProductFeedShippingExtension\Service\FeedChannelProviderService;
use Ruhrcoder\RcProductFeedShippingExtension\Struct\CalculationChannelSelection;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\Context\AbstractSalesChannelContextFactory;
use Shopware\Core\System\SalesChannel\SalesChannelCollection;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SalesChannel\SalesChannelEntity;

/**
 * Welche Berechnungs-Kanäle zählen: eine Regel für Warmup, Fortschrittsanzeige und Diagnose.
 */
#[CoversClass(CalculationChannelProvider::class)]
#[CoversClass(CalculationChannelSelection::class)]
final class CalculationChannelProviderTest extends TestCase
{
    /**
     * Was: Sechs Kanäle, je einer pro Fall: zählt, ausgeschaltet, ohne Export, ohne Länder, doppelter
     *      Berechnungs-Kanal, ohne baubaren Kontext.
     * Warum: Jeder Fall entscheidet über die Zahlen von Warmup und Diagnose; weichen sie ab, widersprechen sich beide.
     * Erwartet: ein Kanal zählt, drei fallen mit Grund weg, zwei still.
     */
    public function testEachRuleDecidesOnce(): void
    {
        $channels = ['ok' => 'Shop', 'disabled' => 'Aus', 'unread' => 'Vergleich', 'noCountries' => 'Leer', 'duplicate' => 'Zweitshop', 'broken' => 'Defekt'];

        $configuration = $this->createStub(ConfigurationService::class);
        $configuration->method('isEnabled')->willReturnCallback(static fn (string $id): bool => $id !== 'disabled');
        $configuration->method('getCountries')->willReturnCallback(static fn (string $id): array => $id === 'noCountries' ? [] : ['DE']);
        $configuration->method('getCalculationSalesChannelId')->willReturnCallback(static fn (string $id): ?string => match ($id) {
            'duplicate' => 'ok',
            'broken' => 'brokenCalc',
            default => null,
        });

        $feeds = $this->createStub(FeedChannelProviderService::class);
        $feeds->method('loadReadingChannelIds')->willReturn(array_fill_keys(['ok', 'disabled', 'noCountries', 'duplicate', 'broken'], true));

        $factory = $this->createStub(AbstractSalesChannelContextFactory::class);
        $factory->method('create')->willReturnCallback(function (string $token, string $salesChannelId): SalesChannelContext {
            if ($salesChannelId === 'brokenCalc') {
                throw new \RuntimeException('Sprache fehlt');
            }

            return $this->createStub(SalesChannelContext::class);
        });

        $selection = (new CalculationChannelProvider($configuration, $factory, $this->repository($channels), $feeds))
            ->select(new Context(new SystemSource()));

        self::assertSame(['ok' => ['name' => 'Shop', 'countries' => ['DE']]], $selection->channels());
        self::assertSame(
            [
                ['name' => 'Vergleich', 'reason' => CalculationChannelSelection::SKIP_UNREAD, 'calcChannelId' => '', 'detail' => ''],
                ['name' => 'Zweitshop', 'reason' => CalculationChannelSelection::SKIP_DUPLICATE, 'calcChannelId' => 'ok', 'detail' => ''],
                ['name' => 'Defekt', 'reason' => CalculationChannelSelection::SKIP_NO_CONTEXT, 'calcChannelId' => 'brokenCalc', 'detail' => 'Sprache fehlt'],
            ],
            $selection->skipped(),
        );
    }

    /**
     * @param array<string, string> $channels Kennung => Name
     *
     * @return EntityRepository<SalesChannelCollection>
     */
    private function repository(array $channels): EntityRepository
    {
        $entities = [];
        foreach ($channels as $id => $name) {
            $channel = new SalesChannelEntity();
            $channel->setId($id);
            $channel->setUniqueIdentifier($id);
            $channel->setName($name);
            $entities[] = $channel;
        }

        $repository = $this->createStub(EntityRepository::class);
        $repository->method('search')->willReturnCallback(static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
            'sales_channel',
            \count($entities),
            new SalesChannelCollection($entities),
            null,
            $criteria,
            $context,
        ));

        return $repository;
    }
}

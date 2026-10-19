<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

/**
 * Die Berechnungs-Kanäle eines Laufs und die Kanäle, die mit Grund wegfallen.
 *
 * Die Gründe stehen als Kennung da, nicht als Satz: Warmup und Diagnose melden sie verschieden (Protokoll und Konsole
 * gegen Konsole allein), und jeder formuliert für seinen Ort.
 */
final class CalculationChannelSelection
{
    public const SKIP_UNREAD = 'unread';
    public const SKIP_DUPLICATE = 'duplicate';
    public const SKIP_NO_CONTEXT = 'no_context';

    /** @var array<string, array{name: string, countries: array<int, string>}> je Berechnungs-Kanal */
    private array $channels = [];

    /** @var list<array{name: string, reason: string, calcChannelId: string, detail: string}> */
    private array $skipped = [];

    /**
     * @param array<int, string> $countries
     */
    public function add(string $calcChannelId, string $name, array $countries): void
    {
        $this->channels[$calcChannelId] = ['name' => $name, 'countries' => $countries];
    }

    public function has(string $calcChannelId): bool
    {
        return isset($this->channels[$calcChannelId]);
    }

    public function skip(string $name, string $reason, string $calcChannelId = '', string $detail = ''): void
    {
        $this->skipped[] = ['name' => $name, 'reason' => $reason, 'calcChannelId' => $calcChannelId, 'detail' => $detail];
    }

    /**
     * @return array<string, array{name: string, countries: array<int, string>}>
     */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * @return list<array{name: string, reason: string, calcChannelId: string, detail: string}>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }
}

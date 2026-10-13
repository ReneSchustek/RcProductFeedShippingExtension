<?php

declare(strict_types=1);

namespace Ruhrcoder\RcProductFeedShippingExtension\Struct;

/**
 * Zählwerk eines Warmup-Laufs.
 *
 * Der Unterschied zwischen `berechnet` und `ersatzwert` ist die eigentliche Aussage: Ein Lauf, der
 * durchläuft und dabei ausschließlich Ersatzwerte einträgt, ist kein erfolgreicher Lauf. Genau das
 * war lange der Zustand, ohne dass es irgendwo sichtbar wurde.
 */
final class WarmupResult
{
    public int $calculated = 0;

    public int $fallback = 0;

    /**
     * Kombinationen, deren Prüfwert unverändert war und die deshalb nicht neu gerechnet wurden.
     *
     * Sie sind **kein** Ausfall, sondern der Zweck der Übung: nachgesehen, nichts geändert, alter
     * Wert bleibt gültig. Ihr Zeitstempel wird aufgefrischt, damit der Eintrag nicht verfällt.
     */
    public int $unchanged = 0;

    /** @var array<int, string> Kanäle, die übersprungen wurden, samt Grund */
    public array $skippedChannels = [];

    public function record(bool $isFallback): void
    {
        if ($isFallback) {
            ++$this->fallback;

            return;
        }

        ++$this->calculated;
    }

    public function recordUnchanged(int $anzahl = 1): void
    {
        $this->unchanged += $anzahl;
    }

    public function channelSkipped(string $channelName, string $reason): void
    {
        $this->skippedChannels[] = $channelName . ': ' . $reason;
    }

    public function total(): int
    {
        return $this->calculated + $this->fallback;
    }

    /** Alles, was der Lauf angesehen hat — gerechnet, ersetzt oder als unverändert erkannt. */
    public function inspected(): int
    {
        return $this->total() + $this->unchanged;
    }

    /**
     * Ein Lauf gilt als auffällig, wenn er nichts gerechnet hat, obwohl es etwas zu rechnen gab.
     * Dann stimmt etwas Grundsätzliches — Berechnungs-Kanal, Versandmethoden oder Regeln.
     */
    public function fallbacksOnly(): bool
    {
        return $this->total() > 0 && $this->calculated === 0;
    }
}

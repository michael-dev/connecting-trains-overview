<?php
declare(strict_types=1);

namespace Bahn;

/**
 * One train event (arrival or departure) at a station, with planned and
 * (when known) live-adjusted time and platform.
 */
final class Train
{
    public function __construct(
        public readonly string $category,        // e.g. ICE, RE, RB, S
        public readonly string $number,          // train number
        public readonly string $line,            // line label, e.g. RE1 (may be empty)
        public readonly ?\DateTimeImmutable $planned,
        public readonly ?\DateTimeImmutable $expected,
        public readonly string $plannedPlatform,
        public readonly string $changedPlatform,
        /** @var string[] ordered list of stops (origin for arrivals, destination for departures) */
        public readonly array $path,
        public readonly bool $cancelled = false,
        /** @var string[] names of coupled wing trains sharing this departure */
        public readonly array $wings = [],
        /** @var string[] human-readable delay causes (translated message codes) */
        public readonly array $reasons = []
    ) {
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /** Display label including any coupled wing trains, e.g. "ICE 2592 / ICE 2840". */
    public function fullName(): string
    {
        return $this->wings === []
            ? $this->name()
            : $this->name() . ' / ' . implode(' / ', $this->wings);
    }

    public function name(): string
    {
        $label = $this->line !== '' ? $this->line : trim($this->category . ' ' . $this->number);
        return $label !== '' ? $label : 'Zug ' . $this->number;
    }

    public function platform(): string
    {
        return $this->changedPlatform !== '' ? $this->changedPlatform : $this->plannedPlatform;
    }

    /** True if the track was switched (a known planned platform differs from the changed one). */
    public function platformChanged(): bool
    {
        return $this->changedPlatform !== ''
            && $this->plannedPlatform !== ''
            && $this->changedPlatform !== $this->plannedPlatform;
    }

    public function time(): ?\DateTimeImmutable
    {
        return $this->expected ?? $this->planned;
    }

    /** Delay in whole minutes vs. the planned time, or null if unknown. */
    public function delayMinutes(): ?int
    {
        if ($this->planned === null || $this->expected === null) {
            return null;
        }
        return (int) round(($this->expected->getTimestamp() - $this->planned->getTimestamp()) / 60);
    }

    /** First stop of the path (origin for an arriving train). */
    public function origin(): string
    {
        return $this->path[0] ?? '';
    }

    /** Last stop of the path (final destination for a departing train). */
    public function destination(): string
    {
        return $this->path === [] ? '' : (string) $this->path[array_key_last($this->path)];
    }
}

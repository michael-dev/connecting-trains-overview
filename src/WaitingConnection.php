<?php
declare(strict_types=1);

namespace Bahn;

/**
 * A maintained/decided connection: an outbound train and the inbound train
 * the connection relates to, plus the connection status reported by DB.
 */
final class WaitingConnection
{
    public const STATUS_WAITING     = 'w'; // outbound train waits for the inbound train
    public const STATUS_NOT_WAITING = 'n'; // connection broken / no longer waiting
    public const STATUS_ALTERNATIVE = 'a'; // an alternative connection is offered

    public function __construct(
        public readonly Train $outbound,
        public readonly Train $inbound,
        public readonly string $status
    ) {
    }

    public function isWaiting(): bool
    {
        return $this->status === self::STATUS_WAITING;
    }

    /** Short badge text. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING     => 'wartet',
            self::STATUS_NOT_WAITING => 'wartet nicht',
            self::STATUS_ALTERNATIVE => 'Alternative',
            default                  => $this->status,
        };
    }

    /** Phrase placed between the outbound and inbound train. */
    public function relationLabel(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING     => 'wartet auf',
            self::STATUS_NOT_WAITING => 'wartet nicht (mehr) auf',
            self::STATUS_ALTERNATIVE => 'Alternative zu',
            default                  => $this->status,
        };
    }

    /** Glyph that conveys the status at a glance. */
    public function statusIcon(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING     => '⟵',
            self::STATUS_NOT_WAITING => '✕',
            self::STATUS_ALTERNATIVE => '↷',
            default                  => '·',
        };
    }

    /** CSS modifier class used by the web UI. */
    public function statusClass(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING     => 'wait',
            self::STATUS_NOT_WAITING => 'broken',
            self::STATUS_ALTERNATIVE => 'alt',
            default                  => 'unknown',
        };
    }

    /** One-sentence explanation of what the status means for a traveller. */
    public function statusSentence(): string
    {
        return match ($this->status) {
            self::STATUS_WAITING     => 'Die Abfahrt wartet auf diesen ankommenden Zug — der Anschluss wird gehalten.',
            self::STATUS_NOT_WAITING => 'Der Anschluss wird nicht gehalten — die Abfahrt wartet nicht (mehr) auf diesen Zug.',
            self::STATUS_ALTERNATIVE => 'Ersatz-/Alternativanschluss, falls der ursprüngliche Anschluss nicht klappt.',
            default                  => '',
        };
    }

    /** Ordering: held connections first, then alternatives, then broken. */
    public function sortPriority(): int
    {
        return match ($this->status) {
            self::STATUS_WAITING     => 0,
            self::STATUS_ALTERNATIVE => 1,
            self::STATUS_NOT_WAITING => 2,
            default                  => 3,
        };
    }
}

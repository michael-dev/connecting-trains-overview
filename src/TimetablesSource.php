<?php
declare(strict_types=1);

namespace Bahn;

/** Abstraction over the DB Timetables API, so the finder can be tested. */
interface TimetablesSource
{
    public function resolveEva(string $pattern): ?int;

    /** $date = YYMMDD, $hour = HH. */
    public function plan(int $eva, string $date, string $hour): ?\SimpleXMLElement;

    public function fchg(int $eva): ?\SimpleXMLElement;

    /**
     * Station metadata for an EVA, or null. The "meta" list holds the EVA's
     * sibling stations (the IRIS board data may live under one of them).
     *
     * @return array{eva:int,meta:int[],name:string}|null
     */
    public function station(int $eva): ?array;
}

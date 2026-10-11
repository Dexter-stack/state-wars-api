<?php

namespace App\Core\Db;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Small PostgreSQL helpers shared by repositories.
 *
 * Arrays (question_ids uuid[], uprising_states text[], options text[]) stay native Postgres arrays.
 * Reads select array_to_json(col) and decode it; writes bind a JSON string and let Postgres build the array:
 *   ARRAY(SELECT json_array_elements_text(?::json))::uuid[]
 * so no array literal is ever escaped by hand (options can contain quotes and commas).
 */
final class Pg
{
    /** SQL fragment for a bound JSON list turned into a typed array. Bind Pg::json($list) to the "?". */
    public static function arrayParam(string $type): string
    {
        return "ARRAY(SELECT json_array_elements_text(?::json))::{$type}[]";
    }

    /** @param list<mixed> $values */
    public static function json(array $values): string
    {
        return json_encode(array_values($values), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return list<mixed> */
    public static function decodeArray(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $v = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return is_array($v) ? array_values($v) : [];
    }

    /** ms epoch → timestamptz literal (UTC, millisecond precision, like a JS Date). */
    public static function ts(int $ms): string
    {
        $sec = intdiv($ms, 1000);
        $frac = $ms % 1000;
        if ($frac < 0) {
            $sec--;
            $frac += 1000;
        }

        return gmdate('Y-m-d\TH:i:s', $sec).sprintf('.%03dZ', $frac);
    }

    /** timestamptz from the database → ms epoch, truncated to the millisecond (what node-postgres gives). */
    public static function ms(string $value): int
    {
        return (int) (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Uv');
    }

    /** timestamptz → ISO string as JSON.stringify(new Date(...)) prints it. */
    public static function iso(string $value): string
    {
        return self::ts(self::ms($value));
    }

    public static function bool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === 1 || $v === '1' || $v === 'true';
    }
}

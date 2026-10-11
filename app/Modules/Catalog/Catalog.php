<?php

namespace App\Modules\Catalog;

/** Static reference data: states and subjects. Changes rarely, so it lives in code and is seeded into the DB. Immutable (Octane-safe). */
final class Catalog
{
    /** @var list<array{code: string, name: string, region: string}> */
    public const STATES = [
        ['code' => 'AB', 'name' => 'Abia', 'region' => 'South East'],
        ['code' => 'AD', 'name' => 'Adamawa', 'region' => 'North East'],
        ['code' => 'AK', 'name' => 'Akwa Ibom', 'region' => 'South South'],
        ['code' => 'AN', 'name' => 'Anambra', 'region' => 'South East'],
        ['code' => 'BA', 'name' => 'Bauchi', 'region' => 'North East'],
        ['code' => 'BY', 'name' => 'Bayelsa', 'region' => 'South South'],
        ['code' => 'BE', 'name' => 'Benue', 'region' => 'North Central'],
        ['code' => 'BO', 'name' => 'Borno', 'region' => 'North East'],
        ['code' => 'CR', 'name' => 'Cross River', 'region' => 'South South'],
        ['code' => 'DE', 'name' => 'Delta', 'region' => 'South South'],
        ['code' => 'EB', 'name' => 'Ebonyi', 'region' => 'South East'],
        ['code' => 'ED', 'name' => 'Edo', 'region' => 'South South'],
        ['code' => 'EK', 'name' => 'Ekiti', 'region' => 'South West'],
        ['code' => 'EN', 'name' => 'Enugu', 'region' => 'South East'],
        ['code' => 'FC', 'name' => 'FCT', 'region' => 'North Central'],
        ['code' => 'GO', 'name' => 'Gombe', 'region' => 'North East'],
        ['code' => 'IM', 'name' => 'Imo', 'region' => 'South East'],
        ['code' => 'JI', 'name' => 'Jigawa', 'region' => 'North West'],
        ['code' => 'KD', 'name' => 'Kaduna', 'region' => 'North West'],
        ['code' => 'KN', 'name' => 'Kano', 'region' => 'North West'],
        ['code' => 'KT', 'name' => 'Katsina', 'region' => 'North West'],
        ['code' => 'KE', 'name' => 'Kebbi', 'region' => 'North West'],
        ['code' => 'KO', 'name' => 'Kogi', 'region' => 'North Central'],
        ['code' => 'KW', 'name' => 'Kwara', 'region' => 'North Central'],
        ['code' => 'LA', 'name' => 'Lagos', 'region' => 'South West'],
        ['code' => 'NA', 'name' => 'Nasarawa', 'region' => 'North Central'],
        ['code' => 'NI', 'name' => 'Niger', 'region' => 'North Central'],
        ['code' => 'OG', 'name' => 'Ogun', 'region' => 'South West'],
        ['code' => 'ON', 'name' => 'Ondo', 'region' => 'South West'],
        ['code' => 'OS', 'name' => 'Osun', 'region' => 'South West'],
        ['code' => 'OY', 'name' => 'Oyo', 'region' => 'South West'],
        ['code' => 'PL', 'name' => 'Plateau', 'region' => 'North Central'],
        ['code' => 'RI', 'name' => 'Rivers', 'region' => 'South South'],
        ['code' => 'SO', 'name' => 'Sokoto', 'region' => 'North West'],
        ['code' => 'TA', 'name' => 'Taraba', 'region' => 'North East'],
        ['code' => 'YO', 'name' => 'Yobe', 'region' => 'North East'],
        ['code' => 'ZA', 'name' => 'Zamfara', 'region' => 'North West'],
    ];

    /** Question subjects. Only `league: true` subjects get their own daily league. */
    public const SUBJECTS = [
        ['id' => 'english', 'name' => 'English', 'league' => true],
        ['id' => 'maths', 'name' => 'Maths', 'league' => true],
        ['id' => 'science', 'name' => 'Science', 'league' => true],
        ['id' => 'gist', 'name' => 'Gist and news', 'league' => true],
        ['id' => 'sports', 'name' => 'Sports', 'league' => true],
        ['id' => 'music', 'name' => 'Music and Nollywood', 'league' => true],
        ['id' => 'history', 'name' => 'History', 'league' => false],
        ['id' => 'geography', 'name' => 'Geography', 'league' => false],
    ];

    public static function isStateCode(string $code): bool
    {
        return self::state($code) !== null;
    }

    public static function stateName(string $code): string
    {
        return self::state($code)['name'] ?? $code;
    }

    public static function stateRegion(string $code): string
    {
        return self::state($code)['region'] ?? '';
    }

    public static function isSubject(string $id): bool
    {
        return self::subject($id) !== null;
    }

    public static function subjectName(string $id): string
    {
        return self::subject($id)['name'] ?? $id;
    }

    /** @return list<array{id: string, name: string, league: bool}> */
    public static function leagueSubjects(): array
    {
        return array_values(array_filter(self::SUBJECTS, fn ($s) => $s['league']));
    }

    /** @return list<string> */
    public static function leagueIds(): array
    {
        return array_column(self::leagueSubjects(), 'id');
    }

    public static function ordinal(int $n): string
    {
        $s = ['th', 'st', 'nd', 'rd'];
        $v = $n % 100;
        // JS: s[(v - 20) % 10] || s[v] || s[0]  (JS % keeps the sign, so negatives index nothing)
        $a = ($v - 20) % 10;

        return $n.($s[$a] ?? $s[$v] ?? $s[0]);
    }

    /** @return array{code: string, name: string, region: string}|null */
    private static function state(string $code): ?array
    {
        foreach (self::STATES as $s) {
            if ($s['code'] === $code) {
                return $s;
            }
        }

        return null;
    }

    /** @return array{id: string, name: string, league: bool}|null */
    private static function subject(string $id): ?array
    {
        foreach (self::SUBJECTS as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }

        return null;
    }
}

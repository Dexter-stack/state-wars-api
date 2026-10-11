<?php

namespace Database\Seeders;

use App\Core\Clock\Clock;
use App\Core\Config\GameConfig;
use App\Core\Db\Pg;
use App\Core\JsMath;
use App\Core\WatTime;
use App\Modules\Catalog\Catalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Development seed (port of the Node src/db/seed.ts): states, an admin, sample questions, banter, derbies,
 * yesterday's results and today's battle. Safe to run more than once. Never run against production.
 */
class DatabaseSeeder extends Seeder
{
    private const QUESTIONS = [
        ['subject' => 'english', 'text' => "Choose the word nearest in meaning to the one in capitals: Mama Put's new prices are EXORBITANT.", 'highlight' => 'EXORBITANT', 'options' => ['Affordable', 'Excessive', 'Delicious', 'Temporary'], 'answer' => 1, 'explanation' => 'Exorbitant means far too high, usually about prices.', 'fun_headline' => 'Many players picked "Delicious"', 'fun_banter' => 'Dem think say Mama Put food dey inside exam.'],
        ['subject' => 'geography', 'text' => 'Which river meets the Niger at Lokoja?', 'options' => ['Benue', 'Ogun', 'Cross River', 'Kaduna'], 'answer' => 0, 'explanation' => 'The Niger and Benue meet at Lokoja in Kogi State.'],
        ['subject' => 'maths', 'text' => 'If 3x + 5 = 20, what is x?', 'options' => ['3', '5', '15', '25'], 'answer' => 1, 'explanation' => '3x = 20 − 5 = 15, so x = 15 ÷ 3 = 5.'],
        ['subject' => 'history', 'text' => 'In which year did Nigeria become a republic?', 'options' => ['1960', '1963', '1966', '1979'], 'answer' => 1, 'explanation' => 'Nigeria gained independence in 1960 and became a republic on 1 October 1963.', 'fun_headline' => 'Plenty of Nigeria picked 1960', 'fun_banter' => 'Independence and republic no be the same thing o.'],
        ['subject' => 'science', 'text' => 'What is the chemical symbol for sodium?', 'options' => ['So', 'Sd', 'Na', 'S'], 'answer' => 2, 'explanation' => "Sodium's symbol, Na, comes from its Latin name, natrium."],
        ['subject' => 'sports', 'text' => "In which year did Nigeria win Olympic gold in men's football?", 'options' => ['1992', '1996', '2000', '2008'], 'answer' => 1, 'explanation' => 'The Dream Team won gold at the Atlanta Olympics in 1996.'],
        ['subject' => 'gist', 'text' => 'Which city is the capital of Kwara State?', 'options' => ['Ilorin', 'Offa', 'Lokoja', 'Ogbomoso'], 'answer' => 0, 'explanation' => 'Ilorin is the capital of Kwara State.'],
        ['subject' => 'music', 'text' => 'Fela Kuti is most associated with which genre?', 'options' => ['Highlife', 'Fuji', 'Afrobeat', 'Juju'], 'answer' => 2, 'explanation' => 'Fela pioneered Afrobeat in the late 1960s and 1970s.'],
        ['subject' => 'english', 'text' => 'Choose the correctly spelt word.', 'options' => ['Accomodation', 'Acommodation', 'Accommodation', 'Accommodeation'], 'answer' => 2, 'explanation' => 'Accommodation has a double c and a double m.'],
        ['subject' => 'maths', 'text' => 'What is 15% of 200?', 'options' => ['15', '20', '30', '35'], 'answer' => 2, 'explanation' => '15% of 200 = 0.15 × 200 = 30.'],
    ];

    private const BANTER = [
        '{state}, una dey {rank} o. Even Lagos traffic dey move pass una score.',
        '{state} is {rank} tonight. Who took the light during revision?',
        '{state} at {rank}? Mama Put go hear about this one.',
        '{state} dropped to {rank}. Una go come back tomorrow abi?',
        '{state}, {rank} place. The danfo don comot leave una for bus stop.',
    ];

    private const DERBIES = [
        ['EN', 'AN', 'South East'], ['RI', 'DE', 'South South'], ['KN', 'KD', 'North West'],
        ['LA', 'OG', 'South West'], ['OY', 'OS', 'South West'], ['FC', 'NA', 'North Central'], ['EK', 'ON', 'South West'],
    ];

    public function run(): void
    {
        if (app()->isProduction() || config('statewars.node_env') === 'production') {
            throw new RuntimeException('Refusing to seed production.');
        }
        $cfg = app(GameConfig::class);
        $r = self::seed($cfg->battleHourWat, $cfg->battleWindowMin, $cfg->launchDateWat, app(Clock::class)->nowMs());
        $this->command?->info("Seeded. Today's battle: {$r['today']}. Admin users: sw_admin, sw_editor.");
    }

    /** @return array{adminId: string, editorId: string, questionIds: list<string>, today: string} */
    public static function seed(int $hour, int $windowMin, string $launch, int $now): array
    {
        $today = WatTime::watDate($now);
        $yesterday = WatTime::addDays($today, -1);
        $nowTs = Pg::ts($now);

        DB::table('states')->insertOrIgnore(array_map(
            fn ($s) => ['code' => $s['code'], 'name' => $s['name'], 'region' => $s['region']],
            Catalog::STATES,
        ));

        $upsertAdmin = fn (string $username, string $state) => DB::selectOne(
            "INSERT INTO users (username, state_code, role, state_changed_at) VALUES (?, ?, 'admin', ?)
             ON CONFLICT (lower(username)) DO UPDATE SET role = 'admin' RETURNING id",
            [$username, $state, $nowTs],
        )->id;
        $adminId = $upsertAdmin('sw_admin', 'KW');
        $editorId = $upsertAdmin('sw_editor', 'LA');

        $qIds = DB::table('questions')->where('status', 'approved')->orderBy('created_at')->limit(10)->pluck('id')->all();
        if (count($qIds) < 10) {
            $qIds = [];
            foreach (self::QUESTIONS as $q) {
                $qIds[] = DB::selectOne(
                    'INSERT INTO questions (subject, text, highlight, options, answer, explanation, fun_headline, fun_banter, status, author_id, approved_by)
                     VALUES (?, ?, ?, '.Pg::arrayParam('text').", ?, ?, ?, ?, 'approved', ?, ?) RETURNING id",
                    [$q['subject'], $q['text'], $q['highlight'] ?? null, Pg::json($q['options']), $q['answer'], $q['explanation'],
                        $q['fun_headline'] ?? null, $q['fun_banter'] ?? null, $editorId, $adminId],
                )->id;
            }
        }

        if (! DB::table('banter_lines')->exists()) {
            DB::table('banter_lines')->insert(array_map(fn ($t) => ['template' => $t, 'approved' => true], self::BANTER));
        }

        // derbies for this week (Mon..Sun) so the Derby tab has content
        $monday = WatTime::addDays($today, -(WatTime::watWeekday($now) - 1));
        DB::table('derbies')->insertOrIgnore(array_map(
            fn ($d, $i) => ['derby_date' => WatTime::addDays($monday, $i), 'home' => $d[0], 'away' => $d[1], 'region' => $d[2]],
            self::DERBIES, array_keys(self::DERBIES),
        ));

        $insertBattle = function (string $date, string $status) use ($hour, $windowMin, $launch, $qIds) {
            $t = WatTime::battleTimes($date, $hour, $windowMin);

            return DB::selectOne(
                "INSERT INTO battles (battle_date, kind, subject, day_number, opens_at, closes_at, question_ids, status)
                 VALUES (?, 'daily', NULL, ?, ?, ?, ".Pg::arrayParam('uuid').', ?) ON CONFLICT DO NOTHING RETURNING id',
                [$date, WatTime::dayNumber($date, $launch), Pg::ts($t['opensAt']), Pg::ts($t['closesAt']), Pg::json($qIds), $status],
            );
        };

        // yesterday: a finalized battle with results for every state, so moves, week tables and head-to-heads show up
        $y = $insertBattle($yesterday, 'finalized');
        if ($y) {
            $rows = [];
            foreach (Catalog::STATES as $i => $s) {
                $rows[] = ['code' => $s['code'], 'avg' => JsMath::round2(5 + (($i * 37) % 30) / 10), 'players' => 250 + (($i * 113) % 3000)];
            }
            usort($rows, fn ($a, $b) => $b['avg'] <=> $a['avg']);
            DB::table('state_results')->insert(array_map(fn ($r, $i) => [
                'battle_id' => $y->id, 'state_code' => $r['code'], 'players' => $r['players'], 'average' => $r['avg'],
                'rank' => $i + 1, 'move' => 0, 'banter' => null,
            ], $rows, array_keys($rows)));
        }

        // today's battle
        $insertBattle($today, 'scheduled');

        return ['adminId' => $adminId, 'editorId' => $editorId, 'questionIds' => $qIds, 'today' => $today];
    }
}

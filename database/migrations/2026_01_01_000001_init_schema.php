<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// Initial schema: the same DDL as the Node API's src/db/migrations/001_init.ts (PostgreSQL only), except the
// `sessions` table, replaced by Sanctum's personal_access_tokens (next migration). Kept as raw SQL so CHECKs,
// arrays, partial and expression indexes are exactly the Node ones.
// Never edit a migration that has run in production: add a new file instead.
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE EXTENSION IF NOT EXISTS pgcrypto;

            CREATE TABLE states (
              code   char(2) PRIMARY KEY,
              name   text NOT NULL UNIQUE,
              region text NOT NULL
            );

            CREATE TABLE users (
              id               uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              username         text NOT NULL,
              state_code       char(2) NOT NULL REFERENCES states(code),
              state_changed_at timestamptz NOT NULL DEFAULT now(),
              role             text NOT NULL DEFAULT 'player' CHECK (role IN ('player','admin')),
              points           integer NOT NULL DEFAULT 0,
              streak           integer NOT NULL DEFAULT 0,
              best_streak      integer NOT NULL DEFAULT 0,
              last_played_date date,
              remind           boolean NOT NULL DEFAULT true,
              created_at       timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT username_format CHECK (username ~ '^[A-Za-z0-9._]{3,16}$')
            );
            CREATE UNIQUE INDEX users_username_lower ON users (lower(username));
            CREATE INDEX users_state ON users (state_code);

            CREATE TABLE questions (
              id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              subject      text NOT NULL,
              text         text NOT NULL,
              highlight    text,
              options      text[] NOT NULL CHECK (array_length(options, 1) = 4),
              answer       smallint NOT NULL CHECK (answer BETWEEN 0 AND 3),
              explanation  text NOT NULL,
              fun_headline text,
              fun_banter   text,
              status       text NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','retired')),
              author_id    uuid REFERENCES users(id),
              approved_by  uuid REFERENCES users(id),
              created_at   timestamptz NOT NULL DEFAULT now(),
              CONSTRAINT approver_differs CHECK (approved_by IS NULL OR author_id IS NULL OR approved_by <> author_id)
            );
            CREATE INDEX questions_status_subject ON questions (status, subject);

            CREATE TABLE battles (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              battle_date     date NOT NULL,
              kind            text NOT NULL CHECK (kind IN ('daily','league')),
              subject         text,
              day_number      integer NOT NULL,
              opens_at        timestamptz NOT NULL,
              closes_at       timestamptz NOT NULL,
              question_ids    uuid[] NOT NULL CHECK (array_length(question_ids, 1) BETWEEN 1 AND 20),
              uprising_states text[] NOT NULL DEFAULT '{}',
              status          text NOT NULL DEFAULT 'scheduled' CHECK (status IN ('scheduled','finalized')),
              created_at      timestamptz NOT NULL DEFAULT now(),
              CHECK (closes_at > opens_at),
              CHECK ((kind = 'daily') = (subject IS NULL))
            );
            CREATE UNIQUE INDEX battles_unique_slot ON battles (battle_date, kind, coalesce(subject, ''));
            CREATE INDEX battles_opens ON battles (opens_at DESC);

            CREATE TABLE entries (
              id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              battle_id       uuid NOT NULL REFERENCES battles(id),
              user_id         uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              state_code      char(2) NOT NULL REFERENCES states(code),
              seed            integer NOT NULL,
              started_at      timestamptz NOT NULL,
              finished_at     timestamptz,
              current_index   smallint NOT NULL DEFAULT 0,
              current_sent_at timestamptz NOT NULL,
              pause_used_ms   integer NOT NULL DEFAULT 0,
              score           smallint NOT NULL DEFAULT 0,
              points          integer NOT NULL DEFAULT 0,
              UNIQUE (battle_id, user_id)
            );
            CREATE INDEX entries_user_recent ON entries (user_id, started_at DESC);
            CREATE INDEX entries_unfinished ON entries (battle_id) WHERE finished_at IS NULL;

            CREATE TABLE entry_answers (
              entry_id    uuid NOT NULL REFERENCES entries(id) ON DELETE CASCADE,
              q_index     smallint NOT NULL,
              question_id uuid NOT NULL REFERENCES questions(id),
              choice      smallint,
              correct     boolean NOT NULL,
              points      integer NOT NULL,
              sent_at     timestamptz NOT NULL,
              answered_at timestamptz NOT NULL,
              PRIMARY KEY (entry_id, q_index)
            );
            CREATE INDEX entry_answers_question ON entry_answers (question_id);

            CREATE TABLE state_results (
              battle_id  uuid NOT NULL REFERENCES battles(id),
              state_code char(2) NOT NULL REFERENCES states(code),
              players    integer NOT NULL,
              average    numeric(5,2) NOT NULL,
              rank       integer,
              move       integer NOT NULL DEFAULT 0,
              banter     text,
              PRIMARY KEY (battle_id, state_code)
            );

            CREATE TABLE derbies (
              id         serial PRIMARY KEY,
              derby_date date NOT NULL UNIQUE,
              home       char(2) NOT NULL REFERENCES states(code),
              away       char(2) NOT NULL REFERENCES states(code),
              region     text NOT NULL,
              CHECK (home <> away)
            );

            CREATE TABLE challenges (
              id          uuid PRIMARY KEY DEFAULT gen_random_uuid(),
              from_user   uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              battle_id   uuid NOT NULL REFERENCES battles(id),
              to_user     uuid REFERENCES users(id) ON DELETE SET NULL,
              created_at  timestamptz NOT NULL DEFAULT now(),
              accepted_at timestamptz,
              CHECK (to_user IS NULL OR to_user <> from_user)
            );
            CREATE INDEX challenges_from ON challenges (from_user, created_at DESC);
            CREATE INDEX challenges_to ON challenges (to_user, created_at DESC);

            CREATE TABLE user_badges (
              user_id    uuid NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              badge      text NOT NULL,
              awarded_at timestamptz NOT NULL DEFAULT now(),
              PRIMARY KEY (user_id, badge)
            );

            CREATE TABLE banter_lines (
              id       serial PRIMARY KEY,
              template text NOT NULL,
              approved boolean NOT NULL DEFAULT false
            );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS banter_lines, user_badges, challenges, derbies, state_results,
              entry_answers, entries, battles, questions, users, states CASCADE;
            SQL);
    }
};

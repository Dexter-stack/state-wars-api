<?php

namespace App\Modules\Leaderboard\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Modules\Catalog\Catalog;
use Illuminate\Validation\Rule;

/** zod (query): { period: z.enum(["tonight","week","all"]).default("tonight"), subject: z.enum(["all", ...leagues]).default("all") } */
final class TableRequest extends ApiFormRequest
{
    protected function source(): array
    {
        return $this->query->all();
    }

    protected function transform(array $data): array
    {
        return $data + ['period' => 'tonight', 'subject' => 'all'];
    }

    public function rules(): array
    {
        return [
            'period' => ['string', Rule::in(['tonight', 'week', 'all'])],
            'subject' => ['string', Rule::in(['all', ...Catalog::leagueIds()])],
        ];
    }

    public function period(): string
    {
        return $this->validated('period');
    }

    public function subject(): string
    {
        return $this->validated('subject');
    }
}

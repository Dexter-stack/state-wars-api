<?php

namespace App\Modules\Leaderboard\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** contracts.ts TableRow plus stateCode, ranked, rank (unranked states under MIN_PLAYERS_RANKED have rank null). */
final class TableRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'state' => $r['state'], 'stateCode' => $r['stateCode'], 'players' => $r['players'], 'move' => $r['move'],
            'average' => $r['average'], 'ranked' => $r['ranked'], 'rank' => $r['rank'],
        ];
    }
}

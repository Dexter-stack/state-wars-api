<?php

namespace App\Modules\Auth\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** { token, player: PlayerSummary } */
final class GuestSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['token' => $this->resource['token'], 'player' => $this->resource['player']->toArray()];
    }
}

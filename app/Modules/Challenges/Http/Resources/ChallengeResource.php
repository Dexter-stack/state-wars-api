<?php

namespace App\Modules\Challenges\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** contracts.ts Challenge { id, username, state, status: won|lost|draw|waiting, score? } */
final class ChallengeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $c = $this->resource;
        $out = ['id' => $c['id'], 'username' => $c['username'], 'state' => $c['state'], 'status' => $c['status']];
        if (isset($c['score'])) {
            $out['score'] = $c['score'];
        }

        return $out;
    }
}

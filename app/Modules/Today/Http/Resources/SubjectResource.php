<?php

namespace App\Modules\Today\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** contracts.ts Subject { id, name, leader, myStateRank } */
final class SubjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'], 'name' => $this->resource['name'],
            'leader' => $this->resource['leader'], 'myStateRank' => $this->resource['myStateRank'],
        ];
    }
}

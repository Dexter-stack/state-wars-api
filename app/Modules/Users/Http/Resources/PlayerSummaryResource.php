<?php

namespace App\Modules\Users\Http\Resources;

use App\Shared\Contracts\PlayerSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property PlayerSummary $resource */
final class PlayerSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

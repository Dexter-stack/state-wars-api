<?php

namespace App\Modules\Questions\Http\Resources;

use App\Modules\Questions\Repositories\QuestionRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** The raw question row, snake_case, exactly as the Node admin endpoints return it. Admin only: includes the answer. */
final class QuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var QuestionRow $q */
        $q = $this->resource;

        return $q->toRowArray();
    }
}

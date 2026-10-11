<?php

namespace App\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base for resources that render a contract DTO (App\Shared\Contracts\*) as-is: the DTO's toArray()
 * already has the exact camelCase field names, order and optional-field rules of contracts.ts.
 */
abstract class ContractResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}

<?php

namespace App\Modules\Derbies\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Derbies\Http\Resources\DerbyPageResource;
use App\Modules\Derbies\Services\DerbiesService;
use Illuminate\Http\Request;

final class DerbiesController
{
    public function __construct(private readonly DerbiesService $derbies) {}

    /** GET /v1/derbies */
    public function __invoke(Request $request): DerbyPageResource
    {
        return new DerbyPageResource($this->derbies->page(AuthUser::of($request)->id));
    }
}

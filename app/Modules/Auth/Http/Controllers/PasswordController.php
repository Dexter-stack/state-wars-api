<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Modules\Auth\AuthUser;
use App\Modules\Auth\Http\Requests\ChangePasswordRequest;
use App\Modules\Auth\Http\Requests\EmailCodePasswordRequest;
use App\Modules\Auth\Http\Requests\EmailRequest;
use App\Modules\Auth\Http\Resources\GuestSessionResource;
use App\Modules\Auth\Services\AccountService;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Response;

final class PasswordController
{
    public function __construct(private readonly AccountService $accounts) {}

    /** POST /v1/auth/password/forgot (public) { email } → 204 whether or not the email has an account */
    public function forgot(EmailRequest $request): Response
    {
        $this->accounts->forgot($request->email());

        return new Response(null, 204);
    }

    /** POST /v1/auth/password/reset (public) { email, code, password } → 200 { token, player } */
    public function reset(EmailCodePasswordRequest $request): GuestSessionResource
    {
        return new GuestSessionResource($this->accounts->reset($request->email(), $request->code(), $request->password()));
    }

    /** POST /v1/auth/password/change { currentPassword, newPassword } → 204 */
    public function change(ChangePasswordRequest $request): Response
    {
        $this->accounts->changePassword(
            AuthUser::of($request)->id,
            (string) AppServiceProvider::bearer($request),
            $request->currentPassword(),
            $request->newPassword(),
        );

        return new Response(null, 204);
    }
}

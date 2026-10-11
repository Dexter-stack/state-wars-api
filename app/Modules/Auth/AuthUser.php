<?php

namespace App\Modules\Auth;

use Illuminate\Http\Request;
use LogicException;

/** The signed-in player for this request (set by the Authenticate middleware; per request, so Octane-safe). */
final readonly class AuthUser
{
    public const ATTRIBUTE = 'statewars.authUser';

    public function __construct(public string $id, public string $role) {}

    public static function of(Request $request): self
    {
        $u = $request->attributes->get(self::ATTRIBUTE);
        if (! $u instanceof self) {
            throw new LogicException('Authenticate middleware missing on this route');
        }

        return $u;
    }

    /** @return array{userId: string, role: string} */
    public function toArray(): array
    {
        return ['userId' => $this->id, 'role' => $this->role];
    }
}

<?php

namespace App\Modules\Users\Http\Requests;

use App\Core\Http\ApiFormRequest;
use App\Core\Http\Rules\StrictBoolean;
use Illuminate\Validation\Validator;

/**
 * zod: { stateCode: z.string().length(2).toUpperCase().optional(), remind: z.boolean().optional() }
 *      .refine(v => v.stateCode !== undefined || v.remind !== undefined, "Send stateCode or remind.")
 */
final class PatchMeRequest extends ApiFormRequest
{
    protected function transform(array $data): array
    {
        if (array_key_exists('stateCode', $data)) {
            $data['stateCode'] = self::upper($data['stateCode']);
        }

        return $data;
    }

    public function rules(): array
    {
        return [
            'stateCode' => ['sometimes', 'string', 'size:2'],
            'remind' => ['sometimes', new StrictBoolean],
        ];
    }

    public function after(): array
    {
        return [function (Validator $v) {
            $d = $v->getData();
            if (! array_key_exists('stateCode', $d) && ! array_key_exists('remind', $d)) {
                $v->errors()->add('', 'Send stateCode or remind.');
            }
        }];
    }

    public function stateCode(): ?string
    {
        return $this->validated('stateCode');
    }

    public function remind(): ?bool
    {
        return $this->validated('remind');
    }
}

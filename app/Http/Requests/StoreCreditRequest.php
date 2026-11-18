<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\CreditPacks;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCreditRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount_usd' => ['required', 'string', Rule::in(CreditPacks::all())],
        ];
    }

    public function messages(): array
    {
        return [
            'amount_usd.in' => 'Choose one of the available credit packs.',
        ];
    }

    public function amountUsd(): string
    {
        return $this->validated('amount_usd');
    }
}

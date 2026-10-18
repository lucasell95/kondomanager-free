<?php

namespace App\Http\Requests\Gestionale\Assemblee;

use App\Support\LimiteCaricamento;
use Illuminate\Foundation\Http\FormRequest;

class RegistraVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => ['nullable', 'file', 'mimes:pdf', 'max:'.LimiteCaricamento::regolaMax()],
            'pubblica' => ['boolean'],
        ];
    }
}

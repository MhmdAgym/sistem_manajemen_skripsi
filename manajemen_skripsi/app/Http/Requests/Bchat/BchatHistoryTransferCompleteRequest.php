<?php

namespace App\Http\Requests\Bchat;

use Illuminate\Foundation\Http\FormRequest;

class BchatHistoryTransferCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'completion_signature' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'completion_signature.required' => 'Tanda tangan digital konfirmasi penyelesaian wajib disertakan.',
        ];
    }
}


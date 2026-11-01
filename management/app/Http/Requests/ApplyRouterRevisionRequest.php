<?php

namespace App\Http\Requests;

use App\Models\RouterNode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ApplyRouterRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var RouterNode $routerNode */
        $routerNode = $this->route('router');

        return $this->user()?->can('apply', $routerNode) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'expected_lock_version' => ['nullable', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:255'],
            'safe_mode_minutes' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }
}

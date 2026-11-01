<?php

namespace App\Http\Requests;

use App\Models\RouterNode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreRouterNodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', RouterNode::class) ?? false;
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'namespace_name' => [
                'required',
                'string',
                'max:32',
                'regex:/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/',
                Rule::unique('router_nodes', 'namespace_name'),
            ],
            'management_interface' => ['required', 'string', 'max:15', 'regex:/^[a-zA-Z0-9._-]+$/'],
            'management_cidr' => ['required', 'string', 'regex:/^(\d{1,3}\.){3}\d{1,3}\/\d{1,2}$/'],
            'appliance_host' => ['nullable', 'string', 'max:255'],
            'appliance_ssh_port' => ['nullable', 'integer', 'between:1,65535'],
            'appliance_ssh_user' => ['nullable', 'string', 'max:64'],
            'appliance_ssh_private_key' => ['nullable', 'string'],
            'appliance_ssh_known_host' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'management_cidr.regex' => 'Use an IPv4 management address with CIDR notation, for example 192.0.2.1/24.',
            'namespace_name.regex' => 'Namespace names may contain lowercase letters, digits, and hyphens only.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('management_cidr')) {
                    return;
                }

                [$address, $prefix] = explode('/', (string) $this->input('management_cidr'));

                if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (int) $prefix > 32) {
                    $validator->errors()->add('management_cidr', 'Use a valid IPv4 CIDR such as 192.0.2.1/24.');
                }

                if ($this->filled('appliance_host')) {
                    foreach (['appliance_ssh_user', 'appliance_ssh_private_key', 'appliance_ssh_known_host'] as $field) {
                        if (! $this->filled($field)) {
                            $validator->errors()->add(
                                $field,
                                'Live appliance SSH requires host, user, private key, and a pinned known_hosts entry. Changed host keys must never be accepted silently.',
                            );
                        }
                    }
                }
            },
        ];
    }
}

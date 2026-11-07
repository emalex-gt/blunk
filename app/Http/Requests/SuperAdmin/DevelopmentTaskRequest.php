<?php

namespace App\Http\Requests\SuperAdmin;

use App\Support\DevelopmentTaskOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DevelopmentTaskRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user()?->is_super_admin; }

    protected function prepareForValidation(): void
    {
        if ($this->input('client_name') === null || $this->input('client_name') === '') {
            $this->merge(['client_name' => 'Blunk Core / General']);
        }
    }

    public function rules(): array
    {
        $rules = [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'acceptance_criteria' => ['nullable', 'string', 'max:20000'],
            'technical_notes' => ['nullable', 'string', 'max:20000'],
            'blocked_reason' => ['nullable', 'required_if:work_status,blocked', 'string', 'max:4000'],
            'business_id' => ['nullable', 'integer', Rule::exists('businesses', 'id')],
            'client_name' => ['nullable', 'string', 'max:160'],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_super_admin', true)],
            'github_branch' => ['nullable', 'string', 'max:255'],
            'github_commit' => ['nullable', 'regex:/\A[0-9a-f]{7,64}\z/i'],
            'github_url' => ['nullable', 'url:https', 'max:1000', function ($attribute, $value, $fail) {
                $parts = parse_url($value);
                if (strtolower($parts['host'] ?? '') !== 'github.com' || isset($parts['user']) || isset($parts['pass'])) {
                    $fail('Usa un enlace HTTPS de github.com sin credenciales.');
                }
            }],
            'sort_order' => ['required', 'integer', 'min:0', 'max:2147483647'],
            'version' => [$this->isMethod('post') ? 'nullable' : 'required', 'integer', 'min:1'],
            'checklist' => ['present', 'array', 'max:100'],
            'checklist.*.id' => ['nullable', 'integer', 'distinct'],
            'checklist.*.title' => ['required', 'string', 'max:300'],
            'checklist.*.is_completed' => ['required', 'boolean'],
        ];
        foreach (DevelopmentTaskOptions::all() as $field => $options) {
            $rules[$field] = ['required', Rule::in(array_keys($options))];
        }
        return $rules;
    }
}

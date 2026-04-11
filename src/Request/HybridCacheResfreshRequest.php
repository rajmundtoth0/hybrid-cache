<?php

declare(strict_types=1);

namespace rajmundtoth0\HybridCache\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Override;

final class HybridCacheResfreshRequest extends FormRequest
{
    public ?string $key = null;
    public ?string $prefix = null;
    public ?string $group = null;
    public bool $shouldRefreshKeys;

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'key' => 'required_without_all:prefix,group|nullable|string|min:1',
            'prefix' => 'required_without_all:key,group|nullable|string|min:1',
            'group' => 'required_without_all:key,prefix|nullable|string|min:1',
            'shouldRefreshKeys' => 'boolean',
        ];
    }

    #[Override]
    protected function passedValidation(): void
    {
        $key = $this->input('key');
        $prefix = $this->input('prefix');
        $group = $this->input('group');

        $this->key = is_string($key) && $key !== '' ? $key : null;
        $this->prefix = is_string($prefix) && $prefix !== '' ? $prefix : null;
        $this->group = is_string($group) && $group !== '' ? $group : null;
        $this->shouldRefreshKeys = $this->boolean('shouldRefreshKeys', false);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $targets = [];

            foreach (['key', 'prefix', 'group'] as $field) {
                if ($this->filled($field)) {
                    $targets[] = $field;
                }
            }

            if (count($targets) <= 1) {
                return;
            }

            foreach ($targets as $field) {
                $validator->errors()->add($field, 'Provide exactly one of: key, prefix, group.');
            }
        });
    }
}

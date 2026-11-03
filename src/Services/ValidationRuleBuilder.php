<?php

namespace DevForge\IntegrationBuilder\Services;

use Illuminate\Validation\Rule;

/**
 * Converts builder field definitions into Laravel validation rules.
 *
 * A field definition has the shape:
 * `{name, type, required, nullable, rules, enum, item_type, children, condition}`.
 */
class ValidationRuleBuilder
{
    /**
     * Supported field types and the validation rule that enforces each.
     *
     * @var array<string, string>
     */
    public const array TYPE_RULES = [
        'string' => 'string',
        'integer' => 'integer',
        'number' => 'numeric',
        'boolean' => 'boolean',
        'object' => 'array',
        'array' => 'array',
        'email' => 'email',
        'url' => 'url',
        'uuid' => 'uuid',
        'date' => 'date',
        'datetime' => 'date',
        'file' => 'file',
    ];

    /**
     * Types allowed as the item type of a scalar array.
     *
     * @var list<string>
     */
    public const array SCALAR_TYPES = ['string', 'integer', 'number', 'boolean', 'email', 'url', 'uuid', 'date', 'datetime'];

    /**
     * Supported operators for conditional fields.
     *
     * @var list<string>
     */
    public const array CONDITION_OPERATORS = ['equals', 'not_equals', 'filled'];

    /**
     * Where a field's value comes from: the request, a credential column or a fixed value.
     *
     * @var list<string>
     */
    public const array FIELD_SOURCES = ['input', 'credential', 'fixed'];

    /**
     * Keep only the fields the caller supplies, dropping objects left without children.
     *
     * Fields filled from a credential column or a fixed value are added to
     * the payload by the generated code instead of being validated.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<array<string, mixed>>
     */
    public function inputFields(array $fields): array
    {
        $input = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['source'] ?? 'input') !== 'input') {
                continue;
            }

            if (($field['type'] ?? null) === 'object' && ($field['children'] ?? []) !== []) {
                $field['children'] = $this->inputFields((array) $field['children']);

                if ($field['children'] === []) {
                    continue;
                }
            }

            $input[] = $field;
        }

        return $input;
    }

    /**
     * List the fields filled from a credential column or a fixed value, with their dot paths.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<array{path: string, type: string, source: string, credential: string|null, value: string|null}>
     */
    public function injectedFields(array $fields, string $prefix = ''): array
    {
        $injected = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['name'] ?? null)) {
                continue;
            }

            $path = $prefix.$field['name'];
            $source = (string) ($field['source'] ?? 'input');

            if (($field['type'] ?? null) === 'object') {
                array_push($injected, ...$this->injectedFields((array) ($field['children'] ?? []), $path.'.'));
            } elseif ($source !== 'input') {
                $injected[] = [
                    'path' => $path,
                    'type' => (string) ($field['type'] ?? 'string'),
                    'source' => $source,
                    'credential' => $source === 'credential' ? (string) ($field['credential'] ?? '') : null,
                    'value' => $source === 'fixed' ? (string) ($field['value'] ?? '') : null,
                ];
            }
        }

        return $injected;
    }

    /**
     * Convert a fixed field value to its field type.
     */
    public function castFixedValue(string $value, string $type): string|int|float|bool
    {
        return match ($type) {
            'integer' => (int) $value,
            'number' => (float) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }

    /**
     * Build the flattened rule set for a list of fields.
     *
     * Required children of an optional object are only required when the
     * object itself is present, so `$presentWith` carries the nearest
     * optional ancestor down the tree.
     *
     * @param  array<array-key, mixed>  $fields
     * @return array<string, list<string>>
     */
    public function build(array $fields, string $prefix = '', ?string $presentWith = null): array
    {
        $rules = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = $prefix.$field['name'];
            $rules[$key] = $this->fieldRules($field, $prefix, $presentWith);

            $children = (array) ($field['children'] ?? []);

            if ($field['type'] === 'object' && $children !== []) {
                $isOptional = ! ($field['required'] ?? false) || filled($field['condition']['field'] ?? null);

                $rules += $this->build($children, $key.'.', $isOptional ? $key : $presentWith);
            }

            if ($field['type'] === 'array') {
                if ($children !== []) {
                    $rules += $this->build($children, $key.'.*.');
                } elseif (filled($field['item_type'] ?? null)) {
                    $rules[$key.'.*'] = [self::TYPE_RULES[$field['item_type']]];
                }
            }
        }

        return $rules;
    }

    /**
     * Build the rules for a single field.
     *
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    public function fieldRules(array $field, string $prefix = '', ?string $presentWith = null): array
    {
        $condition = $field['condition'] ?? null;
        $required = (bool) ($field['required'] ?? false);
        $nullable = (bool) ($field['nullable'] ?? false);

        $rules = match (true) {
            is_array($condition) && filled($condition['field'] ?? null) => [$this->conditionRule($condition, $prefix), 'nullable'],
            $required && $presentWith !== null => [($nullable ? 'present_with:' : 'required_with:').$presentWith, 'nullable'],
            $required && $nullable => ['present', 'nullable'],
            $required => ['required'],
            default => ['nullable'],
        };

        $rules[] = self::TYPE_RULES[$field['type']] ?? 'string';

        $enum = $this->enumValues($field['enum'] ?? null);

        if ($enum !== []) {
            $rules[] = (string) Rule::in($enum);
        }

        foreach ($this->extraRules($field['rules'] ?? null) as $rule) {
            if (! in_array($rule, $rules, true)) {
                $rules[] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Split a comma separated enum definition into values.
     *
     * @return array<int, string>
     */
    public function enumValues(?string $enum): array
    {
        return collect(explode(',', (string) $enum))
            ->map(fn (string $value): string => trim($value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Split a pipe separated rule definition into individual rules.
     *
     * @return array<int, string>
     */
    public function extraRules(?string $rules): array
    {
        return collect(explode('|', (string) $rules))
            ->map(fn (string $rule): string => trim($rule))
            ->filter(fn (string $rule): bool => $rule !== '')
            ->values()
            ->all();
    }

    /**
     * Build the presence rule for a conditional field.
     *
     * @param  array<string, mixed>  $condition
     */
    protected function conditionRule(array $condition, string $prefix): string
    {
        $other = $prefix.$condition['field'];
        $value = (string) ($condition['value'] ?? '');

        return match ($condition['operator'] ?? 'equals') {
            'not_equals' => "required_unless:{$other},{$value}",
            'filled' => "required_with:{$other}",
            default => "required_if:{$other},{$value}",
        };
    }
}

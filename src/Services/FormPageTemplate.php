<?php

namespace DevForge\IntegrationBuilder\Services;

use Illuminate\Support\Str;

/**
 * Renders an Inertia Vue page with a form for one generated endpoint.
 *
 * The form is pre-filled with the endpoint's sample values and is read-only
 * until the user clicks "Edit". Submitting calls the generated controller
 * method and shows validation errors and the API response.
 */
class FormPageTemplate
{
    /**
     * PrimeVue components that may be used by a form, keyed by tag name.
     *
     * @var array<string, string>
     */
    protected const array COMPONENTS = [
        'InputNumber' => 'primevue/inputnumber',
        'InputText' => 'primevue/inputtext',
        'Select' => 'primevue/select',
        'ToggleSwitch' => 'primevue/toggleswitch',
    ];

    /**
     * Components used by the page being rendered.
     *
     * @var array<string, true>
     */
    protected array $used = [];

    /**
     * @param  array<string, string>  $names
     */
    public function __construct(
        protected array $names,
        protected ValidationRuleBuilder $rules,
    ) {}

    /**
     * Frontends a form page can be generated for.
     *
     * @var list<string>
     */
    public const array FRONTENDS = ['vue', 'react', 'blade'];

    /**
     * Create the form template for a frontend.
     *
     * @param  array<string, string>  $names
     */
    public static function make(string $frontend, array $names, ValidationRuleBuilder $rules): self
    {
        return match ($frontend) {
            'react' => new ReactFormPageTemplate($names, $rules),
            'blade' => new BladeFormPageTemplate($names, $rules),
            default => new self($names, $rules),
        };
    }

    /**
     * Get the path of an endpoint's form page relative to the project root.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function pagePath(array $endpoint): string
    {
        return self::path($endpoint, $this->names);
    }

    /**
     * Render the route that shows an endpoint's form page.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function route(array $endpoint, string $uri): string
    {
        return 'Route::inertia('.$this->js($uri).', '.$this->js(self::component($endpoint, $this->names)).')';
    }

    /**
     * Get the Inertia component name of an endpoint's form page.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $names
     */
    public static function component(array $endpoint, array $names): string
    {
        return $names['slug'].'/'.Str::studly($endpoint['method']).'Form';
    }

    /**
     * Get the page path relative to the project root.
     *
     * @param  array<string, mixed>  $endpoint
     * @param  array<string, string>  $names
     */
    public static function path(array $endpoint, array $names): string
    {
        return 'resources/js/pages/'.self::component($endpoint, $names).'.vue';
    }

    /**
     * Render the page.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function render(array $endpoint): string
    {
        $this->used = [];

        $fields = [...$endpoint['query'], ...$endpoint['body']];
        $title = $this->names['title'].': '.Str::lower(Str::headline($endpoint['method']));
        $verb = strtolower($endpoint['http_method']);
        $params = array_values($endpoint['path_params']);

        $markup = $this->fields($fields, 'form', [], 4);

        if ($params !== []) {
            $this->used['InputText'] = true;
        }

        $componentImports = collect(array_keys($this->used))
            ->push('Button')
            ->unique()
            ->sort()
            ->map(fn (string $component): string => "import {$component} from '".(self::COMPONENTS[$component] ?? 'primevue/button')."';")
            ->implode("\n");

        $url = $params === []
            ? "{$endpoint['method']}.url()"
            : "{$endpoint['method']}.url({ ".implode(', ', array_map(fn (string $param): string => "{$param}: params.{$param}", $params)).' })';

        $paramsMarkup = implode("\n", array_map(fn (string $param): string => $this->paramInput($param), $params));
        $vueImports = $params === [] ? 'ref' : 'reactive, ref';
        $paramsDeclaration = $params === [] ? '' : 'const params = reactive('.$this->paramsObject($params).");\n";

        $script = <<<VUE
<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
{$componentImports}
import { {$vueImports} } from 'vue';
import { toast } from 'vue-sonner';
import { {$endpoint['method']} } from '@/actions/App/Http/Controllers/{$this->names['controller']}';
import JsonBlock from '@/components/integrations/JsonBlock.vue';
import { {$this->formRouteExport($endpoint)} } from '@/routes/{$this->names['slug']}';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: {$this->js($title)},
                href: {$this->formRouteExport($endpoint)}(),
            },
        ],
    },
});

const editing = ref(false);
const result = ref<unknown>(null);
{$paramsDeclaration}
const form = useHttp({$this->defaults($fields, 0)});

function error(key: string): string | undefined {
    return (form.errors as Record<string, string | undefined>)[key];
}

function submit(): void {
    result.value = null;

    form.{$verb}({$url}, {
        onSuccess: (response) => {
            result.value = response;
            editing.value = false;
            toast.success('Request sent successfully.');
        },
        onHttpException: (response) => {
            result.value = response.data;
            toast.error(`Request failed (\${response.status}).`);
        },
    });
}
</script>
VUE;

        $template = <<<VUE
<template>
    <Head :title="{$this->js($title)}" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">{$this->html($title)}</h1>
                <p class="text-sm text-muted-foreground">
                    {$endpoint['http_method']} {$this->html($endpoint['path'])}
                </p>
            </div>
            <div class="flex gap-2">
                <Button
                    v-if="!editing"
                    label="Edit"
                    icon="pi pi-pencil"
                    severity="secondary"
                    outlined
                    @click="editing = true"
                />
                <Button
                    v-else
                    label="Done editing"
                    icon="pi pi-lock"
                    severity="secondary"
                    outlined
                    @click="editing = false"
                />
                <Button
                    label="Submit"
                    icon="pi pi-send"
                    :loading="form.processing"
                    @click="submit"
                />
            </div>
        </div>

        <form class="grid gap-4 rounded-xl border p-5 md:grid-cols-2" @submit.prevent="submit">
{$paramsMarkup}
{$markup}
        </form>

        <section v-if="result !== null" class="space-y-2">
            <h2 class="text-sm font-semibold">Response</h2>
            <JsonBlock :value="result" />
        </section>
    </div>
</template>

VUE;

        return $script."\n\n".$template;
    }

    /**
     * Get the Wayfinder export name of an endpoint's form route.
     *
     * @param  array<string, mixed>  $endpoint
     */
    public function formRouteExport(array $endpoint): string
    {
        return $endpoint['method'].'Page';
    }

    /**
     * Render inputs for a list of fields.
     *
     * @param  array<int, mixed>  $fields
     * @param  list<string>  $loops  Loop index variables of enclosing arrays.
     */
    protected function fields(array $fields, string $model, array $loops, int $depth, string $errorPrefix = ''): string
    {
        $parts = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? null) === 'file') {
                continue;
            }

            $parts[] = $this->field($field, $model, $loops, $depth, $errorPrefix);
        }

        return implode("\n", $parts);
    }

    /**
     * Render the input for one field.
     *
     * @param  array<string, mixed>  $field
     * @param  list<string>  $loops
     */
    protected function field(array $field, string $model, array $loops, int $depth, string $errorPrefix): string
    {
        $name = (string) $field['name'];
        $path = $this->member($model, $name);
        $errorKey = $errorPrefix.$name;
        $label = $this->html(Str::headline($name)).(($field['required'] ?? false) ? ' *' : '');
        $indent = str_repeat('    ', $depth);
        $children = (array) ($field['children'] ?? []);

        if ($field['type'] === 'object') {
            return implode("\n", [
                "{$indent}<fieldset class=\"grid gap-4 rounded-lg border p-4 md:col-span-2 md:grid-cols-2\">",
                "{$indent}    <legend class=\"px-1 text-sm font-semibold\">{$label}</legend>",
                $this->fields($children, $path, $loops, $depth + 1, $errorKey.'.'),
                "{$indent}</fieldset>",
            ]);
        }

        if ($field['type'] === 'array') {
            $index = 'i'.count($loops);
            $item = "{$path}[{$index}]";
            $itemErrorPrefix = $errorKey.'.${'.$index.'}';

            $body = $children !== []
                ? "{$indent}        <div class=\"grid gap-4 md:grid-cols-2\">\n".$this->fields($children, $item, [...$loops, $index], $depth + 3, $itemErrorPrefix.'.')."\n{$indent}        </div>"
                : $this->control(['type' => $field['item_type'] ?? 'string', 'enum' => ''], $item, $this->errorExpression($itemErrorPrefix, [...$loops, $index]), $depth + 2);

            $newItem = $children !== [] ? $this->defaults($children, 0) : $this->js($this->scalarDefault(['type' => $field['item_type'] ?? 'string']));

            return implode("\n", [
                "{$indent}<fieldset class=\"space-y-3 rounded-lg border p-4 md:col-span-2\">",
                "{$indent}    <legend class=\"px-1 text-sm font-semibold\">{$label}</legend>",
                "{$indent}    <div",
                "{$indent}        v-for=\"(_, {$index}) in {$path}\"",
                "{$indent}        :key=\"{$index}\"",
                "{$indent}        class=\"flex items-start gap-3 rounded-md border p-3\"",
                "{$indent}    >",
                "{$indent}        <div class=\"flex-1\">",
                $body,
                "{$indent}        </div>",
                "{$indent}        <Button",
                "{$indent}            v-if=\"editing\"",
                "{$indent}            icon=\"pi pi-trash\"",
                "{$indent}            severity=\"danger\"",
                "{$indent}            text",
                "{$indent}            aria-label=\"Remove\"",
                "{$indent}            @click=\"{$path}.splice({$index}, 1)\"",
                "{$indent}        />",
                "{$indent}    </div>",
                "{$indent}    <Button",
                "{$indent}        v-if=\"editing\"",
                "{$indent}        label=\"Add\"",
                "{$indent}        icon=\"pi pi-plus\"",
                "{$indent}        severity=\"secondary\"",
                "{$indent}        size=\"small\"",
                "{$indent}        outlined",
                "{$indent}        @click=\"{$path}.push(".str_replace('"', "'", str_replace("\n", ' ', (string) preg_replace('/\s+/', ' ', $newItem))).')"',
                "{$indent}    />",
                "{$indent}</fieldset>",
            ]);
        }

        $error = $this->errorExpression($errorKey, $loops);

        return implode("\n", [
            "{$indent}<div class=\"flex flex-col gap-1\">",
            "{$indent}    <label class=\"text-sm font-medium\">{$label}</label>",
            $this->control($field, $path, $error, $depth + 1),
            "{$indent}    <small v-if=\"error({$error})\" class=\"text-red-600 dark:text-red-400\">{{ error({$error}) }}</small>",
            "{$indent}</div>",
        ]);
    }

    /**
     * Render the PrimeVue control for a scalar field.
     *
     * @param  array<string, mixed>  $field
     */
    protected function control(array $field, string $model, string $error, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $enum = $this->rules->enumValues((string) ($field['enum'] ?? ''));
        $common = ":disabled=\"!editing\" :invalid=\"!!error({$error})\"";

        if ($enum !== []) {
            $this->used['Select'] = true;
            $options = '['.implode(', ', array_map(fn (string $value): string => $this->js($value), $enum)).']';

            return "{$indent}<Select v-model=\"{$model}\" :options=\"{$options}\" {$common} class=\"w-full\" />";
        }

        return match ($field['type'] ?? 'string') {
            'integer' => $this->use('InputNumber', "{$indent}<InputNumber v-model=\"{$model}\" :use-grouping=\"false\" {$common} class=\"w-full\" />"),
            'number' => $this->use('InputNumber', "{$indent}<InputNumber v-model=\"{$model}\" :use-grouping=\"false\" :max-fraction-digits=\"6\" {$common} class=\"w-full\" />"),
            'boolean' => $this->use('ToggleSwitch', "{$indent}<ToggleSwitch v-model=\"{$model}\" :disabled=\"!editing\" />"),
            'email' => $this->use('InputText', "{$indent}<InputText v-model=\"{$model}\" type=\"email\" {$common} />"),
            'url' => $this->use('InputText', "{$indent}<InputText v-model=\"{$model}\" type=\"url\" {$common} />"),
            'date' => $this->use('InputText', "{$indent}<InputText v-model=\"{$model}\" type=\"date\" {$common} />"),
            'datetime' => $this->use('InputText', "{$indent}<InputText v-model=\"{$model}\" type=\"datetime-local\" {$common} />"),
            default => $this->use('InputText', "{$indent}<InputText v-model=\"{$model}\" {$common} />"),
        };
    }

    /**
     * Render the input for a path parameter.
     */
    protected function paramInput(string $param): string
    {
        $label = $this->html(Str::headline($param)).' *';

        return implode("\n", [
            '            <div class="flex flex-col gap-1">',
            "                <label class=\"text-sm font-medium\">{$label}</label>",
            "                <InputText v-model=\"params.{$param}\" :disabled=\"!editing\" />",
            '            </div>',
        ]);
    }

    /**
     * Render the initial form values as a TypeScript object literal.
     *
     * @param  array<int, mixed>  $fields
     */
    protected function defaults(array $fields, int $depth): string
    {
        $indent = str_repeat('    ', $depth + 1);
        $lines = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? null) === 'file') {
                continue;
            }

            $children = (array) ($field['children'] ?? []);

            $value = match (true) {
                $field['type'] === 'object' => $this->defaults($children, $depth + 1),
                $field['type'] === 'array' && $children !== [] => '['.$this->defaults($children, $depth + 1).']',
                $field['type'] === 'array' => '[] as '.$this->tsType((string) ($field['item_type'] ?? 'string')).'[]',
                in_array($field['type'], ['integer', 'number'], true) => $this->scalarDefault($field) === null ? 'null as number | null' : $this->js($this->scalarDefault($field)).' as number | null',
                default => $this->js($this->scalarDefault($field)),
            };

            $lines[] = $indent.$this->key((string) $field['name']).': '.$value.',';
        }

        return $lines === [] ? '{}' : "{\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).'}';
    }

    /**
     * Build the initial form values as a PHP array, for JSON encoding.
     *
     * @param  array<int, mixed>  $fields
     * @return array<string, mixed>
     */
    protected function defaultTree(array $fields): array
    {
        $values = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? null) === 'file') {
                continue;
            }

            $children = (array) ($field['children'] ?? []);

            $values[(string) $field['name']] = match ($field['type']) {
                'object' => (object) $this->defaultTree($children),
                'array' => $children === [] ? [] : [(object) $this->defaultTree($children)],
                default => $this->scalarDefault($field),
            };
        }

        return $values;
    }

    /**
     * Get the initial value of a scalar field from its sample.
     *
     * @param  array<string, mixed>  $field
     */
    protected function scalarDefault(array $field): string|int|float|bool|null
    {
        $example = $field['example'] ?? null;

        return match ($field['type'] ?? 'string') {
            'integer' => is_numeric($example) ? (int) $example : null,
            'number' => is_numeric($example) ? (float) $example : null,
            'boolean' => $example === 'true',
            default => is_scalar($example) ? (string) $example : '',
        };
    }

    /**
     * Get the TypeScript type of a scalar item type.
     */
    protected function tsType(string $type): string
    {
        return match ($type) {
            'integer', 'number' => 'number',
            'boolean' => 'boolean',
            default => 'string',
        };
    }

    /**
     * Build the TypeScript object holding path parameters.
     *
     * @param  list<string>  $params
     */
    protected function paramsObject(array $params): string
    {
        return $params === []
            ? '{}'
            : '{ '.implode(', ', array_map(fn (string $param): string => "{$param}: ''", $params)).' }';
    }

    /**
     * Render the JavaScript expression for a validation error key.
     *
     * @param  list<string>  $loops
     */
    protected function errorExpression(string $key, array $loops): string
    {
        return $loops === [] ? "'".addslashes($key)."'" : '`'.$key.'`';
    }

    /**
     * Render a property access, using brackets for names that are not identifiers.
     */
    protected function member(string $object, string $name): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name)
            ? "{$object}.{$name}"
            : "{$object}['".addslashes($name)."']";
    }

    /**
     * Mark a component as used and return its markup.
     */
    protected function use(string $component, string $markup): string
    {
        $this->used[$component] = true;

        return $markup;
    }

    /**
     * Encode a value as a JavaScript literal, using single-quoted strings.
     */
    protected function js(mixed $value): string
    {
        if (is_string($value)) {
            return "'".addcslashes($value, "'\\")."'";
        }

        return (string) json_encode($value);
    }

    /**
     * Render an object key, quoting it only when it is not an identifier.
     */
    protected function key(string $name): string
    {
        return preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $name) ? $name : $this->js($name);
    }

    /**
     * Escape text for HTML.
     */
    protected function html(string $value): string
    {
        return e($value);
    }
}

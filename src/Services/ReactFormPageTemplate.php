<?php

namespace Rizwan\IntegrationBuilder\Services;

use Illuminate\Support\Str;

/**
 * Renders an Inertia React page with a form for one generated endpoint.
 *
 * Like the Vue page it is pre-filled with sample values, read-only until
 * "Edit" is clicked, and shows validation errors and the API response.
 * Lists are edited as JSON so the page needs no component library.
 */
class ReactFormPageTemplate extends FormPageTemplate
{
    public function pagePath(array $endpoint): string
    {
        return 'resources/js/pages/'.self::component($endpoint, $this->names).'.tsx';
    }

    public function render(array $endpoint): string
    {
        $fields = [...$endpoint['query'], ...$endpoint['body']];
        $title = $this->names['title'].': '.Str::lower(Str::headline($endpoint['method']));
        $component = Str::studly($endpoint['method']).'Form';
        $params = array_values($endpoint['path_params']);
        $isGet = $endpoint['http_method'] === 'GET';
        $defaults = json_encode($this->defaultTree($fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $paramState = $params === [] ? '' : '    const [params, setParams] = useState<Record<string, string>>({ '.implode(', ', array_map(fn (string $param): string => "{$param}: ''", $params))." });\n";
        $urlArguments = $params === [] ? '' : '{ '.implode(', ', array_map(fn (string $param): string => "{$param}: params.{$param}", $params)).' }';

        $url = match (true) {
            $isGet && $params === [] => "{$endpoint['method']}.url({ query: form as Record<string, string> })",
            $isGet => "{$endpoint['method']}.url({$urlArguments}, { query: form as Record<string, string> })",
            default => "{$endpoint['method']}.url({$urlArguments})",
        };

        $paramInputs = implode("\n", array_map(fn (string $param): string => implode("\n", [
            '                <label className="flex flex-col gap-1 text-sm">',
            '                    <span className="font-medium">'.$this->html(Str::headline($param)).' *</span>',
            "                    <input className=\"rounded-md border px-3 py-2\" disabled={!editing} value={params.{$param}} onChange={(event) => setParams({ ...params, {$param}: event.target.value })} />",
            '                </label>',
        ]), $params));

        $inputs = $this->inputs($fields, '', 4);
        $method = strtoupper($endpoint['http_method']);
        $body = $isGet ? '' : "\n                body: JSON.stringify(form),";

        return <<<TSX
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { {$endpoint['method']} } from '@/actions/App/Http/Controllers/{$this->names['controller']}';

type Values = Record<string, unknown>;

const defaults: Values = {$defaults};

function getPath(values: Values, path: string): unknown {
    return path.split('.').reduce<unknown>((value, key) => (value as Values | undefined)?.[key], values);
}

function setPath(values: Values, path: string, value: unknown): Values {
    const [key, ...rest] = path.split('.');

    return {
        ...values,
        [key]: rest.length === 0 ? value : setPath((values[key] as Values) ?? {}, rest.join('.'), value),
    };
}

function xsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export default function {$component}() {
    const [editing, setEditing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [form, setForm] = useState<Values>(defaults);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [result, setResult] = useState<unknown>(null);
{$paramState}
    const set = (path: string, value: unknown) => setForm((current) => setPath(current, path, value));
    const text = (path: string) => String(getPath(form, path) ?? '');
    const error = (path: string) => errors[path]?.[0];

    function setJson(path: string, raw: string): void {
        try {
            set(path, JSON.parse(raw));
            setErrors(({ [path]: _, ...rest }) => rest);
        } catch {
            setErrors((current) => ({ ...current, [path]: ['Enter a valid JSON list.'] }));
        }
    }

    async function submit(event?: FormEvent): Promise<void> {
        event?.preventDefault();
        setProcessing(true);
        setErrors({});
        setResult(null);

        try {
            const response = await fetch({$url}, {
                method: '{$method}',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken() },{$body}
            });
            const data = await response.json().catch(() => null);

            if (response.status === 422) {
                setErrors((data as { errors?: Record<string, string[]> } | null)?.errors ?? {});
            } else {
                setResult(data);
                setEditing(!response.ok);
            }
        } finally {
            setProcessing(false);
        }
    }

    return (
        <>
            <Head title={{$this->js($title)}} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">{$this->html($title)}</h1>
                        <p className="text-sm opacity-70">{$endpoint['http_method']} {$this->html($endpoint['path'])}</p>
                    </div>
                    <div className="flex gap-2">
                        <button type="button" className="rounded-md border px-3 py-2 text-sm" onClick={() => setEditing(!editing)}>
                            {editing ? 'Done editing' : 'Edit'}
                        </button>
                        <button type="button" className="rounded-md bg-black px-3 py-2 text-sm text-white disabled:opacity-50" disabled={processing} onClick={() => submit()}>
                            {processing ? 'Sending…' : 'Submit'}
                        </button>
                    </div>
                </div>

                <form className="grid gap-4 rounded-xl border p-5 md:grid-cols-2" onSubmit={submit}>
{$paramInputs}
{$inputs}
                </form>

                {result !== null && (
                    <section className="space-y-2">
                        <h2 className="text-sm font-semibold">Response</h2>
                        <pre className="overflow-auto rounded-lg border p-4 text-xs">{JSON.stringify(result, null, 2)}</pre>
                    </section>
                )}
            </div>
        </>
    );
}

TSX;
    }

    /**
     * Render inputs for a list of fields.
     *
     * @param  array<int, mixed>  $fields
     */
    protected function inputs(array $fields, string $prefix, int $depth): string
    {
        $indent = str_repeat('    ', $depth);
        $parts = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ($field['type'] ?? null) === 'file') {
                continue;
            }

            $path = $prefix.$field['name'];
            $label = $this->html(Str::headline((string) $field['name'])).(($field['required'] ?? false) ? ' *' : '');
            $key = $this->js($path);

            if ($field['type'] === 'object') {
                $parts[] = implode("\n", [
                    "{$indent}<fieldset className=\"grid gap-4 rounded-lg border p-4 md:col-span-2 md:grid-cols-2\">",
                    "{$indent}    <legend className=\"px-1 text-sm font-semibold\">{$label}</legend>",
                    $this->inputs((array) ($field['children'] ?? []), $path.'.', $depth + 1),
                    "{$indent}</fieldset>",
                ]);

                continue;
            }

            $control = match (true) {
                $field['type'] === 'array' => "<textarea className=\"min-h-24 rounded-md border px-3 py-2 font-mono text-xs\" disabled={!editing} defaultValue={JSON.stringify(getPath(form, {$key}) ?? [], null, 2)} onBlur={(event) => setJson({$key}, event.target.value)} />",
                $field['type'] === 'boolean' => "<input type=\"checkbox\" className=\"size-4\" disabled={!editing} checked={Boolean(getPath(form, {$key}))} onChange={(event) => set({$key}, event.target.checked)} />",
                $this->rules->enumValues((string) ($field['enum'] ?? '')) !== [] => "<select className=\"rounded-md border px-3 py-2\" disabled={!editing} value={text({$key})} onChange={(event) => set({$key}, event.target.value)}>"
                    .implode('', array_map(fn (string $value): string => '<option value='.$this->js($value).'>'.$this->html($value).'</option>', $this->rules->enumValues((string) $field['enum'])))
                    .'</select>',
                in_array($field['type'], ['integer', 'number'], true) => "<input type=\"number\" className=\"rounded-md border px-3 py-2\" disabled={!editing} value={text({$key})} onChange={(event) => set({$key}, event.target.value === '' ? null : Number(event.target.value))} />",
                default => '<input type="'.$this->inputType((string) $field['type'])."\" className=\"rounded-md border px-3 py-2\" disabled={!editing} value={text({$key})} onChange={(event) => set({$key}, event.target.value)} />",
            };

            $parts[] = implode("\n", [
                "{$indent}<label className=\"flex flex-col gap-1 text-sm".($field['type'] === 'array' ? ' md:col-span-2' : '').'">',
                "{$indent}    <span className=\"font-medium\">{$label}</span>",
                "{$indent}    {$control}",
                "{$indent}    {error({$key}) && <small className=\"text-red-600\">{error({$key})}</small>}",
                "{$indent}</label>",
            ]);
        }

        return implode("\n", $parts);
    }

    /**
     * Get the HTML input type for a scalar field type.
     */
    protected function inputType(string $type): string
    {
        return match ($type) {
            'email' => 'email',
            'url' => 'url',
            'date' => 'date',
            'datetime' => 'datetime-local',
            default => 'text',
        };
    }
}

<?php

namespace Rizwan\IntegrationBuilder\Services;

use Illuminate\Support\Str;

/**
 * Renders a standalone Blade page with a form for one generated endpoint.
 *
 * It needs no JavaScript framework or CSS build: a little inline CSS and a
 * plain script send the form as JSON to the generated controller method.
 * Like the other pages it is read-only until "Edit" is clicked.
 */
class BladeFormPageTemplate extends FormPageTemplate
{
    public function pagePath(array $endpoint): string
    {
        return 'resources/views/'.$this->view($endpoint, '/').'.blade.php';
    }

    public function route(array $endpoint, string $uri): string
    {
        return 'Route::view('.$this->js($uri).', '.$this->js($this->view($endpoint, '.')).')';
    }

    public function render(array $endpoint): string
    {
        $fields = [...$endpoint['query'], ...$endpoint['body']];
        $title = $this->html($this->names['title'].': '.Str::lower(Str::headline($endpoint['method'])));
        $params = array_values($endpoint['path_params']);
        $routeName = $this->names['slug'].'.'.$endpoint['route_name'];
        $routeParams = $params === [] ? '' : ', ['.implode(', ', array_map(fn (string $param): string => "'{$param}' => '__{$param}__'", $params)).']';
        $defaults = json_encode($this->defaultTree($fields), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?: '{}';
        $method = strtoupper($endpoint['http_method']);

        $paramInputs = implode("\n", array_map(fn (string $param): string => implode("\n", [
            '            <label>',
            '                <span>'.$this->html(Str::headline($param)).' *</span>',
            "                <input data-param=\"{$param}\" required>",
            '            </label>',
        ]), $params));

        return <<<BLADE
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{$title}</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; padding: 2rem; color: #111827; background: #f9fafb; }
        main { max-width: 56rem; margin: 0 auto; display: grid; gap: 1.5rem; }
        header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 1rem; }
        h1 { margin: 0; font-size: 1.25rem; }
        header p { margin: .25rem 0 0; color: #6b7280; font-size: .875rem; }
        form, fieldset { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr)); }
        form { background: #fff; border: 1px solid #e5e7eb; border-radius: .75rem; padding: 1.25rem; }
        fieldset { grid-column: 1 / -1; border: 1px solid #e5e7eb; border-radius: .5rem; padding: 1rem; margin: 0; }
        legend { font-weight: 600; font-size: .875rem; padding: 0 .25rem; }
        label { display: grid; gap: .25rem; font-size: .875rem; }
        label span { font-weight: 500; }
        label.wide { grid-column: 1 / -1; }
        input, select, textarea { font: inherit; padding: .5rem .75rem; border: 1px solid #d1d5db; border-radius: .375rem; background: #fff; }
        textarea { min-height: 6rem; font-family: ui-monospace, monospace; font-size: .75rem; }
        input:disabled, select:disabled, textarea:disabled { background: #f3f4f6; color: #6b7280; }
        button { font: inherit; padding: .5rem .9rem; border-radius: .375rem; border: 1px solid #d1d5db; background: #fff; cursor: pointer; }
        button.primary { background: #111827; color: #fff; border-color: #111827; }
        small { color: #dc2626; }
        pre { background: #fff; border: 1px solid #e5e7eb; border-radius: .5rem; padding: 1rem; overflow: auto; font-size: .75rem; }
    </style>
</head>
<body>
<main>
    <header>
        <div>
            <h1>{$title}</h1>
            <p>{$method} {$this->html($endpoint['path'])}</p>
        </div>
        <div>
            <button type="button" id="edit">Edit</button>
            <button type="button" class="primary" id="submit">Submit</button>
        </div>
    </header>

    <form id="form" data-url="{{ route('{$routeName}'{$routeParams}) }}" data-method="{$method}">
        <fieldset id="fields" disabled style="border: 0; padding: 0;">
{$paramInputs}
{$this->inputs($fields, '', 3)}
        </fieldset>
    </form>

    <section id="result" hidden>
        <h2 style="font-size: .875rem;">Response</h2>
        <pre id="response"></pre>
    </section>
</main>

@verbatim
<script>
    const defaults = {$defaults};
    const form = document.getElementById('form');
    const fields = document.getElementById('fields');
    const editButton = document.getElementById('edit');

    const getPath = (values, path) => path.split('.').reduce((value, key) => value?.[key], values);
    const setPath = (values, path, value) => {
        const keys = path.split('.');
        let target = values;
        keys.slice(0, -1).forEach((key) => { target = target[key] ??= {}; });
        target[keys.at(-1)] = value;
    };

    form.querySelectorAll('[data-path]').forEach((input) => {
        const value = getPath(defaults, input.dataset.path);

        if (input.type === 'checkbox') {
            input.checked = Boolean(value);
        } else if (input.dataset.type === 'json') {
            input.value = JSON.stringify(value ?? [], null, 2);
        } else {
            input.value = value ?? '';
        }
    });

    editButton.addEventListener('click', () => {
        fields.disabled = !fields.disabled;
        editButton.textContent = fields.disabled ? 'Edit' : 'Done editing';
    });

    function values() {
        const body = {};

        form.querySelectorAll('[data-path]').forEach((input) => {
            let value = input.value;

            if (input.type === 'checkbox') value = input.checked;
            else if (input.dataset.type === 'number') value = value === '' ? null : Number(value);
            else if (input.dataset.type === 'json') value = JSON.parse(value || '[]');

            setPath(body, input.dataset.path, value);
        });

        return body;
    }

    async function submit() {
        form.querySelectorAll('small[data-error]').forEach((element) => { element.textContent = ''; });

        let url = form.dataset.url;
        form.querySelectorAll('[data-param]').forEach((input) => {
            url = url.replace('__' + input.dataset.param + '__', encodeURIComponent(input.value));
        });

        let body;

        try {
            body = values();
        } catch {
            alert('One of the list fields is not valid JSON.');
            return;
        }

        const isGet = form.dataset.method === 'GET';

        if (isGet) {
            url += (url.includes('?') ? '&' : '?') + new URLSearchParams(body).toString();
        }

        const response = await fetch(url, {
            method: form.dataset.method,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: isGet ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => null);

        if (response.status === 422) {
            Object.entries(data?.errors ?? {}).forEach(([key, messages]) => {
                const element = form.querySelector('small[data-error="' + key + '"]');

                if (element) element.textContent = messages[0];
            });

            return;
        }

        document.getElementById('result').hidden = false;
        document.getElementById('response').textContent = JSON.stringify(data, null, 2);

        if (response.ok) {
            fields.disabled = true;
            editButton.textContent = 'Edit';
        }
    }

    document.getElementById('submit').addEventListener('click', submit);
    form.addEventListener('submit', (event) => { event.preventDefault(); submit(); });
</script>
@endverbatim
</body>
</html>

BLADE;
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
            $attributes = 'data-path="'.$this->html($path).'"';

            if ($field['type'] === 'object') {
                $parts[] = implode("\n", [
                    "{$indent}<fieldset>",
                    "{$indent}    <legend>{$label}</legend>",
                    $this->inputs((array) ($field['children'] ?? []), $path.'.', $depth + 1),
                    "{$indent}</fieldset>",
                ]);

                continue;
            }

            $enum = $this->rules->enumValues((string) ($field['enum'] ?? ''));

            $control = match (true) {
                $field['type'] === 'array' => "<textarea {$attributes} data-type=\"json\"></textarea>",
                $field['type'] === 'boolean' => "<input type=\"checkbox\" {$attributes}>",
                $enum !== [] => "<select {$attributes}>".implode('', array_map(fn (string $value): string => '<option value="'.$this->html($value).'">'.$this->html($value).'</option>', $enum)).'</select>',
                in_array($field['type'], ['integer', 'number'], true) => "<input type=\"number\" step=\"any\" {$attributes} data-type=\"number\">",
                default => '<input type="'.$this->inputType((string) $field['type'])."\" {$attributes}>",
            };

            $parts[] = implode("\n", [
                "{$indent}<label".($field['type'] === 'array' ? ' class="wide"' : '').'>',
                "{$indent}    <span>{$label}</span>",
                "{$indent}    {$control}",
                "{$indent}    <small data-error=\"".$this->html($path).'"></small>',
                "{$indent}</label>",
            ]);
        }

        return implode("\n", $parts);
    }

    /**
     * Get the Blade view name of an endpoint's form page.
     *
     * @param  array<string, mixed>  $endpoint
     */
    protected function view(array $endpoint, string $separator): string
    {
        return $this->names['slug'].$separator.Str::kebab($endpoint['method']).'-form';
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

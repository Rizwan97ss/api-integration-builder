import type {
    AuthSettings,
    EndpointDefinition,
    FieldDefinition,
    FieldType,
    HttpMethod,
    ImportedField,
    ImportResult,
    IntegrationDefinition,
    KeyValue,
} from '@/types/integration';

let uidCounter = 0;

function uid(): string {
    uidCounter += 1;

    return `${Date.now().toString(36)}-${uidCounter}`;
}

export const httpMethods: HttpMethod[] = [
    'GET',
    'POST',
    'PUT',
    'PATCH',
    'DELETE',
];

export const methodSeverity: Record<HttpMethod, string> = {
    GET: 'info',
    POST: 'success',
    PUT: 'warn',
    PATCH: 'contrast',
    DELETE: 'danger',
};

export const scalarTypes: FieldType[] = [
    'string',
    'integer',
    'number',
    'boolean',
    'email',
    'url',
    'uuid',
    'date',
    'datetime',
];

export function defaultAuth(): AuthSettings {
    return {
        type: 'none',
        prefix: '',
        location: 'header',
        header_name: 'X-API-Key',
        param_name: 'api_key',
        token_column: 'token',
        key_column: 'api_key',
        username_column: 'user_name',
        password_column: 'password',
        headers: [],
    };
}

export function newField(
    overrides: Partial<FieldDefinition> = {},
): FieldDefinition {
    return {
        uid: uid(),
        name: '',
        type: 'string',
        required: false,
        nullable: false,
        rules: '',
        enum: '',
        description: '',
        example: null,
        item_type: null,
        condition: null,
        source: 'input',
        credential: null,
        value: '',
        children: [],
        ...overrides,
    };
}

export function newEndpoint(
    overrides: Partial<EndpointDefinition> = {},
): EndpointDefinition {
    return {
        uid: uid(),
        name: '',
        method: 'GET',
        path: '/',
        description: '',
        body_format: 'json',
        store_response: false,
        model: '',
        requires_auth: true,
        create_form: false,
        provides_token: false,
        token_path: 'access_token',
        expires_path: 'expires_in',
        token_ttl: null,
        base_url_column: null,
        basic_username: null,
        basic_password: null,
        autoName: true,
        headers: [],
        query: [],
        body: [],
        test: {
            path_params: {},
            query: '{}',
            body: '{}',
            headers: [],
            skip_validation: false,
        },
        lastTest: null,
        ...overrides,
    };
}

export function newDefinition(): IntegrationDefinition {
    return {
        provider_id: null,
        name: '',
        base_url_column: null,
        auth: defaultAuth(),
        options: {
            timeout: 30,
            retries: 2,
            idempotency_header: '',
            frontend: 'vue',
            http_client: 'http',
        },
        endpoints: [],
    };
}

/**
 * Give imported fields the client-side identifiers the editor needs.
 */
export function hydrateFields(fields: ImportedField[]): FieldDefinition[] {
    return fields.map((field) =>
        newField({
            ...field,
            source: field.source ?? 'input',
            credential: field.credential ?? null,
            value: field.value ?? '',
            children: hydrateFields(field.children ?? []),
        }),
    );
}

export function hydrateEndpoints(
    endpoints: ImportResult['endpoints'],
): EndpointDefinition[] {
    return endpoints.map((endpoint) => {
        const hydrated = newEndpoint({
            ...endpoint,
            autoName: false,
            query: hydrateFields(endpoint.query ?? []),
            body: hydrateFields(endpoint.body ?? []),
        });

        hydrated.test.query = JSON.stringify(
            sampleFromFields(hydrated.query),
            null,
            2,
        );
        hydrated.test.body = JSON.stringify(
            sampleFromFields(hydrated.body),
            null,
            2,
        );

        return hydrated;
    });
}

function studly(value: string): string {
    return value
        .replace(/[^A-Za-z0-9]+/g, ' ')
        .trim()
        .split(' ')
        .filter(Boolean)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join('');
}

function singular(word: string): string {
    if (/ies$/i.test(word)) {
        return word.replace(/ies$/i, 'y');
    }

    if (/(?:ss|us)$/i.test(word) || !/s$/i.test(word)) {
        return word;
    }

    if (/(?:ss|sh|ch|x|z)es$/i.test(word)) {
        return word.slice(0, -2);
    }

    return word.slice(0, -1);
}

/**
 * Convert an endpoint name into the generated PHP method name.
 */
export function methodName(name: string): string {
    const pascal = studly(name);

    return pascal.charAt(0).toLowerCase() + pascal.slice(1);
}

function lastResource(path: string): string {
    const segments = path
        .split('/')
        .filter(
            (segment) =>
                segment && !segment.startsWith('{') && !/^v\d+$/i.test(segment),
        );

    return segments.at(-1) ?? '';
}

/**
 * Suggest a readable endpoint name from its HTTP method and path,
 * e.g. POST /corporates → "Create corporate", GET /corporates → "List corporates".
 */
export function suggestEndpointName(
    method: HttpMethod,
    path: string,
    taken: string[] = [],
): string {
    const resource = lastResource(path)
        .replace(/[^A-Za-z0-9]+/g, ' ')
        .trim();
    const endsWithParameter = /\}\/?$/.test(path.trim());

    const verb =
        method === 'GET'
            ? endsWithParameter
                ? 'Get'
                : 'List'
            : {
                  POST: 'Create',
                  PUT: 'Update',
                  PATCH: 'Update',
                  DELETE: 'Delete',
              }[method];

    const noun = verb === 'List' ? resource : singular(resource);
    const base = noun ? `${verb} ${noun.toLowerCase()}` : `${verb} request`;
    const used = new Set(taken.map((name) => name.toLowerCase()));

    let candidate = base;

    for (
        let suffix = 2;
        used.has(methodName(candidate).toLowerCase());
        suffix++
    ) {
        candidate = `${base} ${suffix}`;
    }

    return candidate;
}

/**
 * Mirror the server's default storage model name, e.g. WeavrCorporate.
 */
export function defaultModelName(
    integration: string,
    endpoint: EndpointDefinition,
): string {
    const resource = lastResource(endpoint.path) || endpoint.name || 'Record';

    return studly(integration) + singular(studly(resource));
}

export function pathParameters(path: string): string[] {
    return [
        ...new Set(
            [...path.matchAll(/\{([A-Za-z_][A-Za-z0-9_]*)\}/g)].map(
                (match) => match[1],
            ),
        ),
    ];
}

function stripField(field: FieldDefinition): Record<string, unknown> {
    return {
        name: field.name,
        type: field.type,
        required: field.required,
        nullable: field.nullable,
        rules: field.rules || null,
        enum: field.enum || null,
        description: field.description || null,
        item_type:
            field.type === 'array' && field.children.length === 0
                ? field.item_type
                : null,
        condition: field.condition?.field ? field.condition : null,
        source: field.source,
        credential: field.source === 'credential' ? field.credential : null,
        value: field.source === 'fixed' ? field.value : null,
        children:
            field.type === 'object' || field.type === 'array'
                ? field.children.map(stripField)
                : [],
    };
}

function stripHeaders(headers: KeyValue[]): KeyValue[] {
    return headers.filter((header) => header.key.trim() !== '');
}

/**
 * Serialize an endpoint into the shape the server validates.
 */
export function endpointPayload(
    endpoint: EndpointDefinition,
): Record<string, unknown> {
    return {
        name: endpoint.name,
        method: endpoint.method,
        path: endpoint.path,
        description: endpoint.description || null,
        body_format: endpoint.body_format,
        store_response: endpoint.provides_token
            ? false
            : endpoint.store_response,
        requires_auth: endpoint.provides_token ? false : endpoint.requires_auth,
        create_form: endpoint.provides_token ? false : endpoint.create_form,
        provides_token: endpoint.provides_token,
        token_path: endpoint.provides_token
            ? endpoint.token_path || null
            : null,
        expires_path: endpoint.provides_token
            ? endpoint.expires_path || null
            : null,
        token_ttl: endpoint.provides_token ? endpoint.token_ttl : null,
        base_url_column: endpoint.provides_token
            ? endpoint.base_url_column
            : null,
        basic_username: endpoint.provides_token
            ? endpoint.basic_username
            : null,
        basic_password: endpoint.provides_token
            ? endpoint.basic_password
            : null,
        model:
            endpoint.store_response && endpoint.model ? endpoint.model : null,
        headers: stripHeaders(endpoint.headers),
        query: endpoint.query.map(stripField),
        body: endpoint.body.map(stripField),
    };
}

export function authPayload(auth: AuthSettings): Record<string, unknown> {
    return { ...auth, headers: stripHeaders(auth.headers) };
}

export function definitionPayload(
    definition: IntegrationDefinition,
): Record<string, unknown> {
    return {
        provider_id: definition.provider_id,
        name: definition.name,
        base_url_column: definition.base_url_column,
        auth: authPayload(definition.auth),
        options: definition.options,
        endpoints: definition.endpoints.map(endpointPayload),
    };
}

function sampleValue(field: FieldDefinition): unknown {
    if (
        field.example !== null &&
        field.example !== '' &&
        field.type !== 'object' &&
        field.type !== 'array'
    ) {
        if (field.type === 'integer' || field.type === 'number') {
            return Number(field.example);
        }

        if (field.type === 'boolean') {
            return field.example === 'true';
        }

        return field.example;
    }

    const firstEnum = field.enum
        .split(',')
        .map((value) => value.trim())
        .find(Boolean);

    if (firstEnum) {
        return firstEnum;
    }

    switch (field.type) {
        case 'integer':
            return 1;
        case 'number':
            return 1.5;
        case 'boolean':
            return true;
        case 'email':
            return 'user@example.com';
        case 'url':
            return 'https://example.com';
        case 'uuid':
            return '00000000-0000-4000-8000-000000000000';
        case 'date':
            return new Date().toISOString().slice(0, 10);
        case 'datetime':
            return new Date().toISOString();
        case 'object':
            return sampleFromFields(field.children);
        case 'array':
            return field.children.length > 0
                ? [sampleFromFields(field.children)]
                : [
                      sampleValue(
                          newField({ type: field.item_type ?? 'string' }),
                      ),
                  ];
        default:
            return field.name ? `sample ${field.name}` : 'sample';
    }
}

/**
 * Build sample test input from field definitions, skipping conditional fields.
 */
export function sampleFromFields(
    fields: FieldDefinition[],
): Record<string, unknown> {
    return Object.fromEntries(
        fields
            .filter(
                (field) =>
                    field.name &&
                    field.source === 'input' &&
                    !field.condition?.field &&
                    field.type !== 'file',
            )
            .map((field) => [field.name, sampleValue(field)]),
    );
}

/**
 * Count validation errors whose key starts with a prefix.
 */
export function countErrors(
    errors: Record<string, string>,
    prefix: string,
): number {
    return Object.keys(errors).filter(
        (key) => key === prefix || key.startsWith(`${prefix}.`),
    ).length;
}

export function parseJson(text: string): {
    value: Record<string, unknown> | null;
    error: string | null;
} {
    if (text.trim() === '') {
        return { value: {}, error: null };
    }

    try {
        const value: unknown = JSON.parse(text);

        if (
            value === null ||
            typeof value !== 'object' ||
            Array.isArray(value)
        ) {
            return {
                value: null,
                error: 'Enter a JSON object, for example {"key": "value"}.',
            };
        }

        return { value: value as Record<string, unknown>, error: null };
    } catch (error) {
        return { value: null, error: (error as Error).message };
    }
}

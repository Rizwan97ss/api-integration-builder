export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

export type FieldType =
    | 'string'
    | 'integer'
    | 'number'
    | 'boolean'
    | 'object'
    | 'array'
    | 'email'
    | 'url'
    | 'uuid'
    | 'date'
    | 'datetime'
    | 'file';

export type BodyFormat = 'json' | 'form' | 'multipart';

export type AuthType =
    | 'none'
    | 'bearer'
    | 'api_key'
    | 'basic'
    | 'token_endpoint';

export type FieldSource = 'input' | 'credential' | 'fixed';

export type KeyValue = { key: string; value: string };

export type FieldCondition = {
    field: string;
    operator: 'equals' | 'not_equals' | 'filled';
    value: string;
};

export type FieldDefinition = {
    uid: string;
    name: string;
    type: FieldType;
    required: boolean;
    nullable: boolean;
    rules: string;
    enum: string;
    description: string;
    example: string | null;
    item_type: FieldType | null;
    condition: FieldCondition | null;
    source: FieldSource;
    credential: string | null;
    value: string;
    children: FieldDefinition[];
};

export type EndpointTestInput = {
    path_params: Record<string, string>;
    query: string;
    body: string;
    headers: KeyValue[];
    skip_validation: boolean;
};

export type EndpointDefinition = {
    uid: string;
    name: string;
    method: HttpMethod;
    path: string;
    description: string;
    body_format: BodyFormat;
    store_response: boolean;
    requires_auth: boolean;
    create_form: boolean;
    provides_token: boolean;
    token_path: string;
    expires_path: string;
    token_ttl: number | null;
    base_url_column: string | null;
    basic_username: string | null;
    basic_password: string | null;
    model: string;
    autoName: boolean;
    headers: KeyValue[];
    query: FieldDefinition[];
    body: FieldDefinition[];
    test: EndpointTestInput;
    lastTest: TestResult | null;
};

export type AuthSettings = {
    type: AuthType;
    prefix: string;
    location: 'header' | 'query';
    header_name: string;
    param_name: string;
    token_column: string;
    key_column: string;
    username_column: string;
    password_column: string;
    headers: KeyValue[];
};

export type IntegrationDefinition = {
    provider_id: string | null;
    name: string;
    base_url_column: string | null;
    auth: AuthSettings;
    options: {
        timeout: number;
        retries: number;
        idempotency_header: string;
        frontend: 'vue' | 'react' | 'blade';
        http_client: 'http' | 'guzzle';
    };
    endpoints: EndpointDefinition[];
};

export type Provider = {
    id: string;
    name: string;
    url: string | null;
    base_url: string | null;
    auth_url: string | null;
    is_live: boolean;
    default_base_url_column: string | null;
    credentials: Record<string, boolean>;
};

export type TestResult = {
    success: boolean;
    message: string;
    duration_ms: number;
    status?: number;
    reason?: string;
    validation_errors?: Record<string, string[]>;
    details?: Record<string, unknown>;
    request?: {
        method: string;
        url: string;
        query: Record<string, unknown>;
        headers: Record<string, string>;
        body: unknown;
    } | null;
    response?: {
        headers: Record<string, string>;
        content_type: string | null;
        size: number;
        truncated: boolean;
        body: unknown;
    };
};

export type ImportResult = {
    title: string | null;
    endpoints: Array<
        Omit<
            EndpointDefinition,
            'uid' | 'test' | 'lastTest' | 'query' | 'body'
        > & {
            query: ImportedField[];
            body: ImportedField[];
        }
    >;
    suggested_auth: Partial<AuthSettings> | null;
    servers: string[];
    warnings: string[];
};

export type ImportedField = Omit<
    FieldDefinition,
    'uid' | 'children' | 'source' | 'credential' | 'value'
> &
    Partial<Pick<FieldDefinition, 'source' | 'credential' | 'value'>> & {
        children: ImportedField[];
    };

export type GeneratedFile = {
    path: string;
    kind: 'controller' | 'routes' | 'model' | 'migration' | 'form';
    status: 'create' | 'update' | 'unchanged' | 'skip';
    contents: string;
    original: string | null;
    additions: string | null;
    added: string[];
    existing: string[];
    requires: string[];
    notes: string[];
    hash: string;
};

export type IntegrationInspection = {
    studly: string;
    controller: string;
    path: string;
    exists: boolean;
    generated: boolean;
    compatible: boolean;
    methods: string[];
};

export type GenerateResult = {
    results: Array<{ path: string; status: string; written: boolean }>;
};

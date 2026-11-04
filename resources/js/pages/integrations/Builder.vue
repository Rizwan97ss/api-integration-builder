<script setup lang="ts">
import { Head, useHttp } from '@inertiajs/vue3';
import Button from 'primevue/button';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import Step from 'primevue/step';
import StepList from 'primevue/steplist';
import StepPanel from 'primevue/steppanel';
import StepPanels from 'primevue/steppanels';
import Stepper from 'primevue/stepper';
import Tag from 'primevue/tag';
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import AuthSettings from '@/components/integrations/AuthSettings.vue';
import CodePreview from '@/components/integrations/CodePreview.vue';
import EndpointEditor from '@/components/integrations/EndpointEditor.vue';
import ImportDialog from '@/components/integrations/ImportDialog.vue';
import MethodTag from '@/components/integrations/MethodTag.vue';
import TestResultView from '@/components/integrations/TestResultView.vue';
import {
    authPayload,
    countErrors,
    defaultAuth,
    definitionPayload,
    endpointPayload,
    hydrateEndpoints,
    methodName,
    newDefinition,
    newEndpoint,
    suggestEndpointName,
} from '@/lib/integrationBuilder';
import {
    generate,
    inspect,
    preview,
    testAuth,
    testEndpoint,
} from '@/routes/integration-builder';
import type {
    AuthSettings as AuthSettingsType,
    AuthType,
    EndpointDefinition,
    FieldType,
    GeneratedFile,
    GenerateResult,
    ImportResult,
    IntegrationInspection,
    Provider,
    TestResult,
} from '@/types/integration';

type Props = {
    providers: Provider[];
    authTypes: AuthType[];
    fieldTypes: FieldType[];
    credentialColumns: string[];
    urlColumns: string[];
    credentialSource: string;
    canWriteFiles: boolean;
    defaultFrontend?: 'vue' | 'react' | 'blade';
};

const props = defineProps<Props>();

type TestInput = {
    path_params: Record<string, string>;
    query: Record<string, unknown>;
    body: Record<string, unknown>;
    skip_validation: boolean;
};

const definition = reactive(newDefinition());

if (props.defaultFrontend) {
    definition.options.frontend = props.defaultFrontend;
}

const frontendOptions = [
    { label: 'Vue', value: 'vue' },
    { label: 'React', value: 'react' },
    { label: 'Blade', value: 'blade' },
];
const step = ref<string | number>('1');
const selectedUid = ref<string | null>(null);
const importVisible = ref(false);
const errors = ref<Record<string, string>>({});
const authResult = ref<TestResult | null>(null);
const authErrors = ref<Record<string, string>>({});
const testingUid = ref<string | null>(null);
const endpointTestErrors = ref<Record<string, string>>({});
const files = ref<GeneratedFile[]>([]);
const generatedFiles = ref<GenerateResult['results'] | null>(null);
const suggestedAuthType = ref<AuthType | null>(null);

const authHttp = useHttp<Record<string, never>, TestResult>({});
const endpointHttp = useHttp<Record<string, never>, TestResult>({});
const previewHttp = useHttp<
    Record<string, never>,
    { files: GeneratedFile[]; can_write: boolean }
>({});
const generateHttp = useHttp<Record<string, never>, GenerateResult>({});

const provider = computed(
    () =>
        props.providers.find(
            (candidate) => candidate.id === definition.provider_id,
        ) ?? null,
);

const providerOptions = computed(() =>
    props.providers.map((candidate) => ({
        label: candidate.name,
        value: candidate.id,
        hint: candidate.base_url ?? candidate.url ?? 'no URL',
    })),
);

const baseUrl = computed(() => {
    const column = definition.base_url_column as
        | 'url'
        | 'base_url'
        | 'auth_url'
        | null;

    return provider.value && column ? provider.value[column] : null;
});

const baseUrlOptions = computed(() =>
    props.urlColumns.map((column) => {
        const value =
            provider.value?.[column as 'url' | 'base_url' | 'auth_url'];

        return {
            label: value ? `${column} · ${value}` : `${column} · empty`,
            value: column,
            disabled: !value,
        };
    }),
);

const selectedIndex = computed(() =>
    definition.endpoints.findIndex(
        (endpoint) => endpoint.uid === selectedUid.value,
    ),
);
const selectedEndpoint = computed(
    () => definition.endpoints[selectedIndex.value] ?? null,
);

const inspection = ref<IntegrationInspection | null>(null);
const inspectHttp = useHttp<Record<string, never>, IntegrationInspection>({});
let inspectTimer: ReturnType<typeof setTimeout> | undefined;

const existingMethods = computed(() => inspection.value?.methods ?? []);
const controllerName = computed(
    () => inspection.value?.controller ?? 'IntegrationApiController',
);

function existsInController(endpoint: EndpointDefinition): boolean {
    const method = methodName(endpoint.name).toLowerCase();

    return existingMethods.value.some(
        (existing) => existing.toLowerCase() === method,
    );
}

/**
 * Look up which files and methods already exist for the integration name,
 * so new endpoints are added to the existing controller instead of
 * creating a new one.
 */
function runInspect(): void {
    const name = definition.name.trim();

    if (!/^[A-Za-z][A-Za-z0-9 _-]*$/.test(name)) {
        inspection.value = null;

        return;
    }

    inspectHttp.get(inspect.url({ query: { name } }), {
        onSuccess: (result) => {
            inspection.value = result;
        },
    });
}

watch(
    () => definition.name,
    () => {
        clearTimeout(inspectTimer);
        inspectTimer = setTimeout(runInspect, 400);
    },
);

/**
 * The endpoint whose response provides the auth token, if any.
 */
const tokenEndpoint = computed(() =>
    definition.auth.type === 'token_endpoint'
        ? (definition.endpoints.find((endpoint) => endpoint.provides_token) ??
          null)
        : null,
);

/**
 * Only one endpoint can provide the token: ticking another unticks the rest.
 */
watch(
    () => definition.endpoints.map((endpoint) => endpoint.provides_token),
    (current, previous) => {
        const ticked = current.findIndex(
            (value, position) => value && !previous[position],
        );

        if (ticked === -1) {
            return;
        }

        definition.endpoints.forEach((endpoint, position) => {
            endpoint.provides_token = position === ticked;
        });
    },
);

watch(
    () => definition.auth.type,
    (type) => {
        if (type !== 'token_endpoint') {
            definition.endpoints.forEach((endpoint) => {
                endpoint.provides_token = false;
            });
        }
    },
);

function otherEndpointNames(endpoint: EndpointDefinition): string[] {
    return definition.endpoints
        .filter((other) => other.uid !== endpoint.uid)
        .map((other) => methodName(other.name));
}

/**
 * Endpoints that match an existing controller method; they are skipped.
 */
const warnings = computed(() =>
    definition.endpoints.filter(existsInController).map((endpoint) => ({
        message: `${endpoint.name}: ${controllerName.value} already has ${methodName(endpoint.name)}(), so this endpoint will be skipped. Rename it to add a new method.`,
        step: '3',
        uid: endpoint.uid,
    })),
);

/**
 * Problems that can be detected before asking the server for a preview.
 */
const issues = computed(() => {
    const list: Array<{ message: string; step: string; uid?: string }> = [];

    if (!definition.provider_id) {
        list.push({ message: 'Select an API provider.', step: '1' });
    }

    if (!definition.name.trim()) {
        list.push({ message: 'Enter an integration name.', step: '1' });
    }

    if (provider.value && !baseUrl.value) {
        list.push({
            message: 'The selected base URL column is empty for this provider.',
            step: '1',
        });
    }

    if (definition.endpoints.length === 0) {
        list.push({ message: 'Add at least one endpoint.', step: '3' });
    }

    if (
        definition.auth.type === 'token_endpoint' &&
        !tokenEndpoint.value &&
        !inspection.value?.exists
    ) {
        list.push({
            message:
                'Add the endpoint that returns the token and tick "This endpoint provides the token".',
            step: '3',
        });
    }

    const seen = new Map<string, string>();

    for (const endpoint of definition.endpoints) {
        const label = endpoint.name || `${endpoint.method} ${endpoint.path}`;
        const method = methodName(endpoint.name).toLowerCase();

        if (!endpoint.name.trim()) {
            list.push({
                message: `${label}: give the endpoint a name.`,
                step: '3',
                uid: endpoint.uid,
            });
        } else if (seen.has(method)) {
            list.push({
                message: `${label}: generates the same method as "${seen.get(method)}".`,
                step: '3',
                uid: endpoint.uid,
            });
        } else {
            seen.set(method, endpoint.name);
        }

        if (/^\s*(?:[a-z]+:)?\/\//i.test(endpoint.path)) {
            list.push({
                message: `${label}: the path must be relative to the base URL.`,
                step: '3',
                uid: endpoint.uid,
            });
        }

        const unnamed = [...endpoint.query, ...endpoint.body].some(
            (field) => !field.name.trim(),
        );

        if (unnamed) {
            list.push({
                message: `${label}: every field needs a name.`,
                step: '3',
                uid: endpoint.uid,
            });
        }
    }

    return list;
});

const serverIssues = computed(() =>
    Object.entries(errors.value).map(([key, message]) => {
        const match = key.match(/^endpoints\.(\d+)/);
        const endpoint = match
            ? definition.endpoints[Number(match[1])]
            : undefined;

        return {
            key,
            message: endpoint
                ? `${endpoint.name || 'Endpoint'}: ${message}`
                : message,
            step: endpoint
                ? '3'
                : key.startsWith('auth')
                  ? '2'
                  : key.startsWith('approved')
                    ? '4'
                    : '1',
            uid: endpoint?.uid,
        };
    }),
);

function suggestAuth(candidate: Provider): Partial<AuthSettingsType> {
    const has = (column: string): boolean =>
        candidate.credentials[column] ?? false;

    if (candidate.auth_url && has('api_key') && has('secret_key')) {
        return { type: 'token_endpoint', header_name: 'Authorization' };
    }

    if (has('token')) {
        return { type: 'bearer', token_column: 'token' };
    }

    if (has('user_name') && has('password')) {
        return { type: 'basic' };
    }

    if (has('api_key')) {
        return { type: 'api_key', key_column: 'api_key' };
    }

    return { type: 'none' };
}

function selectProvider(id: string): void {
    const previous = provider.value;

    definition.provider_id = id;

    const current = provider.value;

    if (!current) {
        return;
    }

    if (!definition.name || definition.name === previous?.name) {
        definition.name = current.name;
    }

    definition.base_url_column = current.default_base_url_column;

    const suggestion = suggestAuth(current);
    Object.assign(definition.auth, defaultAuth(), suggestion);
    suggestedAuthType.value = suggestion.type ?? null;
    authResult.value = null;
}

function addEndpoint(): void {
    const endpoint = newEndpoint({
        method: 'POST',
        name: suggestEndpointName('POST', '/', [
            ...existingMethods.value,
            ...definition.endpoints.map((other) => methodName(other.name)),
        ]),
    });

    definition.endpoints.push(endpoint);
    selectedUid.value = endpoint.uid;
}

function duplicateEndpoint(endpoint: EndpointDefinition): void {
    const copy = JSON.parse(
        JSON.stringify(endpointPayload(endpoint)),
    ) as ImportResult['endpoints'][number];
    const [duplicate] = hydrateEndpoints([
        { ...copy, name: `${endpoint.name} copy` },
    ]);

    definition.endpoints.splice(selectedIndex.value + 1, 0, duplicate);
    selectedUid.value = duplicate.uid;
}

function removeEndpoint(position: number): void {
    const [removed] = definition.endpoints.splice(position, 1);

    if (removed.uid === selectedUid.value) {
        selectedUid.value =
            definition.endpoints[Math.max(0, position - 1)]?.uid ?? null;
    }
}

function applyImport(result: ImportResult): void {
    const endpoints = hydrateEndpoints(result.endpoints);

    definition.endpoints.push(...endpoints);
    selectedUid.value = endpoints[0]?.uid ?? selectedUid.value;

    if (result.suggested_auth && definition.auth.type === 'none') {
        Object.assign(definition.auth, result.suggested_auth);
        suggestedAuthType.value = result.suggested_auth.type ?? null;
        toast.info(
            `Authentication set to "${result.suggested_auth.type}" from the document.`,
        );
    }

    toast.success(`${endpoints.length} endpoint(s) imported`);
}

function connectionPayload(): Record<string, unknown> {
    return {
        provider_id: definition.provider_id,
        base_url_column: definition.base_url_column,
        auth: {
            ...authPayload(definition.auth),
            ...(tokenEndpoint.value
                ? { endpoint: endpointPayload(tokenEndpoint.value) }
                : {}),
        },
    };
}

function handleHttpException(response: {
    status: number;
    data?: unknown;
}): void {
    const message = (response.data as { message?: string } | undefined)
        ?.message;

    toast.error(
        response.status === 429
            ? 'Too many test requests; wait a minute.'
            : (message ?? `Request failed (${response.status}).`),
    );
}

function runAuthTest(): void {
    authErrors.value = {};

    authHttp.transform(connectionPayload).post(testAuth.url(), {
        onSuccess: (result) => {
            authResult.value = result;
        },
        onError: (validationErrors) => {
            authErrors.value = validationErrors;
        },
        onHttpException: handleHttpException,
    });
}

function runEndpointTest(endpoint: EndpointDefinition, input: TestInput): void {
    endpointTestErrors.value = {};
    testingUid.value = endpoint.uid;

    endpointHttp
        .transform(() => ({
            ...connectionPayload(),
            endpoint: endpointPayload(endpoint),
            input: {
                ...input,
                headers: endpoint.test.headers.filter(
                    (header) => header.key.trim() !== '',
                ),
            },
        }))
        .post(testEndpoint.url(), {
            onSuccess: (result) => {
                endpoint.lastTest = result;
            },
            onError: (validationErrors) => {
                endpointTestErrors.value = validationErrors;
            },
            onHttpException: handleHttpException,
            onFinish: () => {
                testingUid.value = null;
            },
        });
}

function jumpTo(issue: { step: string; uid?: string }): void {
    step.value = issue.step;

    if (issue.uid) {
        selectedUid.value = issue.uid;
    }
}

function runPreview(): void {
    errors.value = {};
    generatedFiles.value = null;

    previewHttp
        .transform(() => definitionPayload(definition))
        .post(preview.url(), {
            onSuccess: (response) => {
                files.value = response.files;
            },
            onError: (validationErrors) => {
                errors.value = validationErrors;
                files.value = [];
                toast.error(
                    `Fix ${Object.keys(validationErrors).length} issue(s) before generating code.`,
                );
            },
            onHttpException: handleHttpException,
        });
}

watch(step, (value) => {
    if (value === '4' && issues.value.length === 0) {
        runPreview();
    }
});

function runGenerate(): void {
    errors.value = {};

    generateHttp
        .transform(() => ({
            ...definitionPayload(definition),
            approved: files.value
                .filter(
                    (file) =>
                        file.status === 'create' || file.status === 'update',
                )
                .map((file) => ({ path: file.path, hash: file.hash })),
        }))
        .post(generate.url(), {
            onSuccess: (response) => {
                const written = response.results.filter(
                    (result) => result.written,
                ).length;

                generatedFiles.value = response.results;
                notify(
                    'success',
                    `Code generated: ${written} file(s) written.`,
                );
                runInspect();
                runPreview();
            },
            onError: (validationErrors) => {
                errors.value = validationErrors;
                notify(
                    'error',
                    `Generation failed: ${Object.values(validationErrors)[0] ?? 'check the highlighted fields.'}`,
                );
            },
            onHttpException: (response) => {
                const message = (
                    response.data as { message?: string } | undefined
                )?.message;

                notify(
                    'error',
                    `Generation failed: ${message ?? `request failed (${response.status}).`}`,
                );
            },
        });
}

const FLASH_KEY = 'integration-builder:toast';

/**
 * Show a toast and keep it for one page load: writing routes/web.php makes
 * the Vite dev server reload the page, which would otherwise drop it.
 */
function notify(type: 'success' | 'error', message: string): void {
    toast[type](message);
    sessionStorage.setItem(FLASH_KEY, JSON.stringify({ type, message }));
    setTimeout(() => sessionStorage.removeItem(FLASH_KEY), 3000);
}

onMounted(async () => {
    const stored = sessionStorage.getItem(FLASH_KEY);

    if (!stored) {
        return;
    }

    sessionStorage.removeItem(FLASH_KEY);
    await nextTick();

    const flash = JSON.parse(stored) as {
        type: 'success' | 'error';
        message: string;
    };

    toast[flash.type](flash.message);
});
</script>

<template>
    <Head title="API Integration Builder" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4 md:p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <header class="mb-8 space-y-0.5">
                <h2 class="text-xl font-semibold tracking-tight">
                    API Integration Builder
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        `Configure a provider from ${credentialSource}, describe its endpoints, test them live, then generate Laravel code.`
                    }}
                </p>
            </header>
            <div class="flex flex-wrap items-center gap-2">
                <Tag
                    v-if="provider"
                    :value="provider.name"
                    icon="pi pi-link"
                    severity="secondary"
                />
                <Tag
                    :value="`${definition.endpoints.length} endpoint(s)`"
                    severity="secondary"
                />
                <Tag
                    :value="
                        canWriteFiles ? 'File writes enabled' : 'Preview only'
                    "
                    :severity="canWriteFiles ? 'success' : 'warn'"
                    :icon="canWriteFiles ? 'pi pi-pencil' : 'pi pi-eye'"
                />
            </div>
        </div>

        <Stepper v-model:value="step" class="flex-1">
            <StepList class="overflow-x-auto">
                <Step value="1">Provider</Step>
                <Step value="2">Authentication</Step>
                <Step value="3">Endpoints</Step>
                <Step value="4">Review &amp; generate</Step>
            </StepList>

            <StepPanels class="!bg-transparent !px-0">
                <StepPanel value="1">
                    <div class="grid gap-6 lg:grid-cols-[1fr_24rem]">
                        <section class="space-y-5 rounded-xl border p-5">
                            <div class="flex flex-col gap-1">
                                <label class="text-sm font-medium"
                                    >API provider</label
                                >
                                <Select
                                    :model-value="definition.provider_id"
                                    :options="providerOptions"
                                    option-label="label"
                                    option-value="value"
                                    placeholder="Choose a configured provider"
                                    filter
                                    class="w-full"
                                    :invalid="!!errors.provider_id"
                                    @update:model-value="selectProvider"
                                >
                                    <template #option="{ option }">
                                        <div
                                            class="flex w-full items-center justify-between gap-4"
                                        >
                                            <span>{{ option.label }}</span>
                                            <small
                                                class="truncate text-muted-foreground"
                                                >{{ option.hint }}</small
                                            >
                                        </div>
                                    </template>
                                </Select>
                                <small
                                    v-if="errors.provider_id"
                                    class="text-red-600 dark:text-red-400"
                                    >{{ errors.provider_id }}</small
                                >
                                <small v-else class="text-muted-foreground">
                                    Providers come from {{ credentialSource }};
                                    credentials stay there and are read at
                                    runtime.
                                </small>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm font-medium"
                                        >Integration name</label
                                    >
                                    <InputText
                                        v-model="definition.name"
                                        placeholder="Acme Payments"
                                        :invalid="!!errors.name"
                                    />
                                    <small
                                        v-if="errors.name"
                                        class="text-red-600 dark:text-red-400"
                                        >{{ errors.name }}</small
                                    >
                                    <small v-else class="text-muted-foreground"
                                        >Used for class names, e.g.
                                        AcmePaymentsApiController.</small
                                    >
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm font-medium"
                                        >Base URL column</label
                                    >
                                    <Select
                                        v-model="definition.base_url_column"
                                        :options="baseUrlOptions"
                                        option-label="label"
                                        option-value="value"
                                        option-disabled="disabled"
                                        placeholder="Select a provider first"
                                        :disabled="!provider"
                                    />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm font-medium"
                                        >Timeout (seconds)</label
                                    >
                                    <InputNumber
                                        v-model="definition.options.timeout"
                                        :min="1"
                                        :max="120"
                                        show-buttons
                                    />
                                </div>
                                <div class="flex flex-col gap-1">
                                    <label class="text-sm font-medium"
                                        >Retries for GET requests</label
                                    >
                                    <InputNumber
                                        v-model="definition.options.retries"
                                        :min="0"
                                        :max="5"
                                        show-buttons
                                    />
                                </div>
                                <div class="flex flex-col gap-1 md:col-span-2">
                                    <label class="text-sm font-medium"
                                        >Idempotency header (optional)</label
                                    >
                                    <InputText
                                        v-model="
                                            definition.options
                                                .idempotency_header
                                        "
                                        placeholder="idempotency-ref"
                                        class="font-mono md:w-1/2"
                                        :invalid="
                                            !!errors[
                                                'options.idempotency_header'
                                            ]
                                        "
                                    />
                                    <small class="text-muted-foreground"
                                        >When set, POST, PUT, PATCH and DELETE
                                        calls send a fresh Str::uuid() in this
                                        header.</small
                                    >
                                </div>
                                <div class="flex flex-col gap-1 md:col-span-2">
                                    <label class="text-sm font-medium"
                                        >Form pages are generated as</label
                                    >
                                    <SelectButton
                                        v-model="definition.options.frontend"
                                        :options="frontendOptions"
                                        option-label="label"
                                        option-value="value"
                                        :allow-empty="false"
                                    />
                                    <small class="text-muted-foreground"
                                        >Used for endpoints with "Create form
                                        page" ticked. Blade pages need no
                                        JavaScript framework.</small
                                    >
                                </div>
                            </div>

                            <Message
                                v-if="inspection && !inspection.compatible"
                                severity="error"
                                size="small"
                            >
                                {{ inspection.path }} exists but has no
                                getAuthToken() and request() methods, so new
                                methods can't be added to it. Choose another
                                integration name.
                            </Message>
                            <Message
                                v-else-if="inspection"
                                :severity="
                                    inspection.exists ? 'info' : 'secondary'
                                "
                                size="small"
                            >
                                <template v-if="inspection.exists">
                                    {{ inspection.controller }} already exists.
                                    New endpoints are added to it as new
                                    methods<template
                                        v-if="inspection.methods.length"
                                    >
                                        (it has:
                                        {{
                                            inspection.methods
                                                .map((method) => `${method}()`)
                                                .join(', ')
                                        }})</template
                                    >.
                                </template>
                                <template v-else>
                                    {{ inspection.controller }} will be created
                                    with the API details loaded from
                                    {{ credentialSource }}, headers(),
                                    getAuthToken() and request() helpers, then
                                    one method per endpoint you add, including
                                    your login or token endpoint.
                                </template>
                            </Message>
                        </section>

                        <aside class="space-y-4 rounded-xl border p-5">
                            <template v-if="provider">
                                <div class="flex items-center justify-between">
                                    <h3 class="font-semibold">
                                        {{ provider.name }}
                                    </h3>
                                    <Tag
                                        :value="
                                            provider.is_live
                                                ? 'Live'
                                                : 'Sandbox'
                                        "
                                        :severity="
                                            provider.is_live ? 'danger' : 'info'
                                        "
                                    />
                                </div>
                                <dl class="space-y-2 text-sm">
                                    <div
                                        v-for="column in urlColumns"
                                        :key="column"
                                    >
                                        <dt
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ column }}
                                        </dt>
                                        <dd class="truncate font-mono text-xs">
                                            {{
                                                provider[column as 'url'] ?? '—'
                                            }}
                                        </dd>
                                    </div>
                                </dl>
                                <div>
                                    <p
                                        class="mb-2 text-xs text-muted-foreground"
                                    >
                                        Stored credentials
                                    </p>
                                    <div class="flex flex-wrap gap-1">
                                        <Tag
                                            v-for="(
                                                filled, column
                                            ) in provider.credentials"
                                            :key="column"
                                            :value="column"
                                            :icon="
                                                filled
                                                    ? 'pi pi-check'
                                                    : 'pi pi-minus'
                                            "
                                            :severity="
                                                filled ? 'success' : 'secondary'
                                            "
                                            class="!text-[0.7rem]"
                                        />
                                    </div>
                                </div>
                                <Message
                                    v-if="provider.is_live"
                                    severity="warn"
                                    size="small"
                                >
                                    This provider is marked live. Endpoint tests
                                    send real requests.
                                </Message>
                            </template>
                            <div
                                v-else
                                class="flex h-full min-h-40 flex-col items-center justify-center gap-2 text-center text-sm text-muted-foreground"
                            >
                                <i class="pi pi-database text-2xl"></i>
                                <p v-if="providers.length">
                                    Select a provider to see its configuration.
                                </p>
                                <p v-else>
                                    No providers found in
                                    {{ credentialSource }}.
                                </p>
                            </div>
                        </aside>
                    </div>

                    <div class="mt-4 flex justify-end">
                        <Button
                            label="Next: authentication"
                            icon="pi pi-arrow-right"
                            icon-pos="right"
                            :disabled="!provider"
                            @click="step = '2'"
                        />
                    </div>
                </StepPanel>

                <StepPanel value="2">
                    <div class="grid gap-6 lg:grid-cols-[1fr_26rem]">
                        <section class="rounded-xl border p-5">
                            <Message
                                v-if="suggestedAuthType"
                                severity="info"
                                size="small"
                                class="mb-4"
                            >
                                Pre-selected "{{ suggestedAuthType }}" based on
                                the credentials this provider has. Adjust if
                                needed.
                            </Message>
                            <AuthSettings
                                v-model="definition.auth"
                                :provider="provider"
                                :auth-types="authTypes"
                                :credential-columns="credentialColumns"
                                :errors="{ ...errors, ...authErrors }"
                            />
                        </section>

                        <aside class="space-y-4 rounded-xl border p-5">
                            <div>
                                <h3 class="font-semibold">
                                    Test authentication
                                </h3>
                                <p class="text-sm text-muted-foreground">
                                    Checks the credentials can be applied, or
                                    calls your token endpoint and shows the
                                    masked token.
                                </p>
                            </div>
                            <Button
                                label="Test authentication"
                                icon="pi pi-shield"
                                :loading="authHttp.processing"
                                :disabled="!provider"
                                @click="runAuthTest"
                            />
                            <Message
                                v-for="(message, key) in authErrors"
                                :key="key"
                                severity="error"
                                size="small"
                                >{{ message }}</Message
                            >
                            <TestResultView
                                v-if="authResult"
                                :result="authResult"
                            />
                        </aside>
                    </div>

                    <div class="mt-4 flex justify-between">
                        <Button
                            label="Back"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            outlined
                            @click="step = '1'"
                        />
                        <Button
                            label="Next: endpoints"
                            icon="pi pi-arrow-right"
                            icon-pos="right"
                            @click="step = '3'"
                        />
                    </div>
                </StepPanel>

                <StepPanel value="3">
                    <div class="grid gap-6 lg:grid-cols-[19rem_1fr]">
                        <aside class="space-y-3">
                            <div class="flex gap-2">
                                <Button
                                    label="Add"
                                    icon="pi pi-plus"
                                    size="small"
                                    class="flex-1"
                                    @click="addEndpoint"
                                />
                                <Button
                                    label="Import"
                                    icon="pi pi-upload"
                                    size="small"
                                    severity="secondary"
                                    outlined
                                    class="flex-1"
                                    @click="importVisible = true"
                                />
                            </div>

                            <div
                                v-if="definition.endpoints.length"
                                class="divide-y overflow-hidden rounded-lg border"
                            >
                                <div
                                    v-for="(
                                        endpoint, position
                                    ) in definition.endpoints"
                                    :key="endpoint.uid"
                                    class="group flex cursor-pointer items-center gap-2 px-3 py-2 transition-colors"
                                    :class="
                                        endpoint.uid === selectedUid
                                            ? 'bg-muted'
                                            : 'hover:bg-muted/50'
                                    "
                                    @click="selectedUid = endpoint.uid"
                                >
                                    <MethodTag :method="endpoint.method" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">
                                            <i
                                                v-if="
                                                    existsInController(endpoint)
                                                "
                                                v-tooltip.top="
                                                    'Method already exists in the controller; will be skipped'
                                                "
                                                class="pi pi-exclamation-triangle mr-1 text-xs text-amber-600"
                                            ></i>
                                            <i
                                                v-if="
                                                    endpoint.uid ===
                                                    tokenEndpoint?.uid
                                                "
                                                v-tooltip.top="
                                                    'Provides the auth token'
                                                "
                                                class="pi pi-key mr-1 text-xs text-emerald-600"
                                            ></i>
                                            {{ endpoint.name || 'Untitled' }}
                                        </p>
                                        <p
                                            class="truncate font-mono text-xs text-muted-foreground"
                                        >
                                            {{ endpoint.path }}
                                        </p>
                                    </div>
                                    <span
                                        v-if="
                                            countErrors(
                                                errors,
                                                `endpoints.${position}`,
                                            )
                                        "
                                        class="rounded-full bg-red-600 px-1.5 text-xs text-white"
                                    >
                                        {{
                                            countErrors(
                                                errors,
                                                `endpoints.${position}`,
                                            )
                                        }}
                                    </span>
                                    <i
                                        v-else-if="endpoint.lastTest"
                                        class="pi text-xs"
                                        :class="
                                            endpoint.lastTest.success
                                                ? 'pi-check-circle text-emerald-600'
                                                : 'pi-times-circle text-red-600'
                                        "
                                    ></i>
                                    <div
                                        class="flex opacity-0 transition-opacity group-hover:opacity-100"
                                    >
                                        <Button
                                            icon="pi pi-clone"
                                            severity="secondary"
                                            text
                                            size="small"
                                            aria-label="Duplicate"
                                            @click.stop="
                                                duplicateEndpoint(endpoint)
                                            "
                                        />
                                        <Button
                                            icon="pi pi-trash"
                                            severity="danger"
                                            text
                                            size="small"
                                            aria-label="Remove"
                                            @click.stop="
                                                removeEndpoint(position)
                                            "
                                        />
                                    </div>
                                </div>
                            </div>
                        </aside>

                        <section class="min-w-0 rounded-xl border p-5">
                            <EndpointEditor
                                v-if="selectedEndpoint"
                                :key="selectedEndpoint.uid"
                                v-model="definition.endpoints[selectedIndex]"
                                :field-types="fieldTypes"
                                :base-url="baseUrl"
                                :errors="errors"
                                :error-prefix="`endpoints.${selectedIndex}`"
                                :testing="testingUid === selectedEndpoint.uid"
                                :test-errors="endpointTestErrors"
                                :can-test="!!provider"
                                :integration-name="definition.name"
                                :controller-name="controllerName"
                                :existing-methods="existingMethods"
                                :taken-names="
                                    otherEndpointNames(selectedEndpoint)
                                "
                                :auth-type="definition.auth.type"
                                :provider="provider"
                                :credential-columns="credentialColumns"
                                :url-columns="urlColumns"
                                @test="
                                    (input) =>
                                        runEndpointTest(
                                            selectedEndpoint!,
                                            input,
                                        )
                                "
                            />
                            <div
                                v-else
                                class="flex min-h-80 flex-col items-center justify-center gap-4 text-center"
                            >
                                <i
                                    class="pi pi-sitemap text-3xl text-muted-foreground"
                                ></i>
                                <div>
                                    <p class="font-medium">
                                        No endpoint selected
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Add endpoints by hand, or import them
                                        from sample JSON or an OpenAPI document.
                                    </p>
                                </div>
                                <div class="flex gap-2">
                                    <Button
                                        label="Add endpoint"
                                        icon="pi pi-plus"
                                        @click="addEndpoint"
                                    />
                                    <Button
                                        label="Import"
                                        icon="pi pi-upload"
                                        severity="secondary"
                                        outlined
                                        @click="importVisible = true"
                                    />
                                </div>
                            </div>
                        </section>
                    </div>

                    <div class="mt-4 flex justify-between">
                        <Button
                            label="Back"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            outlined
                            @click="step = '2'"
                        />
                        <Button
                            label="Next: review"
                            icon="pi pi-arrow-right"
                            icon-pos="right"
                            @click="step = '4'"
                        />
                    </div>
                </StepPanel>

                <StepPanel value="4">
                    <div class="space-y-4">
                        <section class="rounded-xl border p-5">
                            <div
                                class="flex flex-wrap items-center justify-between gap-3"
                            >
                                <div>
                                    <h3 class="font-semibold">
                                        Generate Laravel code
                                    </h3>
                                    <p class="text-sm text-muted-foreground">
                                        Each endpoint becomes a method in
                                        {{ controllerName }} with its validation
                                        inside, and a route in routes/web.php{{
                                            definition.endpoints.some(
                                                (endpoint) =>
                                                    endpoint.store_response,
                                            )
                                                ? ', plus a model and migration where data is stored'
                                                : ''
                                        }}.
                                    </p>
                                </div>
                                <Button
                                    label="Refresh"
                                    icon="pi pi-refresh"
                                    severity="secondary"
                                    outlined
                                    :loading="previewHttp.processing"
                                    :disabled="issues.length > 0"
                                    @click="runPreview"
                                />
                            </div>

                            <div
                                v-if="issues.length || serverIssues.length"
                                class="mt-4 space-y-2"
                            >
                                <button
                                    v-for="issue in [
                                        ...issues,
                                        ...serverIssues,
                                    ]"
                                    :key="issue.message"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-left text-sm text-red-800 hover:bg-red-100 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300"
                                    @click="jumpTo(issue)"
                                >
                                    <i class="pi pi-exclamation-circle"></i>
                                    <span class="flex-1">{{
                                        issue.message
                                    }}</span>
                                    <i class="pi pi-arrow-right text-xs"></i>
                                </button>
                            </div>

                            <div v-if="warnings.length" class="mt-4 space-y-2">
                                <button
                                    v-for="warning in warnings"
                                    :key="warning.uid"
                                    type="button"
                                    class="flex w-full items-center gap-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-left text-sm text-amber-800 hover:bg-amber-100 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300"
                                    @click="jumpTo(warning)"
                                >
                                    <i class="pi pi-exclamation-triangle"></i>
                                    <span class="flex-1">{{
                                        warning.message
                                    }}</span>
                                    <i class="pi pi-arrow-right text-xs"></i>
                                </button>
                            </div>

                            <Message
                                v-if="generatedFiles"
                                severity="success"
                                class="mt-4"
                            >
                                <p class="font-medium">Files generated</p>
                                <ul class="mt-1 list-disc pl-5 text-sm">
                                    <li
                                        v-for="result in generatedFiles"
                                        :key="result.path"
                                    >
                                        <code class="font-mono">{{
                                            result.path
                                        }}</code>
                                        —
                                        {{
                                            result.written
                                                ? result.status
                                                : 'no changes'
                                        }}
                                    </li>
                                </ul>
                                <p class="mt-2 text-sm">
                                    Routes were added to
                                    <code class="font-mono">routes/web.php</code
                                    >.
                                    <template
                                        v-if="
                                            generatedFiles.some((result) =>
                                                result.path.startsWith(
                                                    'database/migrations',
                                                ),
                                            )
                                        "
                                    >
                                        Run
                                        <code class="font-mono"
                                            >php artisan migrate</code
                                        >
                                        to create the response table.
                                    </template>
                                </p>
                            </Message>
                        </section>

                        <section
                            v-if="files.length"
                            class="rounded-xl border p-5"
                        >
                            <CodePreview
                                :files="files"
                                :can-write="canWriteFiles"
                                :generating="generateHttp.processing"
                                @generate="runGenerate"
                            />
                        </section>
                    </div>

                    <div class="mt-4 flex justify-start">
                        <Button
                            label="Back"
                            icon="pi pi-arrow-left"
                            severity="secondary"
                            outlined
                            @click="step = '3'"
                        />
                    </div>
                </StepPanel>
            </StepPanels>
        </Stepper>

        <ImportDialog v-model:visible="importVisible" @imported="applyImport" />
    </div>
</template>

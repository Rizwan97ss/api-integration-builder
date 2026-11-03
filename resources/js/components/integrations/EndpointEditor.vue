<script setup lang="ts">
import Badge from 'primevue/badge';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputGroup from 'primevue/inputgroup';
import InputGroupAddon from 'primevue/inputgroupaddon';
import InputNumber from 'primevue/inputnumber';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EndpointTestPanel from '@/components/integrations/EndpointTestPanel.vue';
import FieldEditor from '@/components/integrations/FieldEditor.vue';
import PasteJsonDialog from '@/components/integrations/PasteJsonDialog.vue';
import {
    countErrors,
    defaultModelName,
    httpMethods,
    methodName,
    pathParameters,
    suggestEndpointName,
} from '@/lib/integrationBuilder';
import type {
    AuthType,
    EndpointDefinition,
    FieldDefinition,
    FieldType,
    Provider,
} from '@/types/integration';

type Props = {
    fieldTypes: FieldType[];
    baseUrl: string | null;
    errors?: Record<string, string>;
    errorPrefix: string;
    testing: boolean;
    testErrors?: Record<string, string>;
    canTest: boolean;
    integrationName: string;
    controllerName: string;
    existingMethods?: string[];
    takenNames?: string[];
    authType: AuthType;
    provider: Provider | null;
    credentialColumns: string[];
    urlColumns: string[];
};

const props = withDefaults(defineProps<Props>(), {
    errors: () => ({}),
    testErrors: () => ({}),
    existingMethods: () => [],
    takenNames: () => [],
});

defineEmits<{
    test: [
        input: {
            path_params: Record<string, string>;
            query: Record<string, unknown>;
            body: Record<string, unknown>;
            skip_validation: boolean;
        },
    ];
}>();

const endpoint = defineModel<EndpointDefinition>({ required: true });

const methodOptions = httpMethods.map((method) => ({
    label: method,
    value: method,
}));

const parameters = computed(() => pathParameters(endpoint.value.path));

const generatedMethod = computed(() => methodName(endpoint.value.name));

const alreadyExists = computed(() =>
    props.existingMethods.some(
        (method) =>
            method.toLowerCase() === generatedMethod.value.toLowerCase(),
    ),
);

const modelPlaceholder = computed(() =>
    defaultModelName(props.integrationName, endpoint.value),
);

const canProvideToken = computed(() => props.authType === 'token_endpoint');

const providesToken = computed(
    () => canProvideToken.value && endpoint.value.provides_token,
);

const urlOptions = computed(() =>
    props.urlColumns.map((column) => {
        const value =
            props.provider?.[column as 'url' | 'base_url' | 'auth_url'];

        return {
            value: column,
            label: value ? `${column} · ${value}` : `${column} · empty`,
            disabled: !value,
        };
    }),
);

/**
 * Keep auto-named endpoints in sync with their method and path until the
 * user types a name of their own.
 */
/**
 * GET requests send fields as query parameters; other methods send a body.
 * Move fields along when the method changes so they are sent correctly.
 */
watch(
    () => endpoint.value.method,
    (method, previous) => {
        const wasGet = previous === 'GET';
        const isGet = method === 'GET';

        if (
            !wasGet &&
            isGet &&
            endpoint.value.query.length === 0 &&
            endpoint.value.body.length > 0
        ) {
            endpoint.value.query = endpoint.value.body;
            endpoint.value.body = [];
            endpoint.value.test.query = endpoint.value.test.body;
            endpoint.value.test.body = '{}';
            toast.info('Body fields moved to query parameters for GET.');
        }

        if (
            wasGet &&
            !isGet &&
            endpoint.value.body.length === 0 &&
            endpoint.value.query.length > 0
        ) {
            endpoint.value.body = endpoint.value.query;
            endpoint.value.query = [];
            endpoint.value.test.body = endpoint.value.test.query;
            endpoint.value.test.query = '{}';
            toast.info(
                `Query parameters moved to the request body for ${method}.`,
            );
        }
    },
);

watch(
    () => [endpoint.value.method, endpoint.value.path] as const,
    ([method, path]) => {
        if (endpoint.value.autoName) {
            endpoint.value.name = suggestEndpointName(method, path, [
                ...props.existingMethods,
                ...props.takenNames,
            ]);
        }
    },
);

function renameManually(value: string | undefined): void {
    endpoint.value.name = value ?? '';
    endpoint.value.autoName = false;
}
const isAbsolute = computed(() =>
    /^\s*(?:[a-z]+:)?\/\//i.test(endpoint.value.path),
);

const pasteTarget = ref<'body' | 'query'>('body');
const pasteVisible = ref(false);

function openPaste(target: 'body' | 'query'): void {
    pasteTarget.value = target;
    pasteVisible.value = true;
}

/**
 * Apply fields converted from pasted JSON and reuse the sample as test input.
 */
function applyPasted(payload: {
    fields: FieldDefinition[];
    mode: 'replace' | 'append';
    sample: string;
    warnings: string[];
}): void {
    const target = pasteTarget.value;
    let added = payload.fields;

    if (payload.mode === 'replace') {
        endpoint.value[target] = payload.fields;
    } else {
        const existing = new Set(
            endpoint.value[target].map((field) => field.name.toLowerCase()),
        );

        added = payload.fields.filter(
            (field) => !existing.has(field.name.toLowerCase()),
        );
        endpoint.value[target].push(...added);
    }

    endpoint.value.test[target] = payload.sample;

    toast.success(
        `${added.length} field(s) added to ${target === 'body' ? 'the body' : 'query parameters'}`,
        { description: payload.warnings.join(' ') || undefined },
    );
}

function error(key: string): string | undefined {
    return props.errors[`${props.errorPrefix}.${key}`];
}

function errorCount(key: string): number {
    return countErrors(props.errors, `${props.errorPrefix}.${key}`);
}
</script>

<template>
    <div class="space-y-5">
        <div class="grid gap-4 md:grid-cols-[1fr_auto]">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Endpoint name</label>
                <InputText
                    :model-value="endpoint.name"
                    placeholder="Create customer"
                    :invalid="!!error('name') || alreadyExists"
                    @update:model-value="renameManually"
                />
                <small
                    v-if="error('name')"
                    class="text-red-600 dark:text-red-400"
                    >{{ error('name') }}</small
                >
                <small
                    v-else-if="alreadyExists"
                    class="text-amber-700 dark:text-amber-400"
                >
                    {{ controllerName }} already has
                    <code class="font-mono">{{ generatedMethod }}()</code>; it
                    will be left as it is. Rename this endpoint to add a new
                    method.
                </small>
                <small v-else class="text-muted-foreground">
                    Adds
                    <code class="font-mono"
                        >{{ generatedMethod || '…' }}()</code
                    >
                    to {{ controllerName }}
                    <template v-if="providesToken"
                        >, which fetches a fresh token and caches it for the
                        other methods,</template
                    >
                    <template v-else
                        >(with its validation rules inside)</template
                    >
                    and a route in routes/web.php.
                    <template v-if="!providesToken && endpoint.requires_auth"
                        >It calls getAuthToken() first.</template
                    >
                    <template v-if="endpoint.autoName"
                        >Named automatically from the method and path.</template
                    >
                </small>
            </div>
            <div class="flex flex-col gap-2 pt-7 text-sm">
                <label v-if="!providesToken" class="flex items-start gap-2">
                    <Checkbox v-model="endpoint.requires_auth" binary />
                    Requires auth token
                </label>
                <label v-if="canProvideToken" class="flex items-start gap-2">
                    <Checkbox v-model="endpoint.provides_token" binary />
                    This endpoint provides the token
                </label>
            </div>
        </div>

        <div
            v-if="providesToken"
            class="grid gap-4 rounded-lg border bg-muted/30 p-4 md:grid-cols-2"
        >
            <Message severity="info" size="small" class="md:col-span-2">
                The token is fetched automatically, so set every field below to
                a credential column or a fixed value (open a field's settings).
                <small
                    v-if="error('provides_token')"
                    class="mt-1 block text-red-600 dark:text-red-400"
                    >{{ error('provides_token') }}</small
                >
            </Message>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium"
                    >Token path in response</label
                >
                <InputText
                    v-model="endpoint.token_path"
                    placeholder="access_token"
                    class="font-mono"
                    :invalid="!!error('token_path')"
                />
                <small class="text-muted-foreground"
                    >Dot notation, e.g. data.token</small
                >
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium"
                    >Expiry path in response</label
                >
                <InputText
                    v-model="endpoint.expires_path"
                    placeholder="expires_in"
                    class="font-mono"
                    :invalid="!!error('expires_path')"
                />
                <small class="text-muted-foreground"
                    >Seconds until the token expires.</small
                >
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium"
                    >Fallback lifetime (seconds)</label
                >
                <InputNumber
                    v-model="endpoint.token_ttl"
                    placeholder="3600"
                    :min="60"
                    :use-grouping="false"
                    :invalid="!!error('token_ttl')"
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Send to</label>
                <Select
                    v-model="endpoint.base_url_column"
                    :options="urlOptions"
                    option-label="label"
                    option-value="value"
                    option-disabled="disabled"
                    placeholder="API base URL"
                    show-clear
                />
                <small class="text-muted-foreground"
                    >Pick auth_url when tokens come from a separate auth
                    server.</small
                >
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium"
                    >HTTP Basic username (optional)</label
                >
                <Select
                    v-model="endpoint.basic_username"
                    :options="credentialColumns"
                    placeholder="Not sent"
                    show-clear
                />
            </div>
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">HTTP Basic password</label>
                <Select
                    v-model="endpoint.basic_password"
                    :options="credentialColumns"
                    :disabled="!endpoint.basic_username"
                    :invalid="!!error('basic_password')"
                    placeholder="Credential column"
                />
                <small class="text-muted-foreground"
                    >For OAuth servers that want the client ID and secret in a
                    Basic header.</small
                >
            </div>
        </div>

        <div class="flex flex-col gap-1">
            <label class="text-sm font-medium">Request</label>
            <InputGroup>
                <Select
                    v-model="endpoint.method"
                    :options="methodOptions"
                    option-label="label"
                    option-value="value"
                    class="!w-32 font-mono"
                />
                <InputGroupAddon
                    v-if="baseUrl"
                    class="max-w-64 truncate font-mono text-xs"
                    >{{ baseUrl }}</InputGroupAddon
                >
                <InputText
                    v-model="endpoint.path"
                    placeholder="/customers/{id}"
                    class="font-mono"
                    :invalid="!!error('path') || isAbsolute"
                />
            </InputGroup>
            <small
                v-if="error('path')"
                class="text-red-600 dark:text-red-400"
                >{{ error('path') }}</small
            >
            <small
                v-else-if="isAbsolute"
                class="text-red-600 dark:text-red-400"
            >
                Use a path relative to the provider base URL; credentials are
                never sent to other hosts.
            </small>
            <small v-else class="text-muted-foreground">
                Wrap path parameters in braces.
                <template v-if="parameters.length"
                    >Detected: {{ parameters.join(', ') }}.</template
                >
            </small>
        </div>

        <div class="grid gap-4 md:grid-cols-[1fr_auto]">
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Description</label>
                <InputText
                    v-model="endpoint.description"
                    placeholder="Used in the generated PHPDoc"
                />
            </div>
            <div
                v-if="!providesToken"
                class="flex flex-col justify-end gap-2 pb-2 text-sm"
            >
                <label class="flex items-center gap-2">
                    <Checkbox v-model="endpoint.store_response" binary />
                    Store data locally (adds model + migration)
                </label>
                <label class="flex items-center gap-2">
                    <Checkbox v-model="endpoint.create_form" binary />
                    Create form page (read-only until Edit is clicked)
                </label>
            </div>
        </div>

        <div
            v-if="endpoint.store_response && !providesToken"
            class="grid gap-4 rounded-lg border bg-muted/30 p-4 md:grid-cols-[1fr_1fr]"
        >
            <div class="flex flex-col gap-1">
                <label class="text-sm font-medium">Model name</label>
                <InputText
                    v-model="endpoint.model"
                    :placeholder="modelPlaceholder"
                    class="font-mono"
                    :invalid="!!error('model')"
                />
                <small
                    v-if="error('model')"
                    class="text-red-600 dark:text-red-400"
                    >{{ error('model') }}</small
                >
                <small v-else class="text-muted-foreground"
                    >Leave empty to use {{ modelPlaceholder }}. Endpoints with
                    the same model share one table.</small
                >
            </div>
            <div class="text-sm text-muted-foreground">
                <p class="font-medium text-foreground">Table columns</p>
                <p>
                    One column per top-level body field ({{
                        endpoint.body.length
                    }}); nested objects and arrays are stored as JSON. The API
                    response and its <code class="font-mono">id</code> are saved
                    too.
                </p>
            </div>
        </div>

        <Tabs value="body">
            <TabList>
                <Tab value="query" class="flex items-center gap-2">
                    Query params
                    <Badge
                        :value="endpoint.query.length"
                        severity="secondary"
                    />
                    <Badge
                        v-if="errorCount('query')"
                        :value="errorCount('query')"
                        severity="danger"
                    />
                </Tab>
                <Tab value="body" class="flex items-center gap-2">
                    Body fields
                    <Badge :value="endpoint.body.length" severity="secondary" />
                    <Badge
                        v-if="errorCount('body')"
                        :value="errorCount('body')"
                        severity="danger"
                    />
                </Tab>
                <Tab value="test" class="flex items-center gap-2">
                    <i class="pi pi-play"></i> Test
                    <i
                        v-if="endpoint.lastTest"
                        class="pi text-xs"
                        :class="
                            endpoint.lastTest.success
                                ? 'pi-check-circle text-emerald-600'
                                : 'pi-times-circle text-red-600'
                        "
                    ></i>
                </Tab>
            </TabList>
            <TabPanels class="!px-0">
                <TabPanel value="query">
                    <div class="mb-3 flex justify-end">
                        <Button
                            label="Paste JSON"
                            icon="pi pi-clipboard"
                            size="small"
                            severity="secondary"
                            outlined
                            @click="openPaste('query')"
                        />
                    </div>
                    <FieldEditor
                        v-model="endpoint.query"
                        :field-types="fieldTypes"
                        :errors="errors"
                        :error-prefix="`${errorPrefix}.query`"
                        :credential-columns="credentialColumns"
                        :credentials-set="provider?.credentials"
                        empty-text="No query parameters. Add filters, pagination or search fields here."
                    />
                </TabPanel>
                <TabPanel value="body">
                    <Message
                        v-if="endpoint.method === 'GET' && endpoint.body.length"
                        severity="warn"
                        size="small"
                        class="mb-3"
                    >
                        GET requests do not send a body; move these fields to
                        query parameters.
                    </Message>
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <p class="text-sm text-muted-foreground">
                            Build fields by hand, or paste an example payload to
                            create them all at once.
                        </p>
                        <Button
                            label="Paste JSON"
                            icon="pi pi-clipboard"
                            size="small"
                            @click="openPaste('body')"
                        />
                    </div>
                    <FieldEditor
                        v-model="endpoint.body"
                        :field-types="fieldTypes"
                        :errors="errors"
                        :error-prefix="`${errorPrefix}.body`"
                        :credential-columns="credentialColumns"
                        :credentials-set="provider?.credentials"
                    />
                </TabPanel>
                <TabPanel value="test">
                    <EndpointTestPanel
                        v-model="endpoint"
                        :processing="testing"
                        :disabled="!canTest"
                        :errors="testErrors"
                        @run="(input) => $emit('test', input)"
                    />
                </TabPanel>
            </TabPanels>
        </Tabs>

        <PasteJsonDialog
            v-model:visible="pasteVisible"
            :target="pasteTarget"
            :method="endpoint.method"
            :path="endpoint.path"
            :has-fields="endpoint[pasteTarget].length > 0"
            @apply="applyPasted"
        />
    </div>
</template>

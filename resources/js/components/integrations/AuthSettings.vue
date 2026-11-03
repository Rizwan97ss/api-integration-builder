<script setup lang="ts">
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import SelectButton from 'primevue/selectbutton';
import { computed, watch } from 'vue';
import KeyValueEditor from '@/components/integrations/KeyValueEditor.vue';
import type { AuthSettings, AuthType, Provider } from '@/types/integration';

type Props = {
    provider: Provider | null;
    authTypes: AuthType[];
    credentialColumns: string[];
    errors?: Record<string, string>;
};

const props = withDefaults(defineProps<Props>(), {
    errors: () => ({}),
});

const auth = defineModel<AuthSettings>({ required: true });

const descriptions: Record<AuthType, { label: string; description: string }> = {
    none: {
        label: 'No authentication',
        description: 'Requests are sent without credentials.',
    },
    bearer: {
        label: 'Bearer token',
        description: 'Sends a stored token as "Authorization: Bearer …".',
    },
    api_key: {
        label: 'API key',
        description: 'Sends a stored key in a header or query parameter.',
    },
    basic: {
        label: 'HTTP Basic',
        description: 'Sends a stored username and password using Basic auth.',
    },
    token_endpoint: {
        label: 'Token from an endpoint',
        description:
            'One of your endpoints (login, OAuth token, …) returns a token; it is cached and sent with the other requests.',
    },
};

const typeOptions = computed(() =>
    props.authTypes.map((type) => ({ value: type, ...descriptions[type] })),
);

const current = computed(() => descriptions[auth.value.type]);

const credentialOptions = computed(() =>
    props.credentialColumns.map((column) => {
        const filled = props.provider?.credentials[column] ?? false;

        return {
            value: column,
            label: `${column} ${filled ? '· set' : '· empty'}`,
            filled,
        };
    }),
);

const usesUsernamePassword = computed(() => auth.value.type === 'basic');

/**
 * api_key and token_endpoint share header_name; swap the default when switching.
 */
watch(
    () => auth.value.type,
    (type) => {
        if (
            type === 'token_endpoint' &&
            auth.value.header_name === 'X-API-Key'
        ) {
            auth.value.header_name = 'Authorization';
        }

        if (type === 'api_key' && auth.value.header_name === 'Authorization') {
            auth.value.header_name = 'X-API-Key';
        }
    },
);

const emptyColumns = computed(() => {
    const columns: Array<keyof AuthSettings> = [];

    if (auth.value.type === 'bearer') {
        columns.push('token_column');
    }

    if (auth.value.type === 'api_key') {
        columns.push('key_column');
    }

    if (usesUsernamePassword.value) {
        columns.push('username_column', 'password_column');
    }

    return columns
        .map((key) => auth.value[key] as string)
        .filter(
            (column) =>
                column && props.provider && !props.provider.credentials[column],
        );
});

function error(key: string): string | undefined {
    return props.errors[`auth.${key}`];
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-col gap-2">
            <label class="text-sm font-medium">Authentication method</label>
            <Select
                v-model="auth.type"
                :options="typeOptions"
                option-label="label"
                option-value="value"
                class="w-full md:w-96"
                :invalid="!!error('type')"
            >
                <template #option="{ option }">
                    <div class="flex flex-col">
                        <span>{{ option.label }}</span>
                        <small class="text-muted-foreground">{{
                            option.description
                        }}</small>
                    </div>
                </template>
            </Select>
            <small class="text-muted-foreground">{{
                current.description
            }}</small>
        </div>

        <Message v-if="emptyColumns.length > 0" severity="warn" size="small">
            The selected provider has no value in:
            {{ emptyColumns.join(', ') }}. Requests will fail until these values
            are filled in.
        </Message>

        <div class="grid gap-4 md:grid-cols-2">
            <template v-if="auth.type === 'bearer'">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Token column</label>
                    <Select
                        v-model="auth.token_column"
                        :options="credentialOptions"
                        option-label="label"
                        option-value="value"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Prefix</label>
                    <InputText
                        v-model="auth.prefix"
                        placeholder="Bearer"
                        :invalid="!!error('prefix')"
                    />
                    <small class="text-muted-foreground"
                        >Leave empty for "Bearer".</small
                    >
                </div>
            </template>

            <template v-if="auth.type === 'api_key'">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Key column</label>
                    <Select
                        v-model="auth.key_column"
                        :options="credentialOptions"
                        option-label="label"
                        option-value="value"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Send key in</label>
                    <SelectButton
                        v-model="auth.location"
                        :options="[
                            { label: 'Header', value: 'header' },
                            { label: 'Query string', value: 'query' },
                        ]"
                        option-label="label"
                        option-value="value"
                        :allow-empty="false"
                    />
                </div>
                <div
                    v-if="auth.location === 'header'"
                    class="flex flex-col gap-1"
                >
                    <label class="text-sm font-medium">Header name</label>
                    <InputText
                        v-model="auth.header_name"
                        class="font-mono"
                        :invalid="!!error('header_name')"
                    />
                </div>
                <div v-else class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Parameter name</label>
                    <InputText
                        v-model="auth.param_name"
                        class="font-mono"
                        :invalid="!!error('param_name')"
                    />
                </div>
                <div
                    v-if="auth.location === 'header'"
                    class="flex flex-col gap-1"
                >
                    <label class="text-sm font-medium">Value prefix</label>
                    <InputText
                        v-model="auth.prefix"
                        placeholder="optional, e.g. Token"
                        :invalid="!!error('prefix')"
                    />
                </div>
            </template>

            <template v-if="usesUsernamePassword">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Username column</label>
                    <Select
                        v-model="auth.username_column"
                        :options="credentialOptions"
                        option-label="label"
                        option-value="value"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Password column</label>
                    <Select
                        v-model="auth.password_column"
                        :options="credentialOptions"
                        option-label="label"
                        option-value="value"
                    />
                </div>
            </template>

            <template v-if="auth.type === 'token_endpoint'">
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Send the token in</label>
                    <InputText
                        v-model="auth.header_name"
                        placeholder="Authorization"
                        class="font-mono"
                        :invalid="!!error('header_name')"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <label class="text-sm font-medium">Prefix</label>
                    <InputText
                        v-model="auth.prefix"
                        placeholder="Bearer"
                        :invalid="!!error('prefix')"
                    />
                    <small class="text-muted-foreground"
                        >Leave empty for "Bearer" in the Authorization header,
                        or no prefix in any other header.</small
                    >
                </div>
                <Message severity="info" size="small" class="md:col-span-2">
                    Next, add your login or token endpoint like any other
                    endpoint and tick
                    <strong>This endpoint provides the token</strong>. Fill its
                    fields from credential columns or fixed values. Endpoints
                    marked "Requires auth token" then get a cached token, and a
                    fresh one once if the API answers 401.
                </Message>
            </template>
        </div>

        <div class="flex flex-col gap-2">
            <label class="text-sm font-medium"
                >Extra headers on every request</label
            >
            <small class="text-muted-foreground">
                Use <code class="font-mono">{tenant_id}</code> or any other
                credential column in braces to insert its value at runtime.
            </small>
            <KeyValueEditor
                v-model="auth.headers"
                value-placeholder="e.g. {tenant_id}"
                :errors="errors"
                error-prefix="auth.headers"
            />
        </div>
    </div>
</template>

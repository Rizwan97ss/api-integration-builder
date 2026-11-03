<script setup lang="ts">
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Textarea from 'primevue/textarea';
import { computed, ref } from 'vue';
import KeyValueEditor from '@/components/integrations/KeyValueEditor.vue';
import TestResultView from '@/components/integrations/TestResultView.vue';
import {
    parseJson,
    pathParameters,
    sampleFromFields,
} from '@/lib/integrationBuilder';
import type { EndpointDefinition } from '@/types/integration';

type Props = {
    processing: boolean;
    disabled?: boolean;
    errors?: Record<string, string>;
};

withDefaults(defineProps<Props>(), {
    disabled: false,
    errors: () => ({}),
});

const emit = defineEmits<{
    run: [
        input: {
            path_params: Record<string, string>;
            query: Record<string, unknown>;
            body: Record<string, unknown>;
            skip_validation: boolean;
        },
    ];
}>();

const endpoint = defineModel<EndpointDefinition>({ required: true });

const jsonError = ref<string | null>(null);

const parameters = computed(() => pathParameters(endpoint.value.path));
const sendsBody = computed(() => endpoint.value.method !== 'GET');

function fillSamples(): void {
    endpoint.value.test.query = JSON.stringify(
        sampleFromFields(endpoint.value.query),
        null,
        2,
    );
    endpoint.value.test.body = JSON.stringify(
        sampleFromFields(endpoint.value.body),
        null,
        2,
    );
}

function run(): void {
    const query = parseJson(endpoint.value.test.query);
    const body = parseJson(endpoint.value.test.body);

    jsonError.value = query.error
        ? `Query: ${query.error}`
        : body.error
          ? `Body: ${body.error}`
          : null;

    if (jsonError.value) {
        return;
    }

    emit('run', {
        path_params: Object.fromEntries(
            parameters.value.map((name) => [
                name,
                endpoint.value.test.path_params[name] ?? '',
            ]),
        ),
        query: query.value ?? {},
        body: sendsBody.value ? (body.value ?? {}) : {},
        skip_validation: endpoint.value.test.skip_validation,
    });
}
</script>

<template>
    <div class="grid gap-6 xl:grid-cols-2">
        <div class="space-y-4">
            <div v-if="parameters.length > 0" class="space-y-2">
                <p class="text-sm font-medium">Path parameters</p>
                <div
                    v-for="name in parameters"
                    :key="name"
                    class="flex items-center gap-2"
                >
                    <code class="w-32 truncate font-mono text-xs">{{
                        name
                    }}</code>
                    <InputText
                        :model-value="endpoint.test.path_params[name] ?? ''"
                        size="small"
                        class="flex-1"
                        @update:model-value="
                            (value?: string) =>
                                (endpoint.test.path_params[name] = value ?? '')
                        "
                    />
                </div>
            </div>

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium">Query parameters (JSON)</p>
                    <Button
                        label="Fill samples"
                        icon="pi pi-sparkles"
                        size="small"
                        severity="secondary"
                        text
                        @click="fillSamples"
                    />
                </div>
                <Textarea
                    v-model="endpoint.test.query"
                    rows="4"
                    class="w-full font-mono text-xs"
                    auto-resize
                />
            </div>

            <div v-if="sendsBody" class="space-y-2">
                <p class="text-sm font-medium">Request body (JSON)</p>
                <Textarea
                    v-model="endpoint.test.body"
                    rows="8"
                    class="w-full font-mono text-xs"
                    auto-resize
                />
            </div>

            <div class="space-y-2">
                <p class="text-sm font-medium">Extra test headers</p>
                <KeyValueEditor
                    v-model="endpoint.test.headers"
                    empty-text="Endpoint headers are sent automatically."
                />
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox v-model="endpoint.test.skip_validation" binary />
                Send even if the input fails the field rules
            </label>

            <Message v-if="jsonError" severity="error" size="small">{{
                jsonError
            }}</Message>
            <Message
                v-for="(message, key) in errors"
                :key="key"
                severity="error"
                size="small"
                >{{ message }}</Message
            >

            <Button
                label="Send test request"
                icon="pi pi-send"
                :loading="processing"
                :disabled="disabled"
                @click="run"
            />
            <p v-if="disabled" class="text-xs text-muted-foreground">
                Select a provider first.
            </p>
        </div>

        <div>
            <TestResultView
                v-if="endpoint.lastTest"
                :result="endpoint.lastTest"
            />
            <div
                v-else
                class="flex h-full min-h-48 items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground"
            >
                Live responses from the provider appear here.
            </div>
        </div>
    </div>
</template>

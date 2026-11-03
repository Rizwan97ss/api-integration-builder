<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import InputText from 'primevue/inputtext';
import Message from 'primevue/message';
import Select from 'primevue/select';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import Textarea from 'primevue/textarea';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import MethodTag from '@/components/integrations/MethodTag.vue';
import { httpMethods } from '@/lib/integrationBuilder';
import { importMethod } from '@/routes/integration-builder';
import type { HttpMethod, ImportResult } from '@/types/integration';

const visible = defineModel<boolean>('visible', { required: true });

const emit = defineEmits<{
    imported: [result: ImportResult];
}>();

const source = ref<'sample_json' | 'openapi'>('sample_json');
const method = ref<HttpMethod>('POST');
const path = ref('/');
const name = ref('');
const sampleContent = ref('');
const specContent = ref('');
const errors = ref<Record<string, string>>({});
const result = ref<ImportResult | null>(null);
const selected = ref<number[]>([]);

const http = useHttp<Record<string, never>, ImportResult>({});

const methodOptions = httpMethods.map((value) => ({ label: value, value }));

const content = computed(() =>
    source.value === 'openapi' ? specContent.value : sampleContent.value,
);

watch(source, () => {
    result.value = null;
    errors.value = {};
});

function detect(): void {
    errors.value = {};

    http.transform(() => ({
        source: source.value,
        content: content.value,
        method: source.value === 'sample_json' ? method.value : null,
        path: source.value === 'sample_json' ? path.value : null,
        name: source.value === 'sample_json' && name.value ? name.value : null,
    })).post(importMethod.url(), {
        onSuccess: (response) => {
            result.value = response;
            selected.value = response.endpoints.map((_, index) => index);
        },
        onError: (validationErrors) => {
            errors.value = validationErrors;
        },
        onHttpException: () => {
            toast.error('The document could not be imported.');
        },
    });
}

function readFile(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    if (file.size > 2_000_000) {
        errors.value = { content: 'The document may not be larger than 2 MB.' };

        return;
    }

    void file.text().then((text) => {
        specContent.value = text;
    });
}

function addSelected(): void {
    if (!result.value) {
        return;
    }

    emit('imported', {
        ...result.value,
        endpoints: result.value.endpoints.filter((_, index) =>
            selected.value.includes(index),
        ),
    });

    visible.value = false;
    result.value = null;
    sampleContent.value = '';
    specContent.value = '';
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        header="Import endpoints"
        :style="{ width: '56rem' }"
        :breakpoints="{ '960px': '95vw' }"
    >
        <Tabs v-model:value="source">
            <TabList>
                <Tab value="sample_json"
                    ><i class="pi pi-code mr-2"></i>Sample JSON</Tab
                >
                <Tab value="openapi"
                    ><i class="pi pi-book mr-2"></i>OpenAPI / Swagger</Tab
                >
            </TabList>
            <TabPanels class="!px-0">
                <TabPanel value="sample_json">
                    <div class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Paste an example request body. Field names, types,
                            nesting and arrays are detected automatically. For
                            GET requests the fields become query parameters.
                        </p>
                        <div class="grid gap-3 md:grid-cols-[8rem_1fr_1fr]">
                            <Select
                                v-model="method"
                                :options="methodOptions"
                                option-label="label"
                                option-value="value"
                            />
                            <InputText
                                v-model="path"
                                placeholder="/customers"
                                class="font-mono"
                                :invalid="!!errors.path"
                            />
                            <InputText
                                v-model="name"
                                placeholder="Endpoint name (optional)"
                                :invalid="!!errors.name"
                            />
                        </div>
                        <small
                            v-if="errors.path || errors.name"
                            class="text-red-600 dark:text-red-400"
                            >{{ errors.path ?? errors.name }}</small
                        >
                        <Textarea
                            v-model="sampleContent"
                            rows="12"
                            class="w-full font-mono text-xs"
                            placeholder='{ "email": "jane@example.com", "address": { "city": "Paris" }, "items": [{ "sku": "A1", "qty": 2 }] }'
                            :invalid="!!errors.content"
                        />
                    </div>
                </TabPanel>
                <TabPanel value="openapi">
                    <div class="space-y-3">
                        <p class="text-sm text-muted-foreground">
                            Paste or upload an OpenAPI 3.x or Swagger 2.0
                            document (JSON; YAML when supported by the server).
                            Endpoints, parameters, request bodies, required
                            fields, formats, enums and authentication are
                            detected.
                        </p>
                        <input
                            type="file"
                            accept=".json,.yaml,.yml"
                            class="block text-sm file:mr-3 file:rounded-md file:border-0 file:bg-muted file:px-3 file:py-1.5 file:text-sm"
                            @change="readFile"
                        />
                        <Textarea
                            v-model="specContent"
                            rows="12"
                            class="w-full font-mono text-xs"
                            placeholder='{ "openapi": "3.0.0", "paths": { … } }'
                            :invalid="!!errors.content"
                        />
                    </div>
                </TabPanel>
            </TabPanels>
        </Tabs>

        <Message
            v-if="errors.content"
            severity="error"
            size="small"
            class="mt-2"
            >{{ errors.content }}</Message
        >

        <div v-if="result" class="mt-4 space-y-3">
            <div class="flex items-center justify-between">
                <p class="text-sm font-medium">
                    {{ result.title ? `${result.title}: ` : ''
                    }}{{ result.endpoints.length }} endpoint(s) detected
                </p>
                <span
                    v-if="result.suggested_auth"
                    class="text-xs text-muted-foreground"
                >
                    Suggested auth: {{ result.suggested_auth.type }}
                </span>
            </div>

            <Message
                v-for="warning in result.warnings"
                :key="warning"
                severity="warn"
                size="small"
                >{{ warning }}</Message
            >
            <Message v-if="result.servers.length" severity="info" size="small">
                Spec servers: {{ result.servers.join(', ') }}. Make sure the
                provider's base URL matches, and that paths are not prefixed
                twice.
            </Message>

            <div class="max-h-72 divide-y overflow-auto rounded-lg border">
                <label
                    v-for="(endpoint, index) in result.endpoints"
                    :key="index"
                    class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-muted/50"
                >
                    <Checkbox v-model="selected" :value="index" />
                    <MethodTag :method="endpoint.method" />
                    <span class="font-mono text-xs">{{ endpoint.path }}</span>
                    <span
                        class="ml-auto truncate text-xs text-muted-foreground"
                    >
                        {{ endpoint.name }} · {{ endpoint.query.length }} query
                        · {{ endpoint.body.length }} body
                    </span>
                </label>
            </div>
        </div>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                text
                @click="visible = false"
            />
            <Button
                v-if="!result"
                label="Detect endpoints"
                icon="pi pi-search"
                :loading="http.processing"
                :disabled="content.trim() === ''"
                @click="detect"
            />
            <template v-else>
                <Button
                    label="Back"
                    severity="secondary"
                    outlined
                    @click="result = null"
                />
                <Button
                    :label="`Add ${selected.length} endpoint(s)`"
                    icon="pi pi-check"
                    :disabled="selected.length === 0"
                    @click="addSelected"
                />
            </template>
        </template>
    </Dialog>
</template>

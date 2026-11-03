<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import Dialog from 'primevue/dialog';
import Message from 'primevue/message';
import SelectButton from 'primevue/selectbutton';
import Textarea from 'primevue/textarea';
import { computed, ref } from 'vue';
import { hydrateFields, parseJson } from '@/lib/integrationBuilder';
import { importMethod } from '@/routes/integration-builder';
import type {
    FieldDefinition,
    HttpMethod,
    ImportResult,
} from '@/types/integration';

type Props = {
    target: 'body' | 'query';
    method: HttpMethod;
    path: string;
    hasFields: boolean;
};

const props = defineProps<Props>();

const emit = defineEmits<{
    apply: [
        payload: {
            fields: FieldDefinition[];
            mode: 'replace' | 'append';
            sample: string;
            warnings: string[];
        },
    ];
}>();

const visible = defineModel<boolean>('visible', { required: true });

const content = ref('');
const mode = ref<'replace' | 'append'>('replace');
const markRequired = ref(true);
const error = ref<string | null>(null);

const http = useHttp<Record<string, never>, ImportResult>({});

const title = computed(() =>
    props.target === 'body'
        ? 'Paste a sample request body'
        : 'Paste sample query parameters',
);

function format(): void {
    const parsed = parseJson(content.value);

    if (parsed.value) {
        content.value = JSON.stringify(parsed.value, null, 2);
    }
}

function convert(): void {
    const parsed = parseJson(content.value);
    error.value = parsed.error;

    if (!parsed.value) {
        return;
    }

    http.transform(() => ({
        source: 'sample_json',
        content: content.value,
        method: props.method,
        path: props.path || '/',
        target: props.target,
        mark_required: markRequired.value,
    })).post(importMethod.url(), {
        onSuccess: (result) => {
            const endpoint = result.endpoints[0];

            emit('apply', {
                fields: hydrateFields(endpoint?.[props.target] ?? []),
                mode: mode.value,
                sample: JSON.stringify(parsed.value, null, 2),
                warnings: result.warnings,
            });

            visible.value = false;
            content.value = '';
        },
        onError: (errors) => {
            error.value =
                errors.content ?? Object.values(errors)[0] ?? 'Invalid JSON.';
        },
    });
}
</script>

<template>
    <Dialog
        v-model:visible="visible"
        modal
        :header="title"
        :style="{ width: '48rem' }"
        :breakpoints="{ '960px': '95vw' }"
    >
        <div class="space-y-3">
            <p class="text-sm text-muted-foreground">
                Paste an example payload from the provider's documentation.
                Every key becomes a field: nested objects become groups, arrays
                of objects become repeatable items, and types (text, number,
                boolean, email, URL, date) are detected from the values.
            </p>

            <Textarea
                v-model="content"
                rows="16"
                class="w-full font-mono text-xs"
                :invalid="!!error"
                placeholder='{
  "profileId": "10001",
  "rootUser": {
    "email": "alex@example.com",
    "dateOfBirth": { "year": 1990, "month": 1, "day": 1 }
  },
  "acceptedTerms": true
}'
                @blur="format"
            />

            <Message v-if="error" severity="error" size="small">{{
                error
            }}</Message>

            <div class="flex flex-wrap items-center gap-4">
                <SelectButton
                    v-if="hasFields"
                    v-model="mode"
                    :options="[
                        { label: 'Replace existing fields', value: 'replace' },
                        { label: 'Add new fields only', value: 'append' },
                    ]"
                    option-label="label"
                    option-value="value"
                    :allow-empty="false"
                    size="small"
                />
                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="markRequired" binary />
                    Mark every field as required
                </label>
            </div>

            <p class="text-xs text-muted-foreground">
                A sample can't tell which fields are optional or which values
                are fixed choices. After converting, untick "Required" where
                needed and add allowed values to fields such as
                <code class="font-mono">companyPosition</code>; those fields are
                flagged in their settings.
            </p>
        </div>

        <template #footer>
            <Button
                label="Cancel"
                severity="secondary"
                text
                @click="visible = false"
            />
            <Button
                label="Convert to fields"
                icon="pi pi-sitemap"
                :loading="http.processing"
                :disabled="content.trim() === ''"
                @click="convert"
            />
        </template>
    </Dialog>
</template>

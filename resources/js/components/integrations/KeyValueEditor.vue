<script setup lang="ts">
import Button from 'primevue/button';
import InputText from 'primevue/inputtext';
import type { KeyValue } from '@/types/integration';

type Props = {
    keyPlaceholder?: string;
    valuePlaceholder?: string;
    addLabel?: string;
    emptyText?: string;
    errors?: Record<string, string>;
    errorPrefix?: string;
};

const props = withDefaults(defineProps<Props>(), {
    keyPlaceholder: 'Header name',
    valuePlaceholder: 'Value',
    addLabel: 'Add header',
    emptyText: 'Nothing added yet.',
    errors: () => ({}),
    errorPrefix: '',
});

const rows = defineModel<KeyValue[]>({ required: true });

function error(index: number, part: 'key' | 'value'): string | undefined {
    return props.errorPrefix
        ? props.errors[`${props.errorPrefix}.${index}.${part}`]
        : undefined;
}
</script>

<template>
    <div class="space-y-2">
        <p v-if="rows.length === 0" class="text-sm text-muted-foreground">
            {{ emptyText }}
        </p>

        <div
            v-for="(row, index) in rows"
            :key="index"
            class="flex flex-col gap-1"
        >
            <div class="flex items-start gap-2">
                <InputText
                    v-model="row.key"
                    :placeholder="keyPlaceholder"
                    :invalid="!!error(index, 'key')"
                    size="small"
                    class="w-2/5 font-mono"
                />
                <InputText
                    v-model="row.value"
                    :placeholder="valuePlaceholder"
                    :invalid="!!error(index, 'value')"
                    size="small"
                    class="flex-1 font-mono"
                />
                <Button
                    icon="pi pi-trash"
                    severity="secondary"
                    text
                    size="small"
                    aria-label="Remove"
                    @click="rows.splice(index, 1)"
                />
            </div>
            <small
                v-if="error(index, 'key') || error(index, 'value')"
                class="text-red-600 dark:text-red-400"
            >
                {{ error(index, 'key') ?? error(index, 'value') }}
            </small>
        </div>

        <Button
            :label="addLabel"
            icon="pi pi-plus"
            severity="secondary"
            size="small"
            outlined
            @click="rows.push({ key: '', value: '' })"
        />
    </div>
</template>

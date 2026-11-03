<script setup lang="ts">
import Button from 'primevue/button';
import { computed } from 'vue';
import { toast } from 'vue-sonner';

type Props = {
    value: unknown;
    maxHeight?: string;
    emptyText?: string;
};

const props = withDefaults(defineProps<Props>(), {
    maxHeight: '24rem',
    emptyText: 'Empty',
});

const text = computed(() => {
    if (
        props.value === null ||
        props.value === undefined ||
        props.value === ''
    ) {
        return '';
    }

    return typeof props.value === 'string'
        ? props.value
        : JSON.stringify(props.value, null, 2);
});

function escapeHtml(value: string): string {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/**
 * Escape first, then wrap JSON tokens in classes for light highlighting.
 */
const highlighted = computed(() => {
    const escaped = escapeHtml(text.value);

    if (typeof props.value === 'string') {
        return escaped;
    }

    return escaped.replace(
        /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/g,
        (
            match,
            string: string | undefined,
            colon: string | undefined,
            literal: string | undefined,
        ) => {
            if (string !== undefined) {
                return colon
                    ? `<span class="text-sky-700 dark:text-sky-300">${string}</span>${colon}`
                    : `<span class="text-emerald-700 dark:text-emerald-300">${string}</span>`;
            }

            if (literal !== undefined) {
                return `<span class="text-purple-700 dark:text-purple-300">${match}</span>`;
            }

            return `<span class="text-amber-700 dark:text-amber-300">${match}</span>`;
        },
    );
});

async function copy(): Promise<void> {
    await navigator.clipboard.writeText(text.value);
    toast.success('Copied to clipboard');
}
</script>

<template>
    <div class="relative rounded-lg border bg-muted/40">
        <Button
            v-if="text"
            icon="pi pi-copy"
            severity="secondary"
            text
            size="small"
            class="absolute top-1 right-1"
            aria-label="Copy"
            @click="copy"
        />
        <pre
            v-if="text"
            class="overflow-auto p-3 pr-10 font-mono text-xs leading-relaxed"
            :style="{ maxHeight }"
            v-html="highlighted"
        ></pre>
        <p v-else class="p-3 text-sm text-muted-foreground">{{ emptyText }}</p>
    </div>
</template>

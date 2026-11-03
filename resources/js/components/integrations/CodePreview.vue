<script setup lang="ts">
import Button from 'primevue/button';
import Message from 'primevue/message';
import Tag from 'primevue/tag';
import { computed, reactive } from 'vue';
import type { GeneratedFile } from '@/types/integration';

type Props = {
    files: GeneratedFile[];
    canWrite: boolean;
    generating: boolean;
};

const props = defineProps<Props>();

defineEmits<{ generate: [] }>();

const open = reactive(new Set<string>());

const statusMeta: Record<
    GeneratedFile['status'],
    { label: string; severity: string }
> = {
    create: { label: 'New file', severity: 'success' },
    update: { label: 'Update', severity: 'info' },
    unchanged: { label: 'No change', severity: 'secondary' },
    skip: { label: 'Already exists', severity: 'secondary' },
};

const kindIcons: Record<GeneratedFile['kind'], string> = {
    controller: 'pi pi-code',
    routes: 'pi pi-map',
    model: 'pi pi-database',
    migration: 'pi pi-table',
    form: 'pi pi-file-edit',
};

const changes = computed(() =>
    props.files.filter(
        (file) => file.status === 'create' || file.status === 'update',
    ),
);

function title(file: GeneratedFile): string {
    return file.kind === 'routes'
        ? file.path
        : (file.path.split('/').pop() ?? file.path).replace('.php', '');
}

function summary(file: GeneratedFile): string {
    if (file.status === 'skip') {
        return 'Kept as it is.';
    }

    if (file.status === 'unchanged') {
        return 'Everything is already there.';
    }

    switch (file.kind) {
        case 'controller':
            return `${file.status === 'create' ? 'Creates the controller with' : 'Adds'} ${file.added.map((method) => `${method}()`).join(', ')}`;
        case 'routes':
            return `Adds ${file.added.length} route(s): ${(file.additions ?? '')
                .split('\n')
                .map((line) => line.match(/Route::(\w+)\('([^']*)'/))
                .filter((match) => match !== null)
                .map((match) => `${match[1].toUpperCase()} ${match[2]}`)
                .join(', ')}`;
        case 'form':
            return 'Form page, read-only until Edit is clicked; submits to the endpoint.';
        case 'model':
            return 'Stores the request data and API response.';
        default:
            return 'Creates the table. Run php artisan migrate afterwards.';
    }
}

function toggle(path: string): void {
    if (open.has(path)) {
        open.delete(path);
    } else {
        open.add(path);
    }
}

function code(file: GeneratedFile): string[] {
    return (file.additions ?? file.contents).split('\n');
}
</script>

<template>
    <div class="space-y-4">
        <ul class="divide-y overflow-hidden rounded-lg border">
            <li v-for="file in files" :key="file.path">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    <i
                        :class="kindIcons[file.kind]"
                        class="text-muted-foreground"
                    ></i>
                    <div class="min-w-0 flex-1">
                        <p class="font-mono text-sm">{{ title(file) }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ summary(file) }}
                        </p>
                    </div>
                    <Tag
                        :value="statusMeta[file.status].label"
                        :severity="statusMeta[file.status].severity"
                    />
                    <Button
                        :label="open.has(file.path) ? 'Hide code' : 'View code'"
                        size="small"
                        severity="secondary"
                        text
                        @click="toggle(file.path)"
                    />
                </div>

                <div
                    v-if="open.has(file.path)"
                    class="overflow-auto border-t bg-zinc-950 text-zinc-100"
                    style="max-height: 28rem"
                >
                    <p
                        v-if="file.additions"
                        class="border-b border-zinc-800 px-3 py-1 text-xs text-zinc-400"
                    >
                        Code that will be added to {{ file.path }}
                    </p>
                    <table
                        class="w-full border-collapse font-mono text-xs leading-relaxed"
                    >
                        <tbody>
                            <tr
                                v-for="(line, index) in code(file)"
                                :key="index"
                            >
                                <td
                                    class="w-12 border-r border-zinc-800 px-3 text-right align-top text-zinc-500 select-none"
                                >
                                    {{ index + 1 }}
                                </td>
                                <td class="px-3 whitespace-pre">{{ line }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </li>
        </ul>

        <Message v-if="!canWrite" severity="info" size="small">
            Writing files is disabled in this environment. Copy the code above,
            or set
            <code class="font-mono">INTEGRATION_BUILDER_WRITES=true</code> on a
            development machine.
        </Message>

        <div class="flex items-center justify-between gap-3">
            <p class="text-sm text-muted-foreground">
                {{
                    changes.length
                        ? `${changes.length} file(s) will be created or updated.`
                        : 'Nothing to generate; everything already exists.'
                }}
            </p>
            <Button
                label="Generate"
                icon="pi pi-bolt"
                :loading="generating"
                :disabled="!canWrite || changes.length === 0"
                @click="$emit('generate')"
            />
        </div>
    </div>
</template>

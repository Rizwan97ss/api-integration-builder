<script setup lang="ts">
import Button from 'primevue/button';
import Checkbox from 'primevue/checkbox';
import InputText from 'primevue/inputtext';
import Select from 'primevue/select';
import { computed, reactive } from 'vue';
import { countErrors, newField, scalarTypes } from '@/lib/integrationBuilder';
import type {
    FieldDefinition,
    FieldSource,
    FieldType,
} from '@/types/integration';

type Props = {
    fieldTypes: FieldType[];
    errors?: Record<string, string>;
    errorPrefix: string;
    depth?: number;
    emptyText?: string;
    credentialColumns?: string[];
    credentialsSet?: Record<string, boolean>;
    insideArray?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    errors: () => ({}),
    depth: 0,
    emptyText: 'No fields yet. Add one or import a sample.',
    credentialColumns: () => [],
    credentialsSet: () => ({}),
    insideArray: false,
});

const fields = defineModel<FieldDefinition[]>({ required: true });

const expanded = reactive(new Set<string>());

const operators = [
    { label: 'equals', value: 'equals' },
    { label: 'does not equal', value: 'not_equals' },
    { label: 'is filled', value: 'filled' },
];

const typeOptions = computed(() =>
    props.fieldTypes.map((type) => ({ label: type, value: type })),
);
const scalarOptions = scalarTypes.map((type) => ({ label: type, value: type }));

const sourceOptions: Array<{ label: string; value: FieldSource }> = [
    { label: 'Request input', value: 'input' },
    { label: 'Credential column', value: 'credential' },
    { label: 'Fixed value', value: 'fixed' },
];

const credentialOptions = computed(() =>
    props.credentialColumns.map((column) => ({
        value: column,
        label: `${column} ${props.credentialsSet[column] ? '· set' : '· empty'}`,
    })),
);

/**
 * Only single values outside lists can be filled from a credential or a fixed value.
 */
function canChooseSource(field: FieldDefinition): boolean {
    return (
        !props.insideArray && !['object', 'array', 'file'].includes(field.type)
    );
}

function changeSource(field: FieldDefinition, source: FieldSource): void {
    field.source = source;

    if (source === 'credential' && !field.credential) {
        field.credential = props.credentialColumns[0] ?? null;
    }
}

function error(index: number, key: string): string | undefined {
    return props.errors[`${props.errorPrefix}.${index}.${key}`];
}

function hasNestedErrors(index: number): boolean {
    const prefix = `${props.errorPrefix}.${index}`;

    return [
        'rules',
        'enum',
        'item_type',
        'condition',
        'source',
        'credential',
        'value',
    ].some((key) => countErrors(props.errors, `${prefix}.${key}`) > 0);
}

function siblingNames(
    field: FieldDefinition,
): Array<{ label: string; value: string }> {
    return fields.value
        .filter((sibling) => sibling.uid !== field.uid && sibling.name)
        .map((sibling) => ({ label: sibling.name, value: sibling.name }));
}

function looksLikeEnum(field: FieldDefinition): boolean {
    return (
        field.type === 'string' &&
        !field.enum &&
        /^[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/.test(field.example ?? '') &&
        (field.example ?? '').length >= 2
    );
}

function toggle(field: FieldDefinition): void {
    if (expanded.has(field.uid)) {
        expanded.delete(field.uid);
    } else {
        expanded.add(field.uid);
    }
}

function addField(): void {
    fields.value.push(newField());
}

function addChild(field: FieldDefinition): void {
    field.children.push(newField());
}

function move(index: number, offset: number): void {
    const target = index + offset;

    if (target < 0 || target >= fields.value.length) {
        return;
    }

    const [field] = fields.value.splice(index, 1);
    fields.value.splice(target, 0, field);
}

function toggleCondition(field: FieldDefinition): void {
    field.condition = field.condition
        ? null
        : { field: '', operator: 'equals', value: '' };
}

function changeType(field: FieldDefinition, type: FieldType): void {
    field.type = type;

    if (type !== 'object' && type !== 'array') {
        field.children = [];
    }

    if (!canChooseSource(field)) {
        field.source = 'input';
    }

    if (type === 'array' && !field.item_type) {
        field.item_type = 'string';
    }
}
</script>

<template>
    <div class="space-y-2">
        <p
            v-if="fields.length === 0 && depth === 0"
            class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
        >
            {{ emptyText }}
        </p>

        <div
            v-for="(field, index) in fields"
            :key="field.uid"
            class="rounded-lg border bg-card"
            :class="{
                'border-red-400 dark:border-red-500/60':
                    countErrors(errors, `${errorPrefix}.${index}`) > 0,
            }"
        >
            <div class="flex flex-wrap items-start gap-2 p-2">
                <div class="flex min-w-40 flex-1 flex-col gap-1">
                    <InputText
                        v-model="field.name"
                        placeholder="field_name"
                        size="small"
                        class="font-mono"
                        :invalid="!!error(index, 'name')"
                    />
                    <small
                        v-if="error(index, 'name')"
                        class="text-red-600 dark:text-red-400"
                    >
                        {{ error(index, 'name') }}
                    </small>
                </div>

                <Select
                    :model-value="field.type"
                    :options="typeOptions"
                    option-label="label"
                    option-value="value"
                    size="small"
                    class="w-32"
                    :invalid="!!error(index, 'type')"
                    @update:model-value="
                        (type: FieldType) => changeType(field, type)
                    "
                />

                <label
                    v-if="field.source === 'input'"
                    class="flex h-8 items-center gap-2 text-sm"
                >
                    <Checkbox
                        v-model="field.required"
                        binary
                        :input-id="`${field.uid}-required`"
                    />
                    Required
                </label>

                <button
                    v-else
                    v-tooltip.top="
                        'Filled in by the generated code, not by the caller'
                    "
                    type="button"
                    class="flex h-8 items-center gap-1 rounded-md bg-muted px-2 font-mono text-xs"
                    @click="expanded.add(field.uid)"
                >
                    <i
                        class="pi text-xs"
                        :class="
                            field.source === 'credential' ? 'pi-key' : 'pi-lock'
                        "
                    ></i>
                    {{
                        field.source === 'credential'
                            ? (field.credential ?? 'choose column')
                            : `"${field.value}"`
                    }}
                </button>

                <button
                    v-if="looksLikeEnum(field)"
                    v-tooltip.top="
                        `Sample value ${field.example} looks like one of a fixed set. Click to add the allowed values.`
                    "
                    type="button"
                    class="flex h-8 items-center gap-1 rounded-md px-2 text-xs text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-500/10"
                    @click="expanded.add(field.uid)"
                >
                    <i class="pi pi-list text-xs"></i>
                    Fixed values?
                </button>

                <span
                    v-if="field.condition?.field"
                    v-tooltip.top="'Conditionally required'"
                    class="flex h-8 items-center"
                >
                    <i class="pi pi-filter text-xs text-muted-foreground"></i>
                </span>

                <div class="ml-auto flex items-center">
                    <Button
                        v-if="field.type === 'object' || field.type === 'array'"
                        v-tooltip.top="'Add nested field'"
                        icon="pi pi-sitemap"
                        severity="secondary"
                        text
                        size="small"
                        aria-label="Add nested field"
                        @click="addChild(field)"
                    />
                    <Button
                        v-tooltip.top="'Validation & conditions'"
                        :icon="
                            expanded.has(field.uid)
                                ? 'pi pi-chevron-up'
                                : 'pi pi-sliders-h'
                        "
                        :severity="
                            hasNestedErrors(index) ? 'danger' : 'secondary'
                        "
                        text
                        size="small"
                        aria-label="Field settings"
                        @click="toggle(field)"
                    />
                    <Button
                        icon="pi pi-arrow-up"
                        severity="secondary"
                        text
                        size="small"
                        aria-label="Move up"
                        :disabled="index === 0"
                        @click="move(index, -1)"
                    />
                    <Button
                        icon="pi pi-arrow-down"
                        severity="secondary"
                        text
                        size="small"
                        aria-label="Move down"
                        :disabled="index === fields.length - 1"
                        @click="move(index, 1)"
                    />
                    <Button
                        icon="pi pi-trash"
                        severity="danger"
                        text
                        size="small"
                        aria-label="Remove field"
                        @click="fields.splice(index, 1)"
                    />
                </div>
            </div>

            <div
                v-if="expanded.has(field.uid) || hasNestedErrors(index)"
                class="grid gap-3 border-t bg-muted/30 p-3 md:grid-cols-2"
            >
                <div v-if="canChooseSource(field)" class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Value comes from</label
                    >
                    <Select
                        :model-value="field.source"
                        :options="sourceOptions"
                        option-label="label"
                        option-value="value"
                        size="small"
                        :invalid="!!error(index, 'source')"
                        @update:model-value="
                            (source: FieldSource) => changeSource(field, source)
                        "
                    />
                    <small
                        v-if="error(index, 'source')"
                        class="text-red-600 dark:text-red-400"
                        >{{ error(index, 'source') }}</small
                    >
                </div>

                <div
                    v-if="field.source === 'credential'"
                    class="flex flex-col gap-1"
                >
                    <label class="text-xs font-medium text-muted-foreground"
                        >Credential column</label
                    >
                    <Select
                        v-model="field.credential"
                        :options="credentialOptions"
                        option-label="label"
                        option-value="value"
                        size="small"
                        :invalid="!!error(index, 'credential')"
                    />
                    <small
                        v-if="error(index, 'credential')"
                        class="text-red-600 dark:text-red-400"
                        >{{ error(index, 'credential') }}</small
                    >
                    <small v-else class="text-muted-foreground"
                        >Read from the credential source at runtime; never shown
                        or stored.</small
                    >
                </div>

                <div
                    v-else-if="field.source === 'fixed'"
                    class="flex flex-col gap-1"
                >
                    <label class="text-xs font-medium text-muted-foreground"
                        >Fixed value</label
                    >
                    <InputText
                        v-model="field.value"
                        placeholder="client_credentials"
                        size="small"
                        class="font-mono"
                        :invalid="!!error(index, 'value')"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Extra rules</label
                    >
                    <InputText
                        v-model="field.rules"
                        placeholder="min:3|max:255"
                        size="small"
                        class="font-mono"
                        :invalid="!!error(index, 'rules')"
                    />
                    <small
                        v-if="error(index, 'rules')"
                        class="text-red-600 dark:text-red-400"
                        >{{ error(index, 'rules') }}</small
                    >
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Allowed values</label
                    >
                    <InputText
                        v-model="field.enum"
                        placeholder="draft,active,archived"
                        size="small"
                        :invalid="!!error(index, 'enum')"
                    />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Description</label
                    >
                    <InputText v-model="field.description" size="small" />
                </div>

                <div class="flex flex-col gap-1">
                    <label class="text-xs font-medium text-muted-foreground"
                        >Example (used for test samples)</label
                    >
                    <InputText
                        :model-value="field.example ?? ''"
                        size="small"
                        @update:model-value="
                            (value?: string) => (field.example = value || null)
                        "
                    />
                </div>

                <div
                    v-if="field.type === 'array' && field.children.length === 0"
                    class="flex flex-col gap-1"
                >
                    <label class="text-xs font-medium text-muted-foreground"
                        >Item type</label
                    >
                    <Select
                        v-model="field.item_type"
                        :options="scalarOptions"
                        option-label="label"
                        option-value="value"
                        size="small"
                    />
                    <small class="text-muted-foreground"
                        >Add nested fields instead for an array of
                        objects.</small
                    >
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox v-model="field.nullable" binary />
                    Allow null values
                </label>

                <div class="flex flex-col gap-2 md:col-span-2">
                    <div class="flex items-center gap-2">
                        <Button
                            :label="
                                field.condition
                                    ? 'Remove condition'
                                    : 'Make conditionally required'
                            "
                            :icon="
                                field.condition ? 'pi pi-times' : 'pi pi-filter'
                            "
                            severity="secondary"
                            size="small"
                            outlined
                            @click="toggleCondition(field)"
                        />
                    </div>

                    <div
                        v-if="field.condition"
                        class="flex flex-wrap items-center gap-2 text-sm"
                    >
                        <span class="text-muted-foreground">Required when</span>
                        <Select
                            v-model="field.condition.field"
                            :options="siblingNames(field)"
                            option-label="label"
                            option-value="value"
                            placeholder="field"
                            size="small"
                            class="w-40"
                            :invalid="!!error(index, 'condition.field')"
                        />
                        <Select
                            v-model="field.condition.operator"
                            :options="operators"
                            option-label="label"
                            option-value="value"
                            size="small"
                            class="w-40"
                        />
                        <InputText
                            v-if="field.condition.operator !== 'filled'"
                            v-model="field.condition.value"
                            placeholder="value"
                            size="small"
                            class="w-40"
                            :invalid="!!error(index, 'condition.value')"
                        />
                    </div>
                    <small
                        v-if="
                            error(index, 'condition.field') ||
                            error(index, 'condition.value')
                        "
                        class="text-red-600 dark:text-red-400"
                    >
                        {{
                            error(index, 'condition.field') ??
                            error(index, 'condition.value')
                        }}
                    </small>
                </div>
            </div>

            <div
                v-if="
                    (field.type === 'object' || field.type === 'array') &&
                    field.children.length > 0
                "
                class="border-t p-2 pl-6"
            >
                <p class="mb-2 text-xs text-muted-foreground">
                    {{
                        field.type === 'array'
                            ? 'Each item contains'
                            : 'Properties'
                    }}
                </p>
                <FieldEditor
                    v-model="field.children"
                    :field-types="fieldTypes"
                    :errors="errors"
                    :error-prefix="`${errorPrefix}.${index}.children`"
                    :depth="depth + 1"
                    :credential-columns="credentialColumns"
                    :credentials-set="credentialsSet"
                    :inside-array="insideArray || field.type === 'array'"
                />
            </div>
        </div>

        <Button
            :label="depth === 0 ? 'Add field' : 'Add property'"
            icon="pi pi-plus"
            severity="secondary"
            size="small"
            :outlined="depth === 0"
            :text="depth > 0"
            @click="addField"
        />
    </div>
</template>

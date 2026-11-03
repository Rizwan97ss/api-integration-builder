<script setup lang="ts">
import Message from 'primevue/message';
import Tab from 'primevue/tab';
import TabList from 'primevue/tablist';
import TabPanel from 'primevue/tabpanel';
import TabPanels from 'primevue/tabpanels';
import Tabs from 'primevue/tabs';
import Tag from 'primevue/tag';
import { computed } from 'vue';
import JsonBlock from '@/components/integrations/JsonBlock.vue';
import type { TestResult } from '@/types/integration';

const props = defineProps<{ result: TestResult }>();

const statusSeverity = computed(() => {
    const status = props.result.status ?? 0;

    if (status >= 200 && status < 300) {
        return 'success';
    }

    return status >= 400 && status < 500 ? 'warn' : 'danger';
});

const formattedSize = computed(() => {
    const size = props.result.response?.size ?? 0;

    return size > 1024 ? `${(size / 1024).toFixed(1)} KB` : `${size} B`;
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center gap-2">
            <Tag
                v-if="result.status"
                :value="`${result.status} ${result.reason ?? ''}`"
                :severity="statusSeverity"
            />
            <Tag
                v-else
                :value="result.success ? 'OK' : 'Failed'"
                :severity="result.success ? 'success' : 'danger'"
            />
            <span class="text-xs text-muted-foreground"
                >{{ result.duration_ms }} ms</span
            >
            <span v-if="result.response" class="text-xs text-muted-foreground"
                >· {{ formattedSize }}</span
            >
            <span
                v-if="result.response?.truncated"
                class="text-xs text-amber-600"
                >· truncated</span
            >
        </div>

        <Message :severity="result.success ? 'success' : 'error'" size="small">
            {{ result.message }}
        </Message>

        <div
            v-if="result.validation_errors"
            class="rounded-lg border border-red-300 p-3 dark:border-red-500/50"
        >
            <p class="mb-2 text-sm font-medium">Fix these before sending:</p>
            <ul class="space-y-1 text-sm">
                <li
                    v-for="(messages, field) in result.validation_errors"
                    :key="field"
                >
                    <code class="font-mono text-xs">{{ field }}</code>
                    <span class="text-muted-foreground">
                        — {{ messages.join(' ') }}</span
                    >
                </li>
            </ul>
        </div>

        <JsonBlock
            v-if="result.details"
            :value="result.details"
            max-height="16rem"
        />

        <Tabs v-if="result.response || result.request" value="body">
            <TabList>
                <Tab v-if="result.response" value="body">Response</Tab>
                <Tab v-if="result.response" value="headers"
                    >Response headers</Tab
                >
                <Tab v-if="result.request" value="request">Request sent</Tab>
            </TabList>
            <TabPanels class="!px-0">
                <TabPanel v-if="result.response" value="body">
                    <JsonBlock
                        :value="result.response.body"
                        empty-text="Empty response body"
                    />
                </TabPanel>
                <TabPanel v-if="result.response" value="headers">
                    <JsonBlock :value="result.response.headers" />
                </TabPanel>
                <TabPanel v-if="result.request" value="request">
                    <JsonBlock :value="result.request" />
                </TabPanel>
            </TabPanels>
        </Tabs>
    </div>
</template>

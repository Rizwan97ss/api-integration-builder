<script setup lang="ts">
import Tag from 'primevue/tag';
import { methodSeverity } from '@/lib/integrationBuilder';
import type { HttpMethod } from '@/types/integration';

defineProps<{ method: HttpMethod }>();
</script>

<template>
    <Tag
        :value="method"
        :severity="methodSeverity[method]"
        class="w-16 justify-center font-mono !text-[0.7rem]"
    />
</template>

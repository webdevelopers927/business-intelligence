<script setup lang="ts">
import { DropdownMenuLabel, DropdownMenuSeparator } from 'reka-ui';

defineProps<{ separator?: boolean }>();
</script>

<template>
    <DropdownMenuSeparator v-if="separator" class="-mx-1 my-1 h-px bg-border" />
    <DropdownMenuLabel v-else class="label-instrument px-2 pt-1.5 pb-1"><slot /></DropdownMenuLabel>
</template>

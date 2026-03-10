<template>
  <div class="bg-gray-900 border border-gray-800 rounded-xl px-5 py-4">
    <p class="text-xs text-gray-500 font-medium uppercase tracking-wide">{{ label }}</p>
    <p :class="['text-2xl font-bold mt-1', highlight ? 'text-sky-400' : 'text-gray-100']">{{ value }}</p>
  </div>
</template>

<script setup>
defineProps({
  label: String,
  value: [String, Number],
  highlight: Boolean,
});
</script>

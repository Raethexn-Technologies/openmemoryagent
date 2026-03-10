<template>
  <div class="flex items-center px-2">
    <svg class="w-8 h-4" viewBox="0 0 32 16" fill="none">
      <line
        x1="0" y1="8" x2="24" y2="8"
        :stroke="dashed ? '#374151' : '#4B5563'"
        stroke-width="1.5"
        :stroke-dasharray="dashed ? '4 3' : ''"
      />
      <polyline points="20,4 28,8 20,12" :stroke="dashed ? '#374151' : '#4B5563'" stroke-width="1.5" fill="none" />
    </svg>
  </div>
</template>

<script setup>
defineProps({
  dashed: Boolean,
});
</script>

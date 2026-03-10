<template>
  <a
    :href="href"
    :class="[
      'px-3 py-1.5 rounded-md text-sm font-medium transition-colors',
      active
        ? 'bg-sky-500/15 text-sky-400'
        : 'text-gray-400 hover:text-gray-200 hover:bg-gray-800'
    ]"
  >
    <slot />
  </a>
</template>

<script setup>
defineProps({
  href: String,
  active: Boolean,
});
</script>

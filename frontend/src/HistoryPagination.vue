<script setup>
import { computed } from 'vue'

const props = defineProps({
  current: { type: Number, required: true },
  last: { type: Number, required: true },
  busy: Boolean,
  label: { type: String, required: true },
})
const emit = defineEmits(['change'])
const pages = computed(() => {
  if (props.last <= 7) return Array.from({ length: props.last }, (_, index) => index + 1)

  const first = Math.max(2, Math.min(props.current - 1, props.last - 4))
  const last = Math.min(props.last - 1, Math.max(props.current + 1, 5))
  return [
    1,
    ...(first > 2 ? ['start-gap'] : []),
    ...Array.from({ length: last - first + 1 }, (_, index) => first + index),
    ...(last < props.last - 1 ? ['end-gap'] : []),
    props.last,
  ]
})
</script>

<template>
  <nav v-if="last > 1" class="history-pagination" :aria-label="label">
    <button type="button" :disabled="busy || current <= 1" @click="emit('change', current - 1)">前へ</button>
    <template v-for="page in pages" :key="page">
      <span v-if="typeof page === 'string'" class="pagination-gap" aria-hidden="true">…</span>
      <button v-else type="button" :aria-current="page === current ? 'page' : undefined"
        :aria-label="page + 'ページ目'" :disabled="busy" @click="page !== current && emit('change', page)">
        {{ page }}
      </button>
    </template>
    <button type="button" :disabled="busy || current >= last" @click="emit('change', current + 1)">次へ</button>
  </nav>
</template>

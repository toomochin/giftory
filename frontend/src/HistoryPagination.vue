<script setup>
import { computed } from 'vue'

const props = defineProps({
  current: { type: Number, required: true },
  last: { type: Number, required: true },
  busy: Boolean,
  label: { type: String, required: true },
})
const emit = defineEmits(['change'])
// 先頭と末尾を残し、離れたページを省略して現在ページの周辺を表示する。
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
// スマートフォンでは表示する番号を絞り、前へ・次へを置く幅を確保する。
const compactPages = computed(() => {
  const { current, last } = props
  if (last <= 6) return Array.from({ length: last }, (_, index) => index + 1)
  if (current <= 3) return [1, 2, 3, 4, 5, 'end-gap', last]
  if (current >= last - 2) return [1, 'start-gap', last - 4, last - 3, last - 2, last - 1, last]
  return [1, 'start-gap', current - 1, current, current + 1, 'end-gap', last]
})
</script>

<template>
  <nav v-if="last > 1" class="history-pagination" :aria-label="label">
    <button type="button" :disabled="busy || current <= 1" @click="emit('change', current - 1)">前へ</button>
    <div v-for="(numbers, variant) in { desktop: pages, compact: compactPages }" :key="variant"
      class="pagination-numbers" :class="'pagination-' + variant">
      <template v-for="page in numbers" :key="page">
        <span v-if="typeof page === 'string'" class="pagination-gap" aria-hidden="true">…</span>
        <button v-else type="button" :aria-current="page === current ? 'page' : undefined"
          :aria-label="page + 'ページ目'" :disabled="busy" @click="page !== current && emit('change', page)">
          {{ page }}
        </button>
      </template>
    </div>
    <button type="button" :disabled="busy || current >= last" @click="emit('change', current + 1)">次へ</button>
  </nav>
</template>

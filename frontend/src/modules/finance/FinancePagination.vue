<script setup>
defineProps({ meta: { type: Object, required: true }, busy: Boolean });
defineEmits(['page']);
</script>
<template><div class="table-pagination-footer"><button type="button" class="btn small" :disabled="busy || meta.current_page <= 1" @click="$emit('page', meta.current_page - 1)">السابق</button><span>الصفحة {{ meta.current_page || 1 }} من {{ meta.last_page || 1 }} · {{ meta.total || 0 }} سجل</span><button type="button" class="btn small" :disabled="busy || meta.current_page >= meta.last_page" @click="$emit('page', meta.current_page + 1)">التالي</button></div></template>

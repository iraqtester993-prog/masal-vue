<script setup>
import DashboardActivity from './DashboardActivity.vue';
</script>

<template>
  <div class="dashboard-workspace pos-financial-history">
    <DashboardActivity complete />
  </div>
</template>

<style scoped>
.pos-financial-history :deep(.dashboard-activity-grid){margin-top:0}
.pos-financial-history :deep(.pos-activity-row){padding-block:16px}
.pos-financial-history :deep(.activity-direction){display:block;color:var(--muted);font-size:10px}
.pos-financial-history :deep(.table-pagination-footer){display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:18px;flex-wrap:wrap}
</style>

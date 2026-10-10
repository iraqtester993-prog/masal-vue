<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="two">
    <div class="card">
      <button
        class="btn primary"
        v-permit="can('backup.download')"
        @click="backup"
        :disabled="serverBackupBusy"
      >
        {{ tr(serverBackupBusy ? "جارٍ النسخ الاحتياطي…" : "نسخ احتياطي") }}
      </button>
    </div>
    <div class="card">
      <input
        type="file"
        accept=".json"
        :aria-label="tr('اختيار نسخة احتياطية من الحاسبة')"
        v-permit="can('backup.restore')"
        @change="prepareRestore"
      />
    </div>
  </div>
</template>

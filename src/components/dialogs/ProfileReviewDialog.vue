<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="notice">
    <b>{{ $root.tr(modal.draft.name) }}</b>
    <p>
      {{ $root.tr(modal.draft.permissions.length) }} {{ tr("صلاحية محددة") }} ·
      {{ $root.tr(modal.count) }} {{ tr("موظف مرتبط") }}
    </p>
  </div>
  <p v-if="modal.count" class="help">
    {{ tr("سيطبق التعديل على جميع الموظفين المرتبطين بهذا النوع.") }}
  </p>
  <p v-if="modal.reason">{{ modal.reason }}</p>
  <div class="permission-review-list">
    <div v-for="c in modal.changes">
      <b>{{ tr(c.label) }}</b
      ><span class="badge" :class="{ bad: !c.allow }">{{
        tr(c.allow ? "سماح" : "منع")
      }}</span>
    </div>
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("رجوع") }}</button
    ><button
      class="btn primary"
      @click="savePermissionProfile"
      :disabled="formPending.savePermissionProfile"
    >
      {{ tr("تأكيد حفظ نوع الصلاحية") }}
    </button>
  </div>
</template>

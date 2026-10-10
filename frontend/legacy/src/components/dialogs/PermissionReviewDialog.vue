<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <p>{{ $root.tr(nameOf("users", modal.user)) }}</p>
  <div class="notice">{{ modal.reason }}</div>
  <p v-if="modal.scopeChanged" class="notice warn">
    {{ tr("يتضمن التغيير تعديل نطاق البيانات") }}
  </p>
  <div class="permission-review-list">
    <div v-for="c in modal.changes">
      <b>{{ tr(c.label) }}</b
      ><span
        >{{ tr(permissionValue(c.before)) }} ←
        {{ tr(permissionValue(c.after)) }}</span
      >
    </div>
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("رجوع") }}</button
    ><button class="btn primary" @click="savePermissions">
      {{ tr("تأكيد صلاحيات الموظف") }}
    </button>
  </div>
</template>

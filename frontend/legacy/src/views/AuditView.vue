<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card">
    <page-actions></page-actions>
    <div class="toolbar">
      <input
        style="max-width: 320px"
        v-model="search"
        :placeholder="tr('بحث بالإجراء أو المستخدم أو العملية')"
      /><span class="caption">{{ tr(auditRows.length) }}{{ tr(" حدث") }}</span>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ tr("التوقيت") }}</th>
            <th>{{ tr("المستخدم") }}</th>
            <th>{{ tr("الإجراء") }}</th>
            <th>{{ tr("المرجع") }}</th>
            <th>{{ tr("قبل / بعد") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in auditRows">
            <td>{{ tr(formatTime(r.time)) }}</td>
            <td>{{ tr(r.name) }}</td>
            <td>{{ tr(r.action) }}</td>
            <td class="mono">{{ tr(r.entity) }}</td>
            <td>
              <button
                class="btn small"
                v-permit="can('audit.details')"
                @click="inspect(r)"
              >
                {{ tr("التفاصيل") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!auditRows.length" class="empty">
      {{ tr("ستظهر هنا جميع التغييرات والعمليات") }}
    </div>
  </div>
</template>

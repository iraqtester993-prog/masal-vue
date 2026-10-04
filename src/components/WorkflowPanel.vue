<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["workflow-panel"]);
export default options;
</script>

<template>
  <section
    v-if="visible&amp;&amp;page==='exports'"
    class="card compact-withdrawal"
    data-no-pagination=""
  >
    <h3>{{ $root.tr("طلبات الإرجاع") }}</h3>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("الدفعة / الفئة") }}</th>
            <th>{{ $root.tr("البطاقات") }}</th>
            <th>{{ $root.tr("السبب") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in exportRequests" :key="r.id">
            <td>
              {{ $root.tr(vm.batchLabel(s.batches.find(b=&gt;b.id===r.batch))) }}
            </td>
            <td>
              {{ $root.tr(r.quantity||s.cards.filter(c=&gt;c.batch===r.batch&amp;&amp;!c.sale&amp;&amp;c.status==='Quarantined').length) }}
            </td>
            <td>{{ r.reason }}</td>
            <td>{{ $root.tr(r.status) }}</td>
            <td>
              <div
                v-if="r.status==='بانتظار الاعتماد'&amp;&amp;can('exports.approve')"
                class="actions"
              >
                <button
                  class="btn small primary"
                  v-if="can('exports.encrypt')&amp;&amp;can('data.pin')"
                  @click="approveReturn(r)"
                >
                  {{ $root.tr("اعتماد الإرجاع وتنزيل الملف") }}</button
                ><input
                  v-model="vm.exportRejectReasons[r.id]"
                  :placeholder="$root.tr('سبب الرفض')"
                /><button
                  class="btn small danger"
                  :disabled="!vm.exportRejectReasons[r.id]?.trim()"
                  @click="vm.rejectExportRequest(r)"
                >
                  {{ $root.tr("رفض") }}
                </button>
              </div>
              <button
                v-if="r.status==='معتمد'&amp;&amp;can('exports.encrypt')&amp;&amp;can('data.pin')"
                class="btn small"
                @click="selectExport(r)"
              >
                {{ $root.tr("إكمال الإرجاع وتنزيل الملف") }}
              </button>
            </td>
          </tr>
          <tr v-if="!exportRequests.length">
            <td colspan="5" class="empty">
              {{ $root.tr("لا توجد طلبات إرجاع معلقة") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

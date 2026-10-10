<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {computed, ref, watch} from 'vue';
import {downloadText, rejectedRows} from './stock-model.js';
const props = defineProps({lines:{type:Array,default:() => []}, draftLines:{type:Array,default:() => []},canExport:Boolean});
const selected = ref(''), onlyErrors = ref(false), page = ref(1);
const enrichedLines = computed(() => props.lines.map(line => {
  const draftRows = props.draftLines.find(draft => draft.key === line.key)?.rows || [];
  return {...line,checked:(line.checked || []).map((row,index) => ({...draftRows[index],...row}))};
}));
const current = computed(() => enrichedLines.value.find(line => line.key === selected.value));
const cards = computed(() => {
  const draftRows = props.draftLines.find(line => line.key === selected.value)?.rows || [];
  return current.value?.checked ? current.value.checked.map((row,index) => ({...draftRows[index],...row})) : draftRows;
});
const rows = computed(() => cards.value.filter(row => !onlyErrors.value || row.error));
const visible = computed(() => rows.value.slice((page.value-1)*25, page.value*25));
watch([selected, onlyErrors, () => props.lines], () => {page.value = 1;});
async function exportRejected() {const {workbook} = await import('../../shared/files/excel-export.js'); downloadText('masal-rejected.xlsx',workbook(rejectedRows(enrichedLines.value)));}
</script>
<template>
  <div>
    <div v-if="canExport && lines.some(line => line.rejected)" class="formfoot"><button type="button" class="btn small" @click="exportRejected">تنزيل البطاقات المرفوضة · Excel</button></div>
    <TablePanel :start="(page-1)*25+1" class="tablewrap"><table><thead><tr><th>الملف</th><th>الفئة</th><th>رمز الفئة</th><th>الإجمالي</th><th>صالحة</th><th>مرفوضة</th><th>السعر</th><th>إجمالي قيمة البطاقات</th><th>رقم دفعة المخزون</th><th>الإجراءات</th></tr></thead><tbody>
      <tr v-for="line in lines" :key="line.key"><td>{{line.name}}</td><td>{{line.product_name}}</td><td>{{line.category_code || '—'}}</td><td>{{Number(line.accepted) + Number(line.rejected)}}</td><td>{{line.accepted}}</td><td>{{line.rejected}}</td><td>{{line.load_price ?? '—'}}</td><td>{{line.amount ?? '—'}}</td><td>{{line.batch_id || '—'}}</td><td><button type="button" class="btn small" @click="selected = selected === line.key ? '' : line.key">{{selected === line.key ? 'إغلاق' : 'البطاقات'}}</button></td></tr>
    </tbody></table></TablePanel>
    <section v-if="current" class="order-file"><header><b>{{current.name}}</b><button type="button" class="btn small" :aria-pressed="onlyErrors" @click="onlyErrors = !onlyErrors">{{onlyErrors ? 'عرض الكل' : 'المرفوضة فقط'}}</button></header>
      <TablePanel :start="(page-1)*25+1" class="tablewrap"><table><thead><tr><th>سطر الملف</th><th>Serial</th><th>Expiry</th><th>نتيجة الفحص</th></tr></thead><tbody><tr v-for="(row,index) in visible" :key="index"><td>{{row.source_row ?? row.sourceRow}}</td><td dir="ltr">{{row.serial}}</td><td>{{row.expiry}}</td><td :class="{'text-danger':row.error}">{{row.error || 'صالح'}}</td></tr></tbody></table></TablePanel>
      <p v-if="!rows.length" class="empty">لا توجد بطاقات للعرض</p><div class="formfoot"><button class="btn small" type="button" :disabled="page <= 1" @click="page--">السابق</button><span>{{page}} / {{Math.max(1,Math.ceil(rows.length/25))}}</span><button class="btn small" type="button" :disabled="page*25 >= rows.length" @click="page++">التالي</button></div>
    </section>
  </div>
</template>

<script setup>
import TablePanel from '../../shared/components/TablePanel.vue';

import {computed,onBeforeUnmount,onMounted,reactive,ref,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createOperationsApi} from '../security/operations-api.js';
import {emptyTimePolicy,systemOwner} from '../security/operations-model.js';
import {createMutationKey,errorText} from '../finance/finance-model.js';
import '../finance/finance-parity.css';
import './account-times.css';
const {session} = usePortal(),api = createOperationsApi(session.api);
const allowed = computed(() => systemOwner(session.state.identity)&&session.can('security.view')&&session.can('security.policies'));
const users = ref([]),userId = ref(''),query = ref(''),draft = reactive(emptyTimePolicy()),state = reactive({version:0,error:'',success:'',loading:false,saving:false});
const choices = computed(() => users.value.filter(row => `${row.name} ${row.login}`.toLocaleLowerCase('ar').includes(query.value.trim().toLocaleLowerCase('ar'))));
const saved = computed(() => users.value.filter(row => row.policy));
const write = new AbortController(),key = createMutationKey(); let reader,revision=0,alive=true;
async function failure(cause) {if(cause.name==='AbortError'||!alive)return;state.error=errorText(cause);if([401,403].includes(cause.status))await session.refresh();}
async function load() {if(!allowed.value)return;reader?.abort();reader=new AbortController();state.loading=true;try{const response=await api.times(reader.signal);if(alive)users.value=response.data.users;}catch(cause){await failure(cause);}finally{if(alive)state.loading=false;}}
async function select(id) {
  reader?.abort();reader=new AbortController();const expected=++revision;state.error='';state.success='';state.version=0;Object.assign(draft,emptyTimePolicy());key.clear();
  if(!id)return;state.loading=true;
  try {const response=await api.time(id,reader.signal);if(alive&&expected===revision){Object.assign(draft,response.data.policy);state.version=response.data.version;}}
  catch(cause){await failure(cause);}finally{if(alive&&expected===revision)state.loading=false;}
}
async function save() {
  if(!allowed.value||!userId.value||state.saving||state.loading)return;state.error='';state.success='';state.saving=true;
  const id=Number(userId.value),body={policy:{...draft},version:state.version};
  try {const response=await api.saveTime(id,{...body,idempotency_key:key.for({id,...body})},write.signal);if(!alive)return;state.version=response.data.version;Object.assign(draft,response.data.policy);const row=users.value.find(user=>user.id===id);if(row)Object.assign(row,{policy:response.data.policy,version:response.data.version});key.clear();state.success='تم حفظ أوقات الحساب.';}
  catch(cause){await failure(cause);}finally{if(alive)state.saving=false;}
}
watch(userId,id=>select(id));onMounted(load);onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();write.abort();});
</script>

<template>
  <section v-if="allowed" class="finance-workspace"><section class="card account-time-settings">
    <div class="account-time-heading"><h3>أوقات صلاحية الحساب</h3><span class="badge">توقيت بغداد</span></div>
    <p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><p v-if="state.success" class="notice" role="status">{{state.success}}</p>
    <div class="account-time-toolbar"><label>بحث المستخدمين<input v-model="query" placeholder="ابحث بالاسم أو اسم الدخول" aria-label="بحث مستخدمي الأوقات" :disabled="state.saving"></label><label>المستخدم<select v-model="userId" aria-label="مستخدم ضوابط الوقت" :disabled="state.saving"><option value="">اختر المستخدم</option><option v-for="user in choices" :key="user.id" :value="user.id">{{user.name}} · {{user.login}}</option></select></label><label v-if="userId" class="account-time-switch account-time-master"><input type="checkbox" v-model="draft.enabled" aria-label="تفعيل ضوابط وقت الحساب" :disabled="state.loading||state.saving"><span>تفعيل ضوابط وقت الحساب</span></label></div>
    <form v-if="userId" @submit.prevent="save">
      <fieldset class="account-time-grid" :disabled="!draft.enabled||state.loading||state.saving">
        <section class="account-time-rule" :class="{enabled:draft.enabled&&draft.dateEnabled}"><label class="account-time-switch"><input type="checkbox" v-model="draft.dateEnabled" aria-label="تفعيل مدة الصلاحية"><span>مدة صلاحية الحساب<small>فترة محددة بتاريخ بداية ونهاية</small></span></label><div v-if="draft.dateEnabled" class="account-time-pair"><label>تاريخ ووقت البداية — بغداد<input type="datetime-local" v-model="draft.startAt" required></label><label>تاريخ ووقت النهاية — بغداد<input type="datetime-local" v-model="draft.endAt" required></label></div><p v-else class="account-time-hint">بدون تقييد بتاريخ</p></section>
        <section class="account-time-rule" :class="{enabled:draft.enabled&&draft.hoursEnabled}"><label class="account-time-switch"><input type="checkbox" v-model="draft.hoursEnabled" aria-label="تفعيل ساعات الدوام"><span>ساعات الدوام<small>وقت دخول يومي مسموح</small></span></label><div v-if="draft.hoursEnabled" class="account-time-pair"><label>بدء الدوام — بغداد<input type="time" v-model="draft.startTime" required></label><label>انتهاء الدوام — بغداد<input type="time" v-model="draft.endTime" required></label></div><p v-else class="account-time-hint">بدون تقييد بساعات دوام</p></section>
        <section class="account-time-rule" :class="{enabled:draft.enabled&&draft.idleEnabled}"><label class="account-time-switch"><input type="checkbox" v-model="draft.idleEnabled" aria-label="تفعيل مهلة الخمول"><span>الخروج عند الخمول<small>يُحسب عند عدم استخدام الحساب</small></span></label><div v-if="draft.idleEnabled" class="account-time-duration"><label>مهلة الخمول بالدقائق<input type="number" min="1" max="525600" step="1" v-model.number="draft.idleMinutes" required></label><div class="account-time-presets" role="group" aria-label="اختصارات مهلة الخمول"><button v-for="n in [15,30,60,120]" :key="n" type="button" :class="{active:Number(draft.idleMinutes)===n}" :aria-pressed="Number(draft.idleMinutes)===n" @click="draft.idleMinutes=n">{{n}} دقيقة</button></div></div><p v-else class="account-time-hint">الخروج بسبب الخمول غير مفعّل</p></section>
        <section class="account-time-rule" :class="{enabled:draft.enabled&&draft.sessionEnabled}"><label class="account-time-switch"><input type="checkbox" v-model="draft.sessionEnabled" aria-label="تفعيل مدة الجلسة"><span>مدة الجلسة الإجبارية<small>خروج دوري حتى أثناء الاستخدام</small></span></label><div v-if="draft.sessionEnabled" class="account-time-duration"><label>مدة الجلسة بالدقائق<input type="number" min="1" max="525600" step="1" v-model.number="draft.sessionMinutes" required></label><div class="account-time-presets" role="group" aria-label="اختصارات مدة الجلسة"><button v-for="n in [30,60,120,240]" :key="n" type="button" :class="{active:Number(draft.sessionMinutes)===n}" :aria-pressed="Number(draft.sessionMinutes)===n" @click="draft.sessionMinutes=n">{{n}} دقيقة</button></div></div><p v-else class="account-time-hint">الخروج الدوري غير مفعّل</p></section>
      </fieldset><div class="account-time-save"><span>{{draft.enabled?'تُطبّق الخيارات المفعّلة فقط':'ضوابط وقت هذا الحساب معطّلة'}}</span><button class="btn primary" :disabled="state.loading||state.saving">{{state.saving?'جارٍ الحفظ…':'حفظ أوقات الحساب'}}</button></div>
    </form><p v-else class="empty">{{state.loading?'جارٍ التحميل…':'اختر مستخدمًا لضبط أوقات حسابه'}}</p>
    <div class="toolbar account-time-saved-heading"><h3>الإعدادات المحفوظة</h3></div><TablePanel class="tablewrap"><table><thead><tr><th>المستخدم</th><th>الحالة</th><th>مدة الصلاحية</th><th>الدوام — بغداد</th><th>الخمول / الجلسة بالدقائق</th><th>الإجراء</th></tr></thead><tbody><tr v-for="user in saved" :key="user.id"><td>{{user.name}}</td><td>{{user.policy.enabled?'مفعّل':'معطّل'}}</td><td>{{user.policy.dateEnabled?`${user.policy.startAt} — ${user.policy.endAt}`:'—'}}</td><td>{{user.policy.hoursEnabled?`${user.policy.startTime} — ${user.policy.endTime}`:'—'}}</td><td>{{user.policy.idleEnabled?user.policy.idleMinutes:'—'}} / {{user.policy.sessionEnabled?user.policy.sessionMinutes:'—'}}</td><td><button class="btn small" :disabled="state.saving" @click="userId=user.id">تعديل</button></td></tr></tbody></table></TablePanel>
  </section></section><p v-else class="notice warn">أوقات صلاحية الحساب متاحة لمدير النظام فقط.</p>
</template>

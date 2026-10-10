<script setup>
import FilterBar from '../../shared/components/FilterBar.vue';
import TablePanel from '../../shared/components/TablePanel.vue';

const CategoryAssignmentDialog = defineAsyncComponent(
  () => import("../catalog/CategoryAssignmentDialog.vue"),
);
import {
  computed,
  defineAsyncComponent,
  onBeforeUnmount,
  onMounted,
  reactive,
  ref,
  watch,
} from "vue";
import { useRoute } from "vue-router";
import { useAccountAccess } from "./use-account-access.js";
import { createPagedResource } from "./paged-resource.js";
import { accountTypeLabel } from "../../app/portal-config.js";
import { childTypes } from "./child-types.js";
import { initialAccountContext } from "./account-model.js";
import AccountRowActions from "./AccountRowActions.vue";
import AccountModal from './AccountModal.vue';
const ArchiveConfirm = defineAsyncComponent(() => import('../archive/ArchiveConfirm.vue'));
const archiveBusy = ref(false);
import "./accounts.css";
import NetworkHierarchy from "./NetworkHierarchy.vue";
const networkRevision = ref(0),posFiltersOpen=ref(false);
const AccountEditor = defineAsyncComponent(
  () => import("./CreateAccountDialog.vue"),
);
const DetailsDialog = defineAsyncComponent(
  () => import("./AccountDetailsDialog.vue"),
);
const ActionDialog = defineAsyncComponent(() => import("./ActionDialog.vue"));
const PermissionEditor = defineAsyncComponent(
  () => import("./PermissionEditor.vue"),
);
const { session, router, handleFailure } = useAccountAccess();
const route = useRoute();
const isPos = computed(
  () => route.name === "pos" || session.state.identity?.account.type === "pos",
);
const filters = reactive({
  q: "",
  type: "",
  status: "",
  city: "",
  per_page: 10,
});
const view = ref("list"),
  path = ref([]),
  treeMode = ref("agents"),
  cities = ref([]),
  root = ref(null),
  modal = ref(null),
  success = ref(""),
  hidden = ref([]);
const resource = createPagedResource(
  (parameters, signal) => session.api.accounts(parameters, signal),
  handleFailure,
);
const state = resource.state;
const columns = [
  ["name", "الاسم التجاري"],
  ["owner_name", "اسم صاحب المكتب"],
  ["created_at", "تاريخ إنشاء الحساب"],
  ["phone", "رقم الهاتف"],
  ["representative", "المندوب"],
  ["parent", "الوكيل"],
  ["city", "المحافظة"],
  ["device_model", "الجهاز"],
  ["serial", "سيريال الجهاز"],
  ["status", "الحالة"],
];
const shown = computed(() =>
  columns.filter(([key]) => !hidden.value.includes(key)),
);
const focused = computed(() => root.value);
const summary = computed(() => state.meta.summary || {});
let timer;
const controller = new AbortController();
function parameters(page = 1) {
  return {
    ...filters,
    page,
    kind: isPos.value
      ? "pos"
      : view.value === "list"
        ? "agents"
        : treeMode.value,
    
  };
}
async function load(page = 1, refreshNetwork = false) {
  await resource.load(parameters(page));
  if (refreshNetwork) networkRevision.value++;
}
async function exportAccounts(){
  try{const {workbook}=await import('../../shared/files/excel-export.js'); const url=URL.createObjectURL(workbook(state.rows.map(row=>({'الوكيل':row.name,'المستوى':accountTypeLabel(row.type),'الوكيل الأعلى':row.parent?.name||'—','المحافظة':row.city||'—','الحالة':row.status==='active'?'مفعل':'موقوف'}))));const link=document.createElement('a');link.href=url;link.download='agents.xlsx';link.click();setTimeout(()=>URL.revokeObjectURL(url),0);}catch(cause){state.error=await handleFailure(cause);}
}
function toggleColumn(key) {
  hidden.value = hidden.value.includes(key)
    ? hidden.value.filter((value) => value !== key)
    : [...hidden.value, key];
}
function columnValue(account, key) {
  if(key === "representative")return account.representative_names?.join("، ")||"—";
  if (key === "parent") return account.parent?.name || "—";
  if (key === "created_at")
    return account.created_at
      ? new Date(account.created_at).toLocaleDateString("ar-IQ")
      : "—";
  return account[key] || "—";
}
function open(account) {
  path.value = [...path.value, account];
  treeMode.value = "agents";
  filters.q = "";
  filters.type = "";
  load();
}
function goBack(index) {
  path.value = path.value.slice(0, index + 1);
  load();
}
function newAccount(
  kind = isPos.value ? "pos" : "agents",
  parent = focused.value || root.value,
) {
  if (parent) modal.value = { kind: "edit", createKind: kind, parent };
}
async function rowAction(kind, account) {
  success.value = "";
  if (kind === "create") {
    newAccount(account.type === "sub_branch" ? "pos" : "agents", account);
    return;
  }
  if (kind === "edit" || kind === "login" || kind === "permissions") {
    try {
      const result = await session.api.account(account.id, controller.signal);
      modal.value = { kind, account: result.data };
    } catch (failure) {
      if (failure.name !== "AbortError")
        state.error = await handleFailure(failure);
    }
    return;
  }
  modal.value = { kind, account };
}
function statusAction(payload, signal) {
  return session.api.setAccountStatus(
    modal.value.account.id,
    {
      version: modal.value.account.version,
      status: modal.value.account.status === "active" ? "disabled" : "active",
      ...payload,
    },
    signal,
  );
}
function loginAction(payload, signal) {
  return session.api.updateAccountLogin(
    modal.value.account.id,
    { version: modal.value.account.version, ...payload },
    signal,
  );
}
async function saved() {
  modal.value = null;
  success.value = "تم حفظ التغييرات.";
  await session.refresh();
  if (!session.state.identity) return router.replace({ name: "login" });
  await load(state.meta.current_page, true);
}
async function created() {
  modal.value = null;
  success.value =
    "تم إنشاء الحساب ومستخدم الدخول. يمكنك إضافة الصور من التفاصيل.";
  await load(1, true);
}
function mediaChanged(version) {
  const id = modal.value?.account.id;
  for (const account of [
    ...state.rows,
    ...path.value,
    ...(root.value ? [root.value] : []),
  ])
    if (account.id === id) account.version = version;
}
watch(
  () => [
    filters.q,
    filters.type,
    filters.status,
    filters.city,
    filters.per_page,
  ],
  () => {
    clearTimeout(timer);
    timer = setTimeout(() => load(), 250);
  },
);
watch(
  () => [view.value, isPos.value],
  () => {
    path.value = [];
    filters.type = "";
    load();
  },
);
watch(treeMode, () => load());
function quickCreate(){
  if (!['agent','pos'].includes(route.query.quick) || !session.can('account.create') || !root.value || modal.value) return;
  newAccount(route.query.quick==='pos' ? 'pos' : 'agents', root.value);
}
watch(() => route.query.quick, quickCreate);
onMounted(async () => {
  const list = load();
  const details = (async () => {
    const context = await initialAccountContext(session.state.identity, session.api, controller.signal);
    const response = await session.api.account(context.id, controller.signal);
    if (!controller.signal.aborted) root.value = response.data;
  })();
  const options = session.api.accountOptions(controller.signal).then(response => {
    if (!controller.signal.aborted) cities.value = response.data.cities;
  });
  const results = await Promise.allSettled([list, details, options]);
  if (controller.signal.aborted) return;
  for (const result of results) {
    if (result.status === 'rejected' && result.reason.name !== 'AbortError') {
      state.error = await handleFailure(result.reason);
    }
  }
  quickCreate();
});
onBeforeUnmount(() => {
  clearTimeout(timer);
  controller.abort();
  resource.dispose();
});
</script>
<template>
  <div class="account-workspace network-workspace" :aria-label="isPos ? 'نقاط البيع' : 'الوكلاء'">
    <p v-if="success" class="notice notice-success" role="status">
      {{ success }}
    </p>
    <div class="card workspace-card">
      <FilterBar class="agents-unified-toolbar" :class="{'pos-original-toolbar':isPos}">
        <div v-if="!isPos" class="tabs" aria-label="طريقة عرض الوكلاء">
          <button :class="{ active: view === 'list' }" @click="view = 'list'">
            قائمة الوكلاء</button
          ><button :class="{ active: view === 'tree' }" @click="view = 'tree'">
            التوزيع التنظيمي</button
          ><button
            :class="{ active: view === 'compact' }"
            @click="view = 'compact'"
          >
            عرض مختصر
          </button>
        </div>
        <input
          v-model="filters.q"
          type="search"
          :aria-label="isPos ? 'بحث نقاط البيع' : 'بحث الوكلاء والفروع'"
          :placeholder="
            isPos ? 'بحث بالاسم أو الهاتف أو المندوب…' : 'بحث بالاسم أو المحافظة…'
          "
        />
        <select
          v-if="!isPos"
          v-model="filters.type"
          aria-label="مستوى الوكيل"
        >
          <option value="">الكل</option>
          <option value="main_agent">وكيل رئيسي</option>
          <option value="sub_agent">وكيل فرعي</option>
          <option value="sub_branch">فرع فرعي</option>
        </select>
        <div class="workspace-actions">
          <button
            v-if="
              session.can('account.create') &&
              session.state.identity?.membership?.kind === 'owner' &&
              session.state.identity?.account.type !== 'pos' &&
              (!isPos ||
                (session.can('pos.device') && session.can('pos.location')))
            "
            class="btn primary"
            @click="newAccount()"
          >
            {{ isPos ? "إضافة نقطة بيع" : root?.type === "system" ? "إضافة وكيل رئيسي" : "إضافة وكيل فرعي" }}</button
          ><button v-if="!isPos && root?.type==='system' && session.can('account.create')" class="btn" :disabled="!state.rows.some(row=>['main_agent','sub_agent'].includes(row.type))" @click="newAccount('agents',state.rows.find(row=>['main_agent','sub_agent'].includes(row.type)))">إضافة وكيل فرعي</button><button
            class="btn"
            :disabled="state.loading"
            @click="isPos ? posFiltersOpen=!posFiltersOpen : exportAccounts()" :aria-label="isPos?'فلترة نقاط البيع':'تصدير'" :aria-expanded="isPos?posFiltersOpen:undefined" :aria-controls="isPos?'pos-search-filters':undefined"
          >
            {{isPos ? 'فلترة' : 'تصدير'}}
          </button>
        </div>
        <span v-if="isPos" class="pos-record-count">{{state.meta.total}} سجل</span><details v-if="isPos" class="pos-column-picker">
          <summary class="btn">إظهار الأعمدة ▾</summary>
          <div class="pos-column-options">
            <strong>الأعمدة الظاهرة</strong
            ><label
              v-for="column in columns.filter(([key]) => key !== 'name')"
              :key="column[0]"
              class="pos-column-option"
              ><input
                type="checkbox"
                :checked="!hidden.includes(column[0])"
                @change="toggleColumn(column[0])"
              /><span>{{ column[1] }}</span></label
            >
          </div>
        </details>
<select v-if="isPos" v-model.number="filters.per_page" aria-label="عدد نقاط البيع في الصفحة"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select><small v-if="isPos">{{state.meta.total ? (state.meta.current_page-1)*filters.per_page+1 : 0}}–{{Math.min(state.meta.current_page*filters.per_page,state.meta.total)}} من {{state.meta.total}}</small></FilterBar>
      <div v-if="isPos && posFiltersOpen" id="pos-search-filters" class="pos-filter-panel">        <label>حالة الحساب<select v-model="filters.status" aria-label="حالة الحساب">
          <option value="">كل الحالات</option>
          <option value="active">مفعل</option>
          <option value="disabled">موقوف</option>
        </select></label>
        <label>المحافظة<select v-model="filters.city" aria-label="المحافظة">
          <option value="">كل المحافظات</option>
          <option v-for="city in cities" :key="city">{{ city }}</option>
        </select></label>
<button type="button" class="btn" @click="filters.status='';filters.city=''">مسح الفلاتر</button></div>
      <div v-if="!isPos && view === 'tree'" class="network-summary">
        <span
          v-for="type in ['main_agent', 'sub_agent', 'sub_branch', 'pos']"
          :key="type"
          ><b>{{ summary[type] ?? "—" }}</b
          >{{ accountTypeLabel(type) }}</span
        >
      </div>
      <NetworkHierarchy v-if="!isPos && view !== 'list'" :mode="view" :query="filters.q" :type="filters.type" :revision="networkRevision" @action="rowAction" />
      <template v-if="isPos || view === 'list'">
      <div v-if="!isPos" class="network-list-size"><select v-model.number="filters.per_page" aria-label="عدد الحسابات في الصفحة"><option v-for="size in [10,25,50,100]" :value="size">{{size}}</option></select><span>{{state.meta.total}} حساب</span></div>
      <p v-if="state.loading" class="read-loading empty" role="status">
        جارٍ تحميل الحسابات…
      </p>
      <div v-else-if="state.error" class="notice notice-error" role="alert">
        {{ state.error }}
        <button class="text-button" @click="load()">إعادة المحاولة</button>
      </div>
      <template v-else>
        <TablePanel :start="(state.meta.current_page-1)*filters.per_page+1" class="tablewrap">
          <table>
            <thead>
              <tr>
                <template v-if="isPos"
                  ><th v-for="column in shown" :key="column[0]" scope="col">
                    {{ column[1] }}
                  </th></template
                ><template v-else
                  ><th scope="col">الوكيل</th>
                  <th scope="col">المستوى</th>
                  <th scope="col">الوكيل الأعلى</th>
                  <th scope="col">المحافظة</th>
                  
                  <th scope="col">الحالة</th></template
                >
                <th scope="col">الإجراءات</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="account in state.rows"
                :key="account.id"
                :class="{ 'agent-colored-row': !isPos }"
                :style="{ '--agent-row-color': account.color || '#168baf' }"
              >
                <template v-if="isPos"
                  ><td v-for="column in shown" :key="column[0]">
                    <span
                      v-if="column[0] === 'status'"
                      class="badge"
                      :class="{ neutral: account.status !== 'active' }"
                      >{{
                        account.status === "active" ? "مفعل" : "موقوف"
                      }}</span
                    ><span v-else>{{ columnValue(account, column[0]) }}</span>
                  </td></template
                ><template v-else
                  ><td>{{ account.name }}</td>
                  <td>{{ accountTypeLabel(account.type) }}</td>
                  <td>{{ account.parent?.name || "—" }}</td>
                  <td>{{ account.city || "—" }}</td>
                  
                  <td>
                    <span
                      class="badge"
                      :class="{ neutral: account.status !== 'active' }"
                      >{{
                        account.status === "active" ? "مفعل" : "موقوف"
                      }}</span
                    >
                  </td></template
                >
                <td>
                  <div class="actions">
                    <button
                      v-if="view !== 'list' && account.type !== 'pos'"
                      class="btn small"
                      @click="open(account)"
                    >
                      فتح</button
                    ><AccountRowActions
                      :account="account"
                      :compact="!isPos"
                      @categories="rowAction('categories', $event)"
                      @details="rowAction('details', $event)"
                      @edit="rowAction('edit', $event)"
                      @create="rowAction('create', $event)"
                      @status="rowAction('status', $event)"
                      @permissions="rowAction('permissions', $event)"
                      @login="rowAction('login', $event)" @archive="rowAction('archive', $event)"
                    />
                  </div>
                </td>
              </tr>
<tr v-if="!state.rows.length"><td :colspan="isPos?shown.length+1:6" class="empty">{{isPos?"لا توجد نقاط بيع مطابقة":"لا توجد حسابات مطابقة"}}</td></tr></tbody>
          </table>
        </TablePanel>
        <div class="table-pagination-footer">
          <button
            class="btn small"
            :disabled="state.loading || state.meta.current_page <= 1"
            @click="load(state.meta.current_page - 1)"
          >
            السابق</button
          ><span
            >الصفحة {{ state.meta.current_page }} من
            {{ state.meta.last_page }} · {{ state.meta.total }} حساب</span
          ><label
            >عرض
            <select
              v-model.number="filters.per_page"
              aria-label="عدد الحسابات في الصفحة"
            >
              <option
                v-for="size in [10, 25, 50, 100]"
                :key="size"
                :value="size"
              >
                {{ size }}
              </option>
            </select></label
          ><button
            class="btn small"
            :disabled="
              state.loading || state.meta.current_page >= state.meta.last_page
            "
            @click="load(state.meta.current_page + 1)"
          >
            التالي
          </button>
        </div>
      </template>
      </template>
    </div>
    <CategoryAssignmentDialog
      v-if="modal?.kind === 'categories'"
      :account="modal.account"
      @close="modal = null"
      @saved="saved"
    />
    <AccountEditor
      v-if="modal?.kind === 'edit'"
      :account="modal.account"
      :parent="modal.parent"
      :kind="
        modal.account?.type === 'pos' ? 'pos' : modal.createKind || 'agents'
      "
      @close="modal = null"
      @created="created"
      @saved="saved"
      @changed="mediaChanged"
    />
    <DetailsDialog
      v-if="modal?.kind === 'details'"
      @login="rowAction('login',$event)" @create="rowAction('create',$event)"
      :account="modal.account"
      @close="modal = null"
      @changed="mediaChanged"
    />
    <ActionDialog
      v-if="modal?.kind === 'status'"
      :title="
        modal.account.status === 'active' ? 'إيقاف الحساب' : 'تفعيل الحساب'
      "
      :description="modal.account.name"
      :action="statusAction"
      @close="modal = null"
      @saved="saved"
    />
    <ActionDialog
      v-if="modal?.kind === 'login'"
      title="تعديل حساب الدخول"
      :description="modal.account.name"
      :login="
        modal.account.owner_user?.login || modal.account.owner_user?.email || ''
      "
      :action="loginAction"
      @close="modal = null"
      @saved="saved"
    />
    <PermissionEditor
      v-if="modal?.kind === 'permissions'"
      :account="modal.account"
      @close="modal = null"
      @saved="saved"
    />
    <AccountModal v-if="modal?.kind === 'archive'" title="حذف الحساب أرشيفيًا" :busy="archiveBusy" @close="modal = null"><ArchiveConfirm :account="modal.account" @busy="archiveBusy = $event" @cancel="modal = null" @archived="saved" /></AccountModal>
  </div>
</template>


<style scoped>
.network-list-size{display:flex;justify-content:space-between;align-items:center;margin:22px 0 12px}.network-list-size select{width:90px}.network-workspace .agents-unified-toolbar>input{flex:1;min-width:130px}.network-workspace .agents-unified-toolbar>select{width:150px}.network-workspace .table-pagination-footer>label{display:none}.network-workspace .table-pagination-footer{justify-content:flex-end;gap:22px}
</style>

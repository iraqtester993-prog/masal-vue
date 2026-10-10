<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import { computed, reactive, provide, watch, onBeforeUnmount } from "vue";
import { useRoute, useRouter } from "vue-router";
import { usePortal } from "../auth/session.js";
import { createCatalogApi } from "./catalog-api.js";
import {
  catalogKey,
  tr,
  money,
  newProduct,
  productFromApi,
  providerFromApi,
  productPayload,
  productFields,
  providerFields,
  cardFields,
} from "./catalog-model.js";
import { vMoney } from "./money-input.js";
import CategoryTable from "./CategoryTable.vue";
import ProviderTable from "./ProviderTable.vue";
import CategoryDailyLimit from "./CategoryDailyLimit.vue";
import CategoryEditor from "./CategoryEditor.vue";
import ProviderEditor from "./ProviderEditor.vue";
import CategoryPreview from "./CategoryPreview.vue";
import ProviderPreview from "./ProviderPreview.vue";
import CatalogModal from "./CatalogModal.vue";
import "./catalog.css";
const { session } = usePortal(),
  route = useRoute(),
  router = useRouter(),
  api = createCatalogApi(session.api);
const lifetime = new AbortController();
let read = null,
  revision = 0,
  prefQueue = Promise.resolve();
const emptyFilters = () => ({
  query: "",
  provider: "",
  kind: "",
  state: "",
  supplier: "",
  connection: "",
  from: "",
  to: "",
});
const vm = reactive({
  page: "products",
  rows: [],
  options: { providers: [], cities: [] },
  meta: { current_page: 1, last_page: 1, total: 0, per_page: 10 },
  perPage: 10,
  filters: emptyFilters(),
  hiddenColumns: [],
  busy: false,
  saving: false,
  error: "",
  formError: "",
  success: "",
  modal: null,
  editForm: {},
  categorySpecificCities: false,
  categoryImageBusy: false,
  providerLogoBusy: false,
  imageFile: null,
  tr,
  money,
  emptyFilters,
  currentUser: session.state.identity?.user.id,
  get governorates() {
    return this.options.cities;
  },
  get filtersActive() {
    return Object.values(this.filters).some(Boolean);
  },
  get search() {
    return this.filters.query;
  },
  can(key) {
    if (!session.can(key)) return false;
    if (
      /^(products|providers)\.(create|edit|toggle|order|fields|images|availability)$/.test(
        key,
      )
    ) {
      const i = session.state.identity;
      return (
        i?.account.type === "system" &&
        (i.membership.kind === "owner" ||
          i.membership.scope_roots?.includes(i.account.id))
      );
    }
    return true;
  },
  nameOf(_, id) {
    return this.options.providers?.find((p) => p.id === id)?.name ?? "—";
  },
  load,
  applyFilters(values) {
    this.filters = { ...values };
    load(1);
  },
  toggleColumn(key) {
    const before = [...this.hiddenColumns];
    this.hiddenColumns = this.hiddenColumns.includes(key)
      ? this.hiddenColumns.filter((x) => x !== key)
      : [...this.hiddenColumns, key];
    const selected = [...this.hiddenColumns];
    prefQueue = prefQueue
      .then(() => api.savePreferences(selected, lifetime.signal))
      .catch((error) => {
        if (error.name !== "AbortError") {
          this.hiddenColumns = before;
          this.error = "تعذر حفظ اختيار الأعمدة. " + error.message;
        }
      });
  },
  openEdit(row) {
    this.formError = "";
    this.imageFile = null;
    this.editForm = row
      ? JSON.parse(JSON.stringify(row))
      : this.page === "products"
        ? newProduct()
        : { name: "", supplier: "", connection: "ملفات", logo: "" };
    this.categorySpecificCities = !!this.editForm.allowedCities?.length;
    this.modal = { kind: "edit" };
  },
  closeModal() {
    if (!this.saving) this.modal = null;
  },
  previewCategory(record) {
    this.modal = { kind: "preview", record };
  },
  previewProvider(record) {
    this.modal = { kind: "preview", record };
  },
  toggleEntity(row) {
    this.modal = { kind: "status", record: row };
  },
  moveProduct(id, direction) {
    runMutation(() =>
      api.move(
        this.rows.find((p) => p.id === id),
        direction,
        lifetime.signal,
      ),
    );
  },
  exportCurrent,
});
provide(catalogKey, vm);
const fields = computed(() =>
    vm.page === "products" ? productFields : providerFields,
  ),
  title = computed(() =>
    vm.page === "products" ? "المنتجات والفئات" : "الشركات والمزودون",
  ),
  modalTitle = computed(() =>
    vm.modal?.kind === "edit"
      ? vm.page === "products"
        ? vm.editForm.id
          ? "تعديل الفئة"
          : "إضافة فئة"
        : vm.editForm.id
          ? "تعديل الشركة"
          : "إضافة شركة"
      : vm.modal?.kind === "status"
        ? vm.modal.record.active
          ? "تعطيل"
          : "تفعيل"
        : vm.page === "products"
          ? "معاينة الفئة"
          : "معاينة الشركة",
  );
async function fail(error) {
  if (error.name === "AbortError") return;
  if ([401, 419].includes(error.status)) {
    await session.refresh();
    if (!session.state.identity) await router.replace("/login");
  }
  vm.error = error.message;
}
async function load(page = 1) {
  read?.abort();
  read = new AbortController();
  const ticket = ++revision;
  vm.busy = true;
  vm.error = "";
  try {
    const result = await api.list(
      vm.page,
      {
        ...vm.filters,
        page: vm.perPage === 0 ? 1 : page,
        per_page: vm.perPage === 0 ? 100 : vm.perPage,
      },
      read.signal,
    );
    if (vm.perPage === 0) {
      if (result.meta.total > 10000)
        throw Error("حدد الفلاتر لعرض 10000 سجل أو أقل.");
      for (let next = 2; next <= result.meta.last_page; next++) {
        if (ticket !== revision) return;
        const more = await api.list(
          vm.page,
          { ...vm.filters, page: next, per_page: 100 },
          read.signal,
        );
        result.data.push(...more.data);
      }
      result.meta = {
        ...result.meta,
        current_page: 1,
        last_page: 1,
        per_page: result.data.length || 1,
      };
    }
    if (ticket !== revision) return;
    vm.rows = result.data.map(
      vm.page === "products" ? productFromApi : providerFromApi,
    );
    vm.meta = result.meta;
  } catch (error) {
    if (ticket === revision) await fail(error);
  } finally {
    if (ticket === revision) vm.busy = false;
  }
}
async function initialize() {
  vm.page = route.meta.catalog;
  vm.filters = emptyFilters();
  vm.modal = null;
  vm.success = "";
  try {
    const result = await api.options(lifetime.signal);
    vm.options = result.data;
    if (vm.page === "products") {
      const pref = await api.preferences(lifetime.signal);
      vm.hiddenColumns = pref.data.hidden_columns;
    }
  } catch (error) {
    await fail(error);
  }
  await load();
}
watch(() => route.meta.catalog, initialize, { immediate: true });
onBeforeUnmount(() => {
  revision++;
  read?.abort();
  lifetime.abort();
});
async function runMutation(action) {
  if (vm.saving) return;
  vm.saving = true;
  vm.formError = "";
  try {
    await action();
    vm.modal = null;
    vm.success = "تم حفظ التغييرات.";
    const options = await api.options(lifetime.signal);
    vm.options = options.data;
    await load(vm.meta.current_page);
  } catch (error) {
    vm.formError =
      Object.values(error.errors ?? {})
        .flat()
        .join(" · ") || error.message;
    await fail(error);
  } finally {
    vm.saving = false;
  }
}
function optionsFor(field) {
  return field.options === "providers"
    ? vm.options.providers.map((p) => ({ value: p.id, label: p.name }))
    : field.options.map((x) => ({ value: x, label: x }));
}
async function save() {
  let payload;
  try {
    payload =
      vm.page === "products"
        ? productPayload(vm.editForm, vm.categorySpecificCities)
        : {
            name: vm.editForm.name,
            supplier: vm.editForm.supplier,
            connection: vm.editForm.connection,
            ...(vm.editForm.id ? { version: vm.editForm.version } : {}),
          };
  } catch (error) {
    vm.formError = error.message;
    return;
  }
  const imageKey = vm.page === "products" ? "image" : "logo";
  const old = vm.rows.find((r) => r.id === vm.editForm.id);
  await runMutation(() =>
    api.save(
      vm.page,
      vm.editForm.id,
      payload,
      vm.editForm[imageKey] ? vm.imageFile : null,
      !!old?.[imageKey] && !vm.editForm[imageKey],
      lifetime.signal,
    ),
  );
}
async function exportCurrent() {
  try {
    const result = await api.export(vm.page, vm.filters, lifetime.signal);
    const url = URL.createObjectURL(
      new Blob([result.data.csv], { type: "text/csv;charset=utf-8" }),
    );
    const link = document.createElement("a");
    link.href = url;
    link.download = result.data.filename;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  } catch (error) {
    await fail(error);
  }
}
</script>
<template>
  <div class="catalog-workspace">
    <p v-if="vm.success" class="notice notice-success" role="status">
      {{ vm.success }}
    </p>
    <p v-if="vm.error" class="notice notice-error" role="alert">
      {{ vm.error }}
      <button class="text-button" @click="load(vm.meta.current_page)">
        إعادة المحاولة
      </button>
    </p>
    <div class="card workspace-card" :aria-busy="vm.busy">
      <p v-if="vm.busy && !vm.rows.length" class="read-loading empty" role="status">
        جارٍ التحميل…
      </p>
      <CategoryTable v-if="vm.page === 'products'" /><ProviderTable v-else />
    </div>
    <CatalogModal
      v-if="vm.modal"
      :title="modalTitle"
      :section="tr(title)"
      :busy="vm.saving"
      @close="vm.closeModal()"
      ><p v-if="vm.formError" class="notice notice-error" role="alert">
        {{ vm.formError }}
      </p>
      <form
        v-if="vm.modal.kind === 'edit'"
        @submit.prevent="save"
        :inert="vm.saving"
      >
        <CategoryDailyLimit v-if="vm.page === 'products'" />
        <div class="formgrid">
          <component
            :is="field.type === 'cardFields' ? 'div' : 'label'"
            v-for="field in fields"
            :key="field.key"
            :class="{ full: field.type === 'cardFields' }"
            >{{ tr(field.label) }}
            <div v-if="field.type === 'cardFields'" class="card-field-editor">
              <p class="help">
                حدد البيانات الموجودة في البطاقة. كل حقل محدد مطلوب عند
                الاستيراد، وغير المحدد لا يستخدم.
              </p>
              <label
                v-for="item in cardFields"
                :key="item.key"
                class="card-field-option"
                :class="{
                  'is-selected':
                    vm.editForm.fieldPolicy[item.key] === 'required',
                }"
                ><input
                  v-model="vm.editForm.fieldPolicy[item.key]"
                  type="checkbox"
                  true-value="required"
                  false-value="unused"
                  :aria-label="item.label"
                  :disabled="item.fixed || !vm.can('products.fields')"
                /><span
                  ><b>{{ uiLabel(item.label) }}</b
                  ><small>{{
                    item.fixed
                      ? "مطلوب دائمًا"
                      : vm.editForm.fieldPolicy[item.key] === "required"
                        ? "مطلوب عند الاستيراد"
                        : "غير مستخدم"
                  }}</small></span
                ></label
              >
              <p class="help">
                رمز الشحن وتاريخ الانتهاء مطلوبان للبيع وإدارة صلاحية المخزون.
                يمكن أخذ تاريخ الانتهاء من تاريخ الدفعة عند غيابه من الملف.
              </p>
            </div>
            <select
              v-else-if="field.options"
              v-model="vm.editForm[field.key]"
              required
            >
              <option
                v-for="option in optionsFor(field)"
                :key="option.value"
                :value="option.value"
              >
                {{ tr(option.label) }}
              </option></select
            ><input
              v-else
              v-model="vm.editForm[field.key]"
              v-money="['face', 'min'].includes(field.key)"
              :type="
                ['face', 'min'].includes(field.key)
                  ? 'text'
                  : (field.type ?? 'text')
              "
              :min="field.min"
              :step="field.type === 'number' ? 'any' : undefined"
              required
          /></component>
        </div>
        <CategoryEditor v-if="vm.page === 'products'" /><ProviderEditor
          v-else
        />
        <div class="formfoot">
          <button type="button" class="btn" @click="vm.closeModal()">
            إلغاء</button
          ><button
            type="submit"
            class="btn primary"
            :disabled="vm.saving || vm.categoryImageBusy || vm.providerLogoBusy"
          >
            {{ vm.saving ? "جارٍ الحفظ…" : "حفظ البيانات" }}
          </button>
        </div>
      </form>
      <template v-else-if="vm.modal.kind === 'status'"
        ><p>{{ vm.modal.record.name }}</p>
        <div class="formfoot">
          <button class="btn" :disabled="vm.saving" @click="vm.closeModal()">
            إلغاء</button
          ><button
            class="btn primary"
            :disabled="vm.saving"
            @click="
              runMutation(() =>
                api.status(vm.page, vm.modal.record, lifetime.signal),
              )
            "
          >
            {{ vm.saving ? "جارٍ الحفظ…" : "تأكيد" }}
          </button>
        </div></template
      ><CategoryPreview v-else-if="vm.page === 'products'" /><ProviderPreview
        v-else
    /></CatalogModal>
  </div>
</template>

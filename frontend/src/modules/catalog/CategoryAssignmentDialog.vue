<script setup>
import { ref, onMounted, onBeforeUnmount } from "vue";
import { usePortal } from "../auth/session.js";
import { createCatalogApi } from "./catalog-api.js";
import CatalogModal from "./CatalogModal.vue";
import AgentProductPicker from "./AgentProductPicker.vue";
import "./catalog.css";
const props = defineProps({ account: Object });
const emit = defineEmits(["close", "saved"]);
const { session } = usePortal();
const api = createCatalogApi(session.api),
  controller = new AbortController();
const data = ref(null),
  ids = ref([]),
  busy = ref(false),
  error = ref("");
async function load() {
  try {
    const result = await api.categories(props.account.id, controller.signal);
    data.value = result.data;
    ids.value = [...data.value.selected_ids];
  } catch (failure) {
    error.value = failure.message;
  }
}
onMounted(load);
onBeforeUnmount(() => controller.abort());
async function save() {
  if (busy.value) return;
  busy.value = true;
  error.value = "";
  try {
    const result = await api.saveCategories(
      props.account.id,
      {
        version: data.value.account_version,
        catalog_version: data.value.catalog_version,
        product_ids: ids.value,
      },
      controller.signal,
    );
    emit("saved", result.data);
  } catch (failure) {
    error.value =
      Object.values(failure.errors ?? {})
        .flat()
        .join(" · ") || failure.message;
  } finally {
    busy.value = false;
  }
}
</script>
<template>
  <CatalogModal
    title="الفئات المسموحة"
    :section="account.name"
    :busy="busy"
    @close="emit('close')"
    ><p v-if="error" class="notice notice-error" role="alert">{{ error }}</p>
    <template v-if="data"
      ><AgentProductPicker
        v-model="ids"
        :products="
          data.products.map((p) => ({
            ...p,
            provider: p.provider_id,
            face: p.face_value,
            active: p.status === 'active',
          }))
        "
        :readonly="!data.can_manage || busy"
        inline
      />
      <div class="formfoot">
        <button class="btn" :disabled="busy" @click="emit('close')">
          {{ data.can_manage ? "إلغاء" : "إغلاق" }}</button
        ><button
          v-if="data.can_manage"
          class="btn primary"
          :disabled="busy"
          @click="save"
        >
          {{ busy ? "جارٍ الحفظ…" : "حفظ الفئات" }}
        </button>
      </div></template
    >
    <p v-else-if="!error" class="read-loading empty">جارٍ التحميل…</p></CatalogModal
  >
</template>

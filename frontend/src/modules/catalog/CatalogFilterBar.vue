<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
const props = defineProps({ page: String });
const page = computed(() => props.page);
const opened = ref(false);
const draft = ref({ ...vm.filters });
const providers = computed(() => vm.options.providers ?? []),
  kinds = computed(() => vm.options.kinds ?? []),
  suppliers = computed(() => vm.options.suppliers ?? []),
  connections = computed(() => vm.options.connections ?? []);
const agents = [],
  products = [],
  priceAgents = [];
const filtersActive = computed(() => vm.filtersActive),
  resultCount = computed(() => vm.meta.total ?? 0),
  label = computed(() => (props.page === "products" ? "الفئات" : "الشركات"));
function toggle() {
  opened.value = !opened.value;
  draft.value = { ...vm.filters };
}
function apply() {
  vm.applyFilters(draft.value);
}
function clear() {
  draft.value = vm.emptyFilters();
}
function clearActive() {
  clear();
  vm.applyFilters(draft.value);
}
</script>
<template>
  <section class="catalog-filter" :class="{ 'is-open': opened }">
    <div class="catalog-filter-head">
      <slot name="actions"></slot
      ><button
        type="button"
        class="btn"
        :aria-expanded="opened"
        @click="toggle"
      >
        {{ tr("فلترة وبحث") }}<span v-if="filtersActive"> •</span></button
      ><span v-if="page !== 'prices'" class="caption"
        >{{ tr(label) }} · {{ tr(resultCount) }}{{ tr(" سجلًا مطابقًا") }}</span
      ><button
        v-if="filtersActive"
        type="button"
        class="btn small"
        @click="clearActive"
      >
        {{ tr("مسح الفلاتر") }}</button
      ><slot name="trailing"></slot>
      <div
        v-if="vm.meta.total"
        class="table-pagination-tools table-pagination-inline"
      >
        <label
          ><select
            v-model.number="vm.perPage"
            aria-label="عدد الصفوف في الصفحة"
            @change="vm.load(1)"
          >
            <option
              v-for="size in [10, 25, 50, 100, 0]"
              :key="size"
              :value="size"
            >
              {{ size || "الكل" }}
            </option>
          </select></label
        ><span
          >{{ (vm.meta.current_page - 1) * vm.meta.per_page + 1 }}–{{
            Math.min(vm.meta.current_page * vm.meta.per_page, vm.meta.total)
          }}
          من {{ vm.meta.total }}</span
        >
      </div>
    </div>
    <form v-if="opened" class="catalog-filter-fields" @submit.prevent="apply">
      <label class="catalog-filter-search"
        >{{ tr("بحث")
        }}<input
          type="search"
          v-model="draft.query"
          :placeholder="
            tr(
              page === 'inventory'
                ? 'رقم الدفعة أو الفئة'
                : page === 'providers'
                  ? 'اسم الشركة'
                  : 'اسم أو معرف',
            )
          " /></label
      ><template v-if="page === 'inventory'"
        ><label
          >{{ tr("الوكيل")
          }}<select v-model="draft.agent">
            <option value="">{{ tr("كل المخازن") }}</option>
            <option v-for="a in agents" :key="a.id" :value="a.id">
              {{ tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الفئة")
          }}<select v-model="draft.product">
            <option value="">{{ tr("كل الفئات") }}</option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الحالة")
          }}<select v-model="draft.status">
            <option value="">{{ tr("كل الحالات") }}</option>
            <option
              v-for="s in [
                'Loaded',
                'Partially Used',
                'Completed',
                'Quarantined',
                'Exported',
                'Cancelled by Reversal',
              ]"
              :key="s"
              :value="s"
            >
              {{ tr(vm.status(s)) }}
            </option>
          </select></label
        ><label
          >{{ tr("نوع الدفعة")
          }}<select v-model="draft.tab">
            <option value="all">{{ tr("كل الدفعات") }}</option>
            <option value="active">{{ tr("النشطة") }}</option>
            <option value="stopped">{{ tr("الموقوفة") }}</option>
            <option value="cancelled">{{ tr("الملغاة") }}</option>
          </select></label
        ></template
      ><template v-else-if="page === 'products'"
        ><label
          >{{ tr("الشركة")
          }}<select v-model="draft.provider">
            <option value="">{{ tr("كل الشركات") }}</option>
            <option v-for="p in providers" :key="p.id" :value="p.id">
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("نوع الفئة")
          }}<select v-model="draft.kind">
            <option value="">{{ tr("كل الأنواع") }}</option>
            <option v-for="x in kinds" :key="x" :value="x">
              {{ tr(x) }}
            </option>
          </select></label
        ><label
          >{{ tr("الحالة")
          }}<select v-model="draft.state">
            <option value="">{{ tr("كل الحالات") }}</option>
            <option value="active">{{ tr("مفعلة") }}</option>
            <option value="inactive">{{ tr("معطلة") }}</option>
          </select></label
        ></template
      ><template v-else-if="page === 'providers'"
        ><label
          >{{ tr("المجهز")
          }}<select v-model="draft.supplier">
            <option value="">{{ tr("كل المجهزين") }}</option>
            <option v-for="x in suppliers" :key="x" :value="x">
              {{ tr(x) }}
            </option>
          </select></label
        ><label
          >{{ tr("نوع الربط")
          }}<select v-model="draft.connection">
            <option value="">{{ tr("كل أنواع الربط") }}</option>
            <option v-for="x in connections" :key="x" :value="x">
              {{ tr(x) }}
            </option>
          </select></label
        ><label
          >{{ tr("الحالة")
          }}<select v-model="draft.state">
            <option value="">{{ tr("كل الحالات") }}</option>
            <option value="active">{{ tr("مفعلة") }}</option>
            <option value="inactive">{{ tr("معطلة") }}</option>
          </select></label
        ></template
      ><template v-else=""
        ><label
          >{{ tr("قائمة أسعار الوكيل")
          }}<select v-model="draft.agent">
            <option v-for="a in priceAgents" :key="a.id" :value="a.id">
              {{ tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الفئة")
          }}<select v-model="draft.product">
            <option value="">{{ tr("كل الفئات") }}</option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الشركة")
          }}<select v-model="draft.provider">
            <option value="">{{ tr("كل الشركات") }}</option>
            <option v-for="p in providers" :key="p.id" :value="p.id">
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("السعر")
          }}<select v-model="draft.state">
            <option value="">{{ tr("الكل") }}</option>
            <option value="active">{{ tr("الفئات المفعلة") }}</option>
            <option value="inactive">{{ tr("الفئات المعطلة") }}</option>
            <option value="priced">{{ tr("لها سعر") }}</option>
            <option value="unpriced">{{ tr("بلا سعر") }}</option>
          </select></label
        ></template
      ><label
        >{{ tr("من تاريخ")
        }}<input
          type="date"
          v-model="draft.from"
          :max="draft.to || undefined" /></label
      ><label
        >{{ tr("إلى تاريخ")
        }}<input type="date" v-model="draft.to" :min="draft.from || undefined"
      /></label>
      <div class="catalog-filter-actions">
        <button type="button" class="btn" @click="clear">
          {{ tr("إعادة تعيين") }}</button
        ><button
          type="submit"
          class="btn primary"
          :disabled="!!(draft.from&amp;&amp;draft.to&amp;&amp;draft.from&gt;draft.to)"
        >
          {{ tr("تطبيق الفلتر") }}
        </button>
      </div>
    </form>
  </section>
</template>

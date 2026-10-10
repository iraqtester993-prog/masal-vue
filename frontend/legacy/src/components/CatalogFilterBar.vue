<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["category-table", "catalog-filter-bar"]);
export default options;
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
        {{ $root.tr("فلترة وبحث") }}<span v-if="filtersActive"> •</span></button
      ><span v-if="page !== 'prices'" class="caption"
        >{{ $root.tr(label) }} · {{ $root.tr(resultCount)
        }}{{ $root.tr(" سجلًا مطابقًا") }}</span
      ><button
        v-if="filtersActive"
        type="button"
        class="btn small"
        @click="clearActive"
      >
        {{ $root.tr("مسح الفلاتر") }}</button
      ><slot name="trailing"></slot>
    </div>
    <form v-if="opened" class="catalog-filter-fields" @submit.prevent="apply">
      <label class="catalog-filter-search"
        >{{ $root.tr("بحث")
        }}<input
          type="search"
          v-model="draft.query"
          :placeholder="
            $root.tr(
              page === 'inventory'
                ? 'رقم الدفعة أو الفئة'
                : page === 'providers'
                  ? 'اسم الشركة'
                  : 'اسم أو معرف',
            )
          " /></label
      ><template v-if="page === 'inventory'"
        ><label
          >{{ $root.tr("الوكيل")
          }}<select v-model="draft.agent">
            <option value="">{{ $root.tr("كل المخازن") }}</option>
            <option v-for="a in agents" :key="a.id" :value="a.id">
              {{ $root.tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الفئة")
          }}<select v-model="draft.product">
            <option value="">{{ $root.tr("كل الفئات") }}</option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الحالة")
          }}<select v-model="draft.status">
            <option value="">{{ $root.tr("كل الحالات") }}</option>
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
              {{ $root.tr(vm.status(s)) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("نوع الدفعة")
          }}<select v-model="draft.tab">
            <option value="all">{{ $root.tr("كل الدفعات") }}</option>
            <option value="active">{{ $root.tr("النشطة") }}</option>
            <option value="stopped">{{ $root.tr("الموقوفة") }}</option>
            <option value="cancelled">{{ $root.tr("الملغاة") }}</option>
          </select></label
        ></template
      ><template v-else-if="page === 'products'"
        ><label
          >{{ $root.tr("الشركة")
          }}<select v-model="draft.provider">
            <option value="">{{ $root.tr("كل الشركات") }}</option>
            <option v-for="p in providers" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("نوع الفئة")
          }}<select v-model="draft.kind">
            <option value="">{{ $root.tr("كل الأنواع") }}</option>
            <option v-for="x in kinds" :key="x" :value="x">
              {{ $root.tr(x) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الحالة")
          }}<select v-model="draft.state">
            <option value="">{{ $root.tr("كل الحالات") }}</option>
            <option value="active">{{ $root.tr("مفعلة") }}</option>
            <option value="inactive">{{ $root.tr("معطلة") }}</option>
          </select></label
        ></template
      ><template v-else-if="page === 'providers'"
        ><label
          >{{ $root.tr("المجهز")
          }}<select v-model="draft.supplier">
            <option value="">{{ $root.tr("كل المجهزين") }}</option>
            <option v-for="x in suppliers" :key="x" :value="x">
              {{ $root.tr(x) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("نوع الربط")
          }}<select v-model="draft.connection">
            <option value="">{{ $root.tr("كل أنواع الربط") }}</option>
            <option v-for="x in connections" :key="x" :value="x">
              {{ $root.tr(x) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الحالة")
          }}<select v-model="draft.state">
            <option value="">{{ $root.tr("كل الحالات") }}</option>
            <option value="active">{{ $root.tr("مفعلة") }}</option>
            <option value="inactive">{{ $root.tr("معطلة") }}</option>
          </select></label
        ></template
      ><template v-else=""
        ><label
          >{{ $root.tr("قائمة أسعار الوكيل")
          }}<select v-model="draft.agent">
            <option v-for="a in priceAgents" :key="a.id" :value="a.id">
              {{ $root.tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الفئة")
          }}<select v-model="draft.product">
            <option value="">{{ $root.tr("كل الفئات") }}</option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الشركة")
          }}<select v-model="draft.provider">
            <option value="">{{ $root.tr("كل الشركات") }}</option>
            <option v-for="p in providers" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("السعر")
          }}<select v-model="draft.state">
            <option value="">{{ $root.tr("الكل") }}</option>
            <option value="active">{{ $root.tr("الفئات المفعلة") }}</option>
            <option value="inactive">{{ $root.tr("الفئات المعطلة") }}</option>
            <option value="priced">{{ $root.tr("لها سعر") }}</option>
            <option value="unpriced">{{ $root.tr("بلا سعر") }}</option>
          </select></label
        ></template
      ><label
        >{{ $root.tr("من تاريخ")
        }}<input
          type="date"
          v-model="draft.from"
          :max="draft.to || undefined" /></label
      ><label
        >{{ $root.tr("إلى تاريخ")
        }}<input type="date" v-model="draft.to" :min="draft.from || undefined"
      /></label>
      <div class="catalog-filter-actions">
        <button type="button" class="btn" @click="clear">
          {{ $root.tr("إعادة تعيين") }}</button
        ><button
          type="submit"
          class="btn primary"
          :disabled="!!(draft.from&amp;&amp;draft.to&amp;&amp;draft.from&gt;draft.to)"
        >
          {{ $root.tr("تطبيق الفلتر") }}
        </button>
      </div>
    </form>
  </section>
</template>

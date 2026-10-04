<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["price-editor"]);
export default options;
</script>

<template>
  <section class="card price-editor">
    <nav
      v-if="canEdit"
      class="price-mode-tabs"
      :aria-label="$root.tr('طريقة تعديل الأسعار')"
    >
      <button
        type="button"
        class="btn"
        :class="{ active: mode === 'individual' }"
        :aria-pressed="mode === 'individual'"
        :disabled="busy || vm.priceImportBusy"
        @click="switchMode('individual')"
      >
        {{ $root.tr("تعديل سعر فئة") }}</button
      ><button
        type="button"
        class="btn"
        :class="{ active: mode === 'bulk' }"
        :aria-pressed="mode === 'bulk'"
        :disabled="busy || vm.priceImportBusy"
        @click="switchMode('bulk')"
      >
        {{ $root.tr("تعديل أسعار الفئات") }}
      </button>
    </nav>
    <catalog-filter-bar
      class="price-catalog-filter"
      page="prices"
      :key="'prices-' + vm.currentUser"
      ref="catalogFilters"
      ><template #actions=""
        ><div class="price-scope-summary">
          <span
            class="price-agent-name"
            :title="$root.tr(vm.nameOf('agents', vm.priceAgent))"
            >{{ $root.tr(vm.nameOf("agents", vm.priceAgent)) }}</span
          ><span class="price-category-count"
            >{{ $root.tr(products.length) }}{{ $root.tr(" فئة") }}</span
          >
        </div></template
      ><template #trailing=""
        ><details class="price-toolbar-extra">
          <summary>{{ $root.tr("خيارات إضافية") }}</summary>
          <div class="actions">
            <button
              v-if="vm.can('prices.template')"
              class="btn"
              @click="vm.downloadPrices()"
            >
              {{ $root.tr("تنزيل قالب الأسعار") }}</button
            ><label v-if="vm.can('prices.import')" class="btn"
              >{{
                $root.tr(
                  vm.priceImportBusy
                    ? "جارٍ قراءة الأسعار"
                    : "استيراد Excel / CSV",
                )
              }}<input
                type="file"
                accept=".csv,.xlsx"
                hidden=""
                :disabled="vm.priceImportBusy"
                @change="importFile($event)"
            /></label>
          </div></details></template
    ></catalog-filter-bar>
    <div v-if="canEdit&amp;&amp;mode==='bulk'" class="price-bulk-toolbar">
      <label
        >{{ $root.tr("نطاق التعديل")
        }}<select v-model="scope" :aria-label="$root.tr('نطاق تعديل الأسعار')">
          <option value="filtered">{{ $root.tr("نتائج الفلترة") }}</option>
          <option value="all">{{ $root.tr("كل الفئات") }}</option>
          <option value="selected">
            {{ $root.tr("الفئات المحددة يدويًا") }}
          </option>
        </select></label
      >
      <label
        >{{ $root.tr("نوع التعديل")
        }}<select
          v-model="direction"
          :aria-label="$root.tr('نوع تعديل الأسعار')"
        >
          <option value="add">{{ $root.tr("زيادة مبلغ") }}</option>
          <option value="subtract">{{ $root.tr("نقصان مبلغ") }}</option>
        </select></label
      >
      <label
        >{{ $root.tr("المبلغ · د.ع")
        }}<input
          type="number"
          min="0.01"
          step="0.01"
          v-model="amount"
          :aria-label="$root.tr('مبلغ تعديل الأسعار')"
      /></label>
      <button
        class="btn primary"
        @click="prepare('bulk')"
        :disabled="busy || vm.priceImportBusy || !targetIds.length"
      >
        {{ $root.tr("معاينة التغييرات (") }}{{ $root.tr(targetIds.length) }})
      </button>
    </div>
    <p v-if="error&amp;&amp;!preview" class="notice warn" role="alert">
      {{ $root.tr(error) }}
    </p>
    <div class="tablewrap">
      <table class="price-edit-table">
        <thead>
          <tr>
            <th v-if="canEdit&amp;&amp;mode==='bulk'">
              {{ $root.tr("تحديد") }}
            </th>
            <th>{{ $root.tr("الفئة") }}</th>
            <th v-if="vm.can('data.cost')">{{ $root.tr("أقل سعر مسموح") }}</th>
            <th>{{ $root.tr("السعر الحالي") }}</th>
            <th v-if="canEdit&amp;&amp;mode==='individual'">
              {{ $root.tr("السعر الجديد · د.ع") }}
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in products" :key="p.id">
            <td v-if="canEdit&amp;&amp;mode==='bulk'">
              <input
                type="checkbox"
                v-model="selected"
                :value="p.id"
                :aria-label="$root.tr('تحديد ' + p.name)"
                @change="scope = 'selected'"
              />
            </td>
            <td>{{ $root.tr(p.name) }}</td>
            <td v-if="vm.can('data.cost')">{{ $root.tr(vm.money(p.min)) }}</td>
            <td>
              {{ $root.tr(vm.money(e.policyPrice(vm.priceAgent, p.id))) }}
            </td>
            <td v-if="canEdit&amp;&amp;mode==='individual'">
              <input
                type="number"
                :min="Math.max(0.01, p.min || 0)"
                step="0.01"
                v-model.number="vm.priceDraft[p.id]"
                :aria-label="$root.tr('السعر الجديد ' + p.name)"
                :placeholder="$root.tr('بدون تعديل')"
              />
            </td>
          </tr>
          <tr v-if="!products.length">
            <td colspan="5" class="empty">
              {{ $root.tr("لا توجد فئات مطابقة") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="canEdit&amp;&amp;mode==='individual'" class="formfoot">
      <span>{{ $root.tr(draftCount) }}{{ $root.tr(" تعديلات فردية") }}</span
      ><button
        class="btn"
        @click="vm.priceDraft = {}"
        :disabled="busy || vm.priceImportBusy"
      >
        {{ $root.tr("مسح التعديلات الفردية") }}</button
      ><button
        class="btn primary"
        @click="prepare('draft')"
        :disabled="!draftCount || busy || vm.priceImportBusy"
      >
        {{ $root.tr("معاينة التغييرات") }}
      </button>
    </div>
    <teleport to="body"
      ><dialog
        v-if="preview"
        ref="review"
        class="funding-preview-dialog price-preview-dialog"
        tabindex="-1"
        @cancel.prevent="close"
        :aria-label="$root.tr('معاينة تعديل الأسعار')"
      >
        <div class="support-section-heading">
          <h2>{{ $root.tr("معاينة تعديل الأسعار") }}</h2>
          <button
            class="iconbtn"
            @click="close"
            :disabled="busy"
            :aria-label="$root.tr('إغلاق معاينة الأسعار')"
          >
            ×
          </button>
        </div>
        <p>
          {{ $root.tr(vm.nameOf("agents", preview.agent)) }} ·
          {{ $root.tr(preview.rows.length) }}{{ $root.tr(" فئات") }}
        </p>
        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th>{{ $root.tr("الفئة") }}</th>
                <th>{{ $root.tr("السعر القديم") }}</th>
                <th>{{ $root.tr("التغيير") }}</th>
                <th>{{ $root.tr("السعر الجديد") }}</th>
                <th>{{ $root.tr("الفحص") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in preview.rows" :key="r.product">
                <td>{{ $root.tr(r.name) }}</td>
                <td>{{ $root.tr(vm.money(r.old)) }}</td>
                <td dir="ltr">
                  {{ $root.tr(r.difference&gt;0?'+':'')
                  }}{{ $root.tr(vm.money(r.difference)) }}
                </td>
                <td>{{ $root.tr(vm.money(r.price)) }}</td>
                <td>
                  {{
                    $root.tr(
                      r.error || (r.price === r.old ? "بدون تغيير" : "صالح"),
                    )
                  }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="error" role="alert" class="notice warn">
          {{ $root.tr(error) }}
        </p>
        <div class="actions">
          <button
            class="btn primary"
            :disabled="!preview.valid || busy"
            @click="confirm"
          >
            {{
              $root.tr(
                ["owner", "main"].includes(vm.actor.role)
                  ? "حفظ الأسعار"
                  : "إرسال للاعتماد",
              )
            }}</button
          ><button class="btn" :disabled="busy" @click="close">
            {{ $root.tr("رجوع للتعديل") }}
          </button>
        </div>
      </dialog></teleport
    >
  </section>
</template>

<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["print-policy-settings"]);
export default options;
</script>

<template>
  <section
    v-if="vm.page==='printPolicies'&amp;&amp;vm.actor.role==='owner'"
    class="card print-policy-settings"
  >
    <div class="print-policy-header print-policy-inline">
      <h3>{{ $root.tr("ضوابط الطباعة") }}</h3>
      <div
        class="print-policy-modes"
        role="group"
        :aria-label="$root.tr('نطاق الإعدادات')"
      >
        <button
          type="button"
          :class="{ active: mode === 'general' }"
          :aria-pressed="mode === 'general'"
          @click="mode = 'general'"
        >
          {{ $root.tr("عام") }}</button
        ><button
          type="button"
          :class="{ active: mode === 'custom' }"
          :aria-pressed="mode === 'custom'"
          @click="mode!=='custom'&amp;&amp;fresh()"
        >
          {{ $root.tr("مخصص") }}
        </button>
      </div>
      <label v-if="mode === 'custom'" class="print-policy-name"
        ><span>{{ $root.tr("اسم القاعدة") }}</span
        ><input
          v-model="name"
          :placeholder="$root.tr('مثال: طباعة فروع بغداد')"
          :aria-label="$root.tr('اسم قاعدة الطباعة')" /></label
      ><label
        v-for="(label, key) in labels"
        :key="key"
        class="print-policy-number"
        ><span :title="$root.tr(label)">{{
          $root.tr(
            key === "failedRetries"
              ? "محاولات إعادة الطباعة"
              : key === "maxCards"
                ? "بطاقات الطلب الواحد"
                : "الفاصل بين الطباعات (ثانية)",
          )
        }}</span
        ><input
          type="number"
          :min="key === 'maxCards' ? 1 : 0"
          step="1"
          v-model.number="draft[key]"
          :aria-label="$root.tr(label)" /></label
      ><label class="print-policy-number"
        ><span>{{ $root.tr("البطاقات اليومية (0 بلا حد)") }}</span
        ><input
          type="number"
          min="0"
          step="1"
          v-model.number="draft.dailyCards"
          :aria-label="$root.tr('الحد اليومي للبطاقات المطبوعة')" /></label
      ><label class="print-policy-number"
        ><span>{{ $root.tr("احتساب الحد اليومي") }}</span
        ><select
          v-model="draft.dailyMode"
          :aria-label="$root.tr('احتساب الحد اليومي')"
        >
          <option value="account">{{ $root.tr("لكل حساب مستقلاً") }}</option>
          <option value="network">{{ $root.tr("مجموع شبكة كل وكيل") }}</option>
        </select></label
      >
      <div class="print-policy-inline-actions">
        <button class="btn primary" @click="save">
          {{ $root.tr("حفظ ضوابط الطباعة") }}</button
        ><button v-if="mode === 'custom'" class="btn" @click="fresh">
          {{ $root.tr("قاعدة جديدة") }}
        </button>
      </div>
    </div>
    <template v-if="mode === 'custom'"
      ><div class="formgrid">
        <label
          >{{ $root.tr("نطاق الفئات")
          }}<select
            v-model="draft.dailyProductMode"
            :aria-label="$root.tr('نطاق الفئات للحد اليومي')"
          >
            <option value="all">
              {{ $root.tr("كل الفئات — مجموع مشترك") }}
            </option>
            <option value="selected">
              {{ $root.tr("فئات محددة — حد مستقل لكل فئة") }}
            </option>
          </select></label
        >
      </div>
      <agent-product-picker
        v-if="draft.dailyProductMode === 'selected'"
        :agent="draft"
        field="dailyProducts"
      ></agent-product-picker
      ><print-scope-picker
        v-model="selected"
        :choices="choices"
      ></print-scope-picker
    ></template>
    <h3>{{ $root.tr("النطاقات المحفوظة") }}</h3>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("القاعدة / النطاق") }}</th>
            <th>{{ $root.tr("محاولات الفشل") }}</th>
            <th>{{ $root.tr("بطاقات الطلب") }}</th>
            <th>{{ $root.tr("الفاصل بالثواني") }}</th>
            <th>{{ $root.tr("الحد اليومي") }}</th>
            <th>{{ $root.tr("الفئات") }}</th>
            <th>{{ $root.tr("الاحتساب") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rules" :key="r.id">
            <td>
              <saved-scope-summary
                :rule="r"
                :choices="choices"
              ></saved-scope-summary>
            </td>
            <td>{{ $root.tr(r.policy.failedRetries) }}</td>
            <td>{{ $root.tr(r.policy.maxCards) }}</td>
            <td>{{ $root.tr(r.policy.intervalSeconds) }}</td>
            <td>{{ $root.tr(r.policy.dailyCards || "بلا حد") }}</td>
            <td>
              {{
                $root.tr(
                  r.policy.dailyProductMode === "selected"
                    ? "حد مستقل لكل فئة (" + r.policy.dailyProducts.length + ")"
                    : "كل الفئات — مجموع مشترك",
                )
              }}
            </td>
            <td>
              {{
                $root.tr(
                  r.policy.dailyMode === "network"
                    ? "مجموع شبكة كل وكيل"
                    : "لكل حساب",
                )
              }}
            </td>
            <td>{{ $root.tr(r.active ? "فعال" : "معطّل") }}</td>
            <td>
              <button class="btn small" @click="edit(r)">
                {{ $root.tr("تعديل") }}</button
              ><button v-if="r.active" class="btn small" @click="disable(r)">
                {{ $root.tr("تعطيل") }}</button
              ><button v-else="" class="btn small primary" @click="enable(r)">
                {{ $root.tr("تفعيل") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

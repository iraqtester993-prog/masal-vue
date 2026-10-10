<!-- Template retained from the original component; runtime is API-backed. -->
<script setup>
import { inject, computed, ref } from "vue";
import { catalogKey, tr } from "./catalog-model.js";
const vm = inject(catalogKey);
import { vMoney } from "./money-input.js";
const p = computed(() => vm.editForm);
</script>
<template>
  <section class="category-daily-limit">
    <h3>{{ tr("الحد اليومي للفئة") }}</h3>
    <div class="formgrid">
      <label
        >{{ vm.tr("نوع الحد اليومي")
        }}<select
          v-model="p.dailyLimitType"
          required=""
          :aria-label="tr('نوع الحد اليومي')"
        >
          <option value="" disabled="">{{ tr("اختر نوع الحد") }}</option>
          <option value="quantity">{{ tr("حد الكمية اليومي") }}</option>
          <option value="amount">{{ tr("الحد المالي اليومي") }}</option>
        </select></label
      ><label v-if="p.dailyLimitType === 'quantity'"
        >{{ tr("حد الكمية اليومي")
        }}<input
          type="number"
          min="1"
          step="1"
          required=""
          v-model.number="p.dailyQty"
          :aria-label="tr('حد الكمية اليومي')" /></label
      ><label v-if="p.dailyLimitType === 'amount'"
        >{{ tr("الحد المالي اليومي • د.ع")
        }}<input
          type="text"
          inputmode="decimal"
          v-money=""
          min="0.01"
          step="0.01"
          required=""
          v-model="p.dailyAmount"
          :aria-label="tr('الحد المالي اليومي')"
      /></label>
      <p v-if="!p.dailyLimitType" class="notice full">
        {{
          tr(
            "اختر حد الكمية أو الحد المالي ليظهر حقل القيمة. سيُطبّق حد واحد فقط.",
          )
        }}
      </p>
    </div>
  </section>
</template>

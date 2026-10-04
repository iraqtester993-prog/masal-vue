<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["category-daily-limit"]);
export default options;
</script>

<template>
  <section class="category-daily-limit">
    <h3>{{ $root.tr("الحد اليومي للفئة") }}</h3>
    <div class="formgrid">
      <label
        >{{ vm.tr("نوع الحد اليومي")
        }}<select
          v-model="p.dailyLimitType"
          required=""
          :aria-label="$root.tr('نوع الحد اليومي')"
        >
          <option value="" disabled="">{{ $root.tr("اختر نوع الحد") }}</option>
          <option value="quantity">{{ $root.tr("حد الكمية اليومي") }}</option>
          <option value="amount">{{ $root.tr("الحد المالي اليومي") }}</option>
        </select></label
      ><label v-if="p.dailyLimitType === 'quantity'"
        >{{ $root.tr("حد الكمية اليومي")
        }}<input
          type="number"
          min="1"
          step="1"
          required=""
          v-model.number="p.dailyQty"
          :aria-label="$root.tr('حد الكمية اليومي')" /></label
      ><label v-if="p.dailyLimitType === 'amount'"
        >{{ $root.tr("الحد المالي اليومي • د.ع")
        }}<input
          type="text"
          inputmode="decimal"
          v-money=""
          min="0.01"
          step="0.01"
          required=""
          v-model.number="p.dailyAmount"
          :aria-label="$root.tr('الحد المالي اليومي')"
      /></label>
      <p v-if="!p.dailyLimitType" class="notice full">
        {{
          $root.tr(
            "اختر حد الكمية أو الحد المالي ليظهر حقل القيمة. سيُطبّق حد واحد فقط.",
          )
        }}
      </p>
    </div>
  </section>
</template>

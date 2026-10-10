<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["funding-request-settings"]);
export default options;
</script>

<template>
  <section v-if="visible" class="card funding-request-settings">
    <form @submit.prevent="save">
      <div class="funding-settings-heading">
        <h3>{{ $root.tr("طلبات تمويل نقاط البيع") }}</h3>
        <button class="btn primary" type="submit">
          {{ $root.tr("حفظ إعدادات التمويل") }}
        </button>
      </div>
      <div class="funding-settings-limit">
        <label
          >{{ $root.tr("عدد الطلبات المسموح يوميًا لكل نقطة")
          }}<input
            type="number"
            min="1"
            step="1"
            required=""
            v-model.number="draft.dailyLimit"
            :aria-label="$root.tr('عدد طلبات التمويل اليومية')"
        /></label>
      </div>
      <div class="funding-settings-heading">
        <h4>{{ $root.tr("مبالغ الطلبات المتاحة · د.ع") }}</h4>
        <button class="btn" type="button" @click="add">
          {{ $root.tr("إضافة مبلغ") }}
        </button>
      </div>
      <div class="funding-amount-options">
        <label v-for="(value, i) in draft.amounts" :key="i"
          ><span>{{ $root.tr("المبلغ ") }}{{ $root.tr(i + 1) }}</span>
          <div>
            <input
              type="number"
              min="0.01"
              step="0.01"
              required=""
              v-model.number="draft.amounts[i]"
              :aria-label="$root.tr('مبلغ التمويل ' + (i + 1))"
            /><button
              class="btn small danger"
              type="button"
              :aria-label="$root.tr('حذف مبلغ التمويل ' + (i + 1))"
              @click="draft.amounts.splice(i, 1)"
            >
              {{ $root.tr("حذف") }}
            </button>
          </div></label
        >
      </div>
      <p v-if="!draft.amounts.length" class="notice">
        {{
          $root.tr(
            "لا توجد مبالغ؛ حفظ هذه القائمة يوقف طلبات التمويل الجديدة لنقاط البيع.",
          )
        }}
      </p>
      <p v-if="error" class="notice warn" role="alert">{{ $root.tr(error) }}</p>
    </form>
  </section>
</template>

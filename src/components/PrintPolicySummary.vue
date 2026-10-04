<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["print-policy-summary"]);
export default options;
</script>

<template>
  <div v-if="id" class="notice no-print print-policy-summary">
    <b>{{ $root.tr("ضوابط الطباعة المطبقة") }}</b>
    <div>
      {{ $root.tr("الحد الأقصى للبطاقات في الطلب: ")
      }}{{ $root.tr(policy.maxCards) }}{{ $root.tr(" · محاولات الفشل ")
      }}{{ $root.tr(tx ? "المتبقية" : "المسموحة") }}: {{ $root.tr(remaining)
      }}{{ $root.tr(" · الفاصل: ") }}{{ $root.tr(policy.intervalSeconds)
      }}{{ $root.tr(" ثانية") }}
    </div>
    <div v-if="daily.limit">
      {{ $root.tr("البطاقات اليوم: ") }}{{ $root.tr(daily.used) }} /
      {{ $root.tr(daily.limit) }}{{ $root.tr(" · المتبقي: ")
      }}{{ $root.tr(daily.remaining)
      }}<span v-if="daily.pending"
        >{{ $root.tr(" · قيد الطباعة: ") }}{{ $root.tr(daily.pending) }}</span
      >
    </div>
    <div v-if="wait" role="status">
      {{ $root.tr("الطباعة التالية بعد ") }}{{ $root.tr(wait)
      }}{{ $root.tr(" ثانية") }}
    </div>
    <div v-if="tx?.printPending">
      {{
        $root.tr(
          "بانتظار تسجيل نتيجة المحاولة الحالية. لا تبدأ طباعة ثانية قبل حسم النتيجة.",
        )
      }}
    </div>
    <button
      v-if="tx?.status==='Print Failed'&amp;&amp;remaining&gt;0&amp;&amp;vm.can('sell.reprint')&amp;&amp;vm.can('sell.print')"
      class="btn"
      :disabled="wait&gt;0"
      @click="vm.retryFailedPrint(tx)"
    >
      {{
        $root.tr(
          wait ? "انتظر " + wait + " ثانية" : "إعادة محاولة الطباعة الفاشلة",
        )
      }}</button
    ><span v-if="tx?.status==='Print Failed'&amp;&amp;!remaining">{{
      $root.tr("تم بلوغ الحد؛ اطلب موافقة إعادة الطباعة.")
    }}</span>
  </div>
</template>

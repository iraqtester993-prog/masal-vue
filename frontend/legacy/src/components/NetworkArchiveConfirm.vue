<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["network-archive-confirm"]);
export default options;
</script>

<template>
  <form @submit.prevent="save">
    <p>
      {{
        $root.tr(
          "سيُخفى الحساب ويتوقف تسجيل دخوله، مع الاحتفاظ ببياناته وسجلاته.",
        )
      }}
    </p>
    <label
      >{{ $root.tr("سبب الحذف الأرشيفي")
      }}<textarea
        v-model="reason"
        required=""
        :disabled="busy"
        maxlength="500"
      ></textarea></label
    ><label
      >{{ $root.tr("كلمة مرور حسابك")
      }}<input
        type="password"
        v-model="password"
        required=""
        autocomplete="current-password"
        :disabled="busy"
    /></label>
    <p v-if="error" class="notice warn" role="alert">{{ $root.tr(error) }}</p>
    <div class="formfoot">
      <button
        type="button"
        class="btn"
        :disabled="busy"
        @click="vm.closeModal()"
      >
        {{ $root.tr("إلغاء") }}</button
      ><button
        class="btn danger"
        :disabled="busy || !password || !reason.trim()"
      >
        {{ $root.tr(busy ? "جارٍ التحقق…" : "تأكيد الحذف الأرشيفي") }}
      </button>
    </div>
  </form>
</template>

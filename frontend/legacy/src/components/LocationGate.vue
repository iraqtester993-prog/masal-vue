<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["location-gate"]);
export default options;
</script>

<template>
  <teleport to="body"
    ><dialog
      ref="dialog"
      class="location-required-dialog"
      aria-labelledby="location-required-title"
      @cancel.prevent=""
    >
      <div class="location-required-icon" aria-hidden="true">◎</div>
      <h2 id="location-required-title">{{ $root.tr("تفعيل الموقع") }}</h2>
      <p>
        {{
          $root.tr(
            "مشاركة موقع جهازك مع الإدارة مطلوبة لاستخدام الحساب وإظهاره على الخريطة.",
          )
        }}
      </p>
      <p
        v-if="vm.locationStatus"
        class="location-required-status"
        role="status"
      >
        {{ $root.tr(vm.locationStatus) }}
      </p>
      <p v-if="vm.locationPermission === 'denied'">
        {{
          $root.tr(
            "اسمح للموقع من إعدادات المتصفح، وتأكد من تشغيل خدمة الموقع بالجهاز، ثم اضغط تفعيل الموقع.",
          )
        }}
      </p>
      <div class="actions">
        <button
          class="btn primary"
          :disabled="vm.locationSharing"
          @click="vm.startLocationSharing()"
        >
          {{
            $root.tr(vm.locationSharing ? "جارٍ تحديد الموقع…" : "تفعيل الموقع")
          }}</button
        ><button class="btn" @click="vm.logoutToLogin()">
          {{ $root.tr("تسجيل الخروج") }}
        </button>
      </div>
    </dialog></teleport
  >
</template>

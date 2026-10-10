<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["wallet-account-detail"]);
export default options;
</script>

<template>
  <div v-if="account">
    <dl class="account-fields">
      <div>
        <dt>{{ vm.tr("النوع") }}</dt>
        <dd>{{ vm.tr(vm.walletAccountType(account)) }}</dd>
      </div>
      <div>
        <dt>{{ vm.tr("تابع إلى") }}</dt>
        <dd>{{ $root.tr(parent) }}</dd>
      </div>
      <div>
        <dt>{{ vm.tr("المحافظة") }}</dt>
        <dd>{{ $root.tr(account.city || "—") }}</dd>
      </div>
      <div>
        <dt>{{ vm.tr("الحالة") }}</dt>
        <dd>{{ vm.tr(account.active ? "مفعّل" : "معطّل") }}</dd>
      </div>
      <div>
        <dt>{{ vm.tr("الرصيد الحالي") }}</dt>
        <dd>
          {{
            $root.tr(vm.money(vm.walletTableAmount(account.id, service, false)))
          }}
          {{ vm.tr("د.ع") }}
        </dd>
      </div>
      <div>
        <dt>{{ vm.tr("الرصيد المتاح") }}</dt>
        <dd>
          {{ $root.tr(vm.money(vm.walletTableAmount(account.id, service))) }}
          {{ vm.tr("د.ع") }}
        </dd>
      </div>
    </dl>
    <div class="formfoot">
      <button class="btn" @click="vm.closeModal()">{{ vm.tr("إغلاق") }}</button
      ><button class="btn primary" @click="movements">
        {{ vm.tr("عرض حركاته") }}
      </button>
    </div>
  </div>
  <p v-else="">{{ vm.tr("الحساب غير متاح ضمن نطاقك") }}</p>
</template>

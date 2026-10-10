<script>
import { componentOptions } from "../services/component-registry.js";
export default componentOptions(["pos-mobile-catalog"]);
</script>
<template>
  <section class="pos-phone-catalog" data-no-pagination="">
    <div
      v-if="vm.page==='dashboard'&amp;&amp;!company"
      class="pos-phone-summary"
    >
      <article>
        <span>{{ vm.tr("الرصيد المتاح") }}</span
        ><strong
          >{{ $root.tr(vm.money(balance)) }}
          <small>{{ vm.tr("د.ع") }}</small></strong
        >
      </article>
      <article>
        <span>{{ vm.tr("مبيعات اليوم") }}</span
        ><strong
          >{{ $root.tr(vm.money(todaySales)) }}
          <small>{{ vm.tr("د.ع") }}</small></strong
        >
      </article>
    </div>
    <template v-if="!selected"
      ><div class="pos-phone-title">
        <h2>{{ vm.tr("بيع") }}</h2>
      </div>
      <div
        class="pos-phone-companies"
        role="group"
        :aria-label="vm.tr('الشركات')"
      >
        <button :class="{ active: company === 'all' }" @click="company = 'all'">
          <strong>{{ vm.tr("كل الشركات") }}</strong></button
        ><button
          v-for="c in companies"
          :key="c.id"
          :class="{ active: company === c.id }"
          @click="openCompany(c)"
        >
          <strong>{{ $root.tr(c.name) }}</strong
          ><small>{{ $root.tr(c.count) }} {{ vm.tr("فئة") }}</small>
        </button>
      </div>
      <div class="pos-phone-type-tabs">
        <button
          v-for="t in [
            { id: 'all', name: 'الكل' },
            { id: 'card', name: 'بطاقات' },
            { id: 'topup', name: 'Topup' },
          ]"
          :key="t.id"
          :class="{ active: kind === t.id }"
          @click="kind = t.id"
        >
          {{ vm.tr(t.name) }}
        </button>
      </div>
      <input
        class="pos-phone-search"
        type="search"
        v-model="query"
        :placeholder="vm.tr('بحث عن فئة')"
        :aria-label="vm.tr('بحث عن فئة')"
      />
      <div class="pos-phone-products">
        <button v-for="o in shown" :key="o.key" @click="choose(o)">
          <span class="badge neutral">{{
            vm.tr(o.kind === "topup" ? "شحن مباشر" : "بطاقة")
          }}</span
          ><strong>{{ $root.tr(o.name) }}</strong
          ><b
            >{{ $root.tr(vm.money(o.price)) }}
            <small>{{ vm.tr("د.ع") }}</small></b
          ><span>{{ vm.tr(o.kind === "topup" ? "شحن" : "بيع وطباعة") }} ←</span>
        </button>
      </div>
      <p v-if="!shown.length" class="empty">
        {{ vm.tr("لا توجد فئات متاحة") }}
      </p></template
    >
    <digital-service-panel
      v-else-if="selected.connection"
      :key="selected.key"
      :selected-offer="{
        connection: selected.connection,
        offer: selected.offer,
      }"
      @back="selected = null"
    ></digital-service-panel>
    <section v-else="" class="card pos-phone-sale">
      <div class="pos-phone-title">
        <h2>{{ $root.tr(selected.name) }}</h2>
        <button
          class="btn small"
          @click="
            selected = null;
            confirmation = false;
          "
        >
          {{ vm.tr("رجوع") }}
        </button>
      </div>
      <form @submit.prevent="review">
        <div class="formgrid">
          <label
            >{{ vm.tr("عدد البطاقات")
            }}<input
              type="number"
              min="1"
              :max="e.printPolicy(e.sellerID()).maxCards"
              v-model.number="vm.saleForm.quantity"
              required="" /></label
          ><label
            >{{ vm.tr("سعر البيع · د.ع")
            }}<input
              type="number"
              min="0.01"
              step="0.01"
              v-model.number="vm.saleForm.retailPrice"
              required=""
          /></label>
        </div>
        <div class="pos-phone-sale-total">
          <span>{{ vm.tr("الإجمالي") }}</span
          ><strong
            >{{
              $root.tr(vm.money(vm.saleForm.quantity * vm.saleForm.retailPrice))
            }}
            {{ vm.tr("د.ع") }}</strong
          >
        </div>
        <button
          v-if="point&amp;&amp;!point.online"
          type="button"
          class="btn"
          @click="vm.startLocalPOSSession()"
        >
          {{ vm.tr("بدء جلسة الجهاز") }}</button
        ><button
          class="btn primary"
          :disabled="vm.formPending?.sell || !point?.online"
        >
          {{ vm.tr("مراجعة البيع") }}
        </button>
      </form>
      <div v-if="confirmation" class="pos-phone-confirm">
        <p>{{ vm.tr("تأكيد بيع وطباعة البطاقات") }}</p>
        <button
          class="btn primary"
          :disabled="vm.formPending?.sell"
          @click="sell"
        >
          {{ vm.tr("تأكيد البيع") }}
        </button>
      </div>
    </section>
  </section>
</template>

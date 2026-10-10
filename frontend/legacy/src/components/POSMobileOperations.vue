<script>
import { componentOptions } from "../services/component-registry.js";
export default componentOptions(["pos-mobile-operations"]);
</script>
<template>
  <section class="pos-phone-operations" data-no-pagination="">
    <digital-service-panel
      v-if="receipt"
      :initial-receipt="receipt"
      @back="receipt = null"
    ></digital-service-panel
    ><template v-else=""
      ><h2>{{ vm.tr("سجل العمليات") }}</h2>
      <div class="pos-phone-type-tabs">
        <button
          v-for="t in [
            { id: 'all', name: 'الكل' },
            { id: 'card', name: 'بطاقات' },
            { id: 'topup', name: 'Topup' },
          ]"
          :class="{ active: kind === t.id }"
          @click="kind = t.id"
        >
          {{ vm.tr(t.name) }}
        </button>
      </div>
      <input
        type="search"
        v-model="query"
        :placeholder="vm.tr('بحث عن عملية')"
        :aria-label="vm.tr('بحث عن عملية')"
      />
      <article v-for="r in shown" :key="r.id" class="card">
        <div>
          <strong>{{ $root.tr(r.name) }}</strong
          ><span class="badge neutral">{{ $root.tr(r.state) }}</span>
        </div>
        <small
          >{{r.digital?vm.tr(providerNamesForOperation(r.provider)):vm.nameOf('providers',vm.s.products.find(p=&gt;p.id===r.product)?.provider)}}
          · {{ $root.tr(vm.formatTime(r.time)) }}</small
        >
        <p v-if="r.message" class="caption">{{ $root.tr(r.message) }}</p>
        <p v-if="r.mobile" class="mono">{{ $root.tr(r.mobile) }}</p>
        <div>
          <b>{{ $root.tr(vm.money(r.value)) }} {{ vm.tr("د.ع") }}</b
          ><button
            v-if="r.digital&amp;&amp;['pending','review'].includes(r.status)&amp;&amp;vm.can('digital.create')"
            class="btn small"
            @click="verifyOrder(r)"
          >
            {{ vm.tr("تحقق من العملية") }}</button
          ><button v-else="" class="btn small" @click="open(r)">
            {{vm.tr(r.digital&amp;&amp;r.status==='succeeded'?'عرض الإيصال':'عرض التفاصيل')}}
          </button>
        </div>
      </article>
      <p v-if="!shown.length" class="empty">{{ vm.tr("لا توجد عمليات") }}</p>
      <button v-if="shown.length&lt;rows.length" class="btn" @click="page++">
        {{ vm.tr("عرض المزيد") }}
      </button></template
    >
  </section>
</template>

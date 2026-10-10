<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["meeting-receipt"]);
export default options;
</script>

<template>
  <div
    class="receipt"
    :style="{
      '--receipt-width': (layout.width || 80) + 'mm',
      color: layout.color,
    }"
  >
    <template v-for="block in layout.displayOrder" :key="block"
      ><div v-if="block === 'company'" class="receipt-block">
        <img
          v-if="layout.companyImage"
          :src="layout.companyImage"
          alt="صورة الشركة"
          class="receipt-logo receipt-company-image"
        /><b>{{ provider.name }}</b>
        <div>{{ agent.name }}</div>
      </div>
      <div
        v-else-if="block === 'header' && layout.header"
        class="receipt-block receipt-template-header"
      >
        {{ layout.header }}
      </div>
      <div
        v-else-if="block === 'agent' && (layout.agentImage || layout.agentText)"
        class="receipt-block receipt-agent-block"
      >
        <img
          v-if="layout.agentImage"
          :src="layout.agentImage"
          alt="صورة الوكيل"
          class="receipt-logo receipt-agent-image"
        />
        <div class="receipt-agent-text" :style="{ color: layout.agentColor }">
          {{ layout.agentText }}
        </div>
      </div>
      <div v-else-if="block === 'category'" class="receipt-block">
        <b>{{ product.name }}</b>
        <div v-if="tx">{{ vm.nameOf("pos", tx.pos) }}</div>
        <small v-if="tx?.reprints"
          >{{ label("reprint") }} · {{ tx.reprints }}</small
        >
      </div>
      <div v-else-if="block === 'image' && image" class="receipt-block">
        <img
          :src="image"
          alt="صورة الفئة"
          class="receipt-art receipt-category-image"
        />
      </div>
      <div v-else-if="block === 'codes'" class="receipt-block">
        <div v-for="c in cards" :key="c.id">
          <div class="pin">
            {{ !tx || vm.can("data.pin") ? c.pin : "••••••••" }}
          </div>
          <small
            >{{ label(c.serial ? "serial" : "internal") }}:
            {{ c.serial || c.internal }}</small
          ><small>{{ label("expiry") }}: {{ c.expiry }}</small
          ><small v-if="c.cvc"
            >CVC: {{ vm.can("data.pin") ? c.cvc : "•••" }}</small
          ><small v-if="c.reference"
            >{{ label("reference") }}:
            {{ vm.can("data.pin") ? c.reference : "•••" }}</small
          ><small v-for="f in product.extraFields || []"
            >{{ f.label }}:
            {{ vm.can("data.pin") ? c.extra?.[f.key] || "—" : "•••" }}</small
          >
        </div>
      </div>
      <div v-else-if="block === 'amount'" class="receipt-block">
        <b>{{ tx ? vm.money(tx.retailTotal ?? tx.total) : "—" }} د.ع</b
        ><small v-if="tx">{{ tx.id }} · {{ vm.formatTime(tx.time) }}</small>
      </div>
      <div v-else-if="block === 'footer'" class="receipt-block">
        <div class="receipt-template-footer">{{ layout.footer }}</div>
        <small>{{ agent.support }}</small>
      </div></template
    ><small v-if="cards.some((c) => String(c.pin).startsWith('DEMO'))"
      >بطاقات تجريبية غير صالحة للشحن</small
    >
  </div>
</template>

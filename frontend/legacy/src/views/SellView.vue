<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <print-policy-summary></print-policy-summary>
  <div
    v-if="selectedPOS &amp;&amp; !selectedPOS.online"
    class="local-session-prompt"
    role="status"
  >
    <div>
      <strong>{{ tr("جهاز نقطة البيع غير متصل") }}</strong>
    </div>
    <button
      v-if="can('sell.create')"
      class="btn primary"
      @click="startLocalPOSSession"
    >
      {{ tr("بدء جلسة الجهاز التجريبية") }}
    </button>
  </div>
  <div class="two">
    <div class="card">
      <div class="cardhead">
        <span class="badge neutral">{{ tr("مفرد وجملة") }}</span>
      </div>
      <div v-if="sellingAccount" class="rowline">
        <span>{{ $root.tr(sellingAccount.name) }}</span
        ><b>{{ tr(money(engine.serviceAvailable(sellingAccount.id))) }}</b>
      </div>
      <div class="products" style="margin-top: 20px">
        <button
          v-for="p in availableSaleProducts"
          class="product"
          :class="{ selected: saleForm.product === p.id }"
          @click="saleForm.product = p.id"
        >
          <span class="badge neutral">{{
            tr(nameOf("providers", p.provider))
          }}</span
          ><b>{{ tr(p.name) }}</b
          ><small
            >{{ tr(money(priceFor(selectedPOS?.agent, p.id)))
            }}{{ tr(" د.ع · ") }}{{ tr(p.kind) }}</small
          >
        </button>
      </div>
    </div>
    <div class="card">
      <div class="cardhead">
        <span class="badge">{{ tr("سحب من مخزن الرئيسي") }}</span>
      </div>
      <div class="rowline">
        <span>{{ tr("الفئة") }}</span
        ><b>{{ tr(nameOf("products", saleForm.product)) }}</b>
      </div>
      <div class="rowline">
        <span>{{ tr("سعر البطاقة") }}</span
        ><b
          >{{ tr(money(priceFor(selectedPOS?.agent, saleForm.product)))
          }}{{ tr(" د.ع") }}</b
        >
      </div>
      <label style="margin-top: 18px"
        >{{ $root.tr("سعر البيع للزبون • د.ع")
        }}<input
          type="text"
          inputmode="decimal"
          v-money=""
          min="0.01"
          step="0.01"
          v-model.number="saleForm.retailPrice"
          :placeholder="
            $root.tr(String(priceFor(selectedPOS?.agent, saleForm.product)))
          " /></label
      ><label style="margin-top: 18px"
        >{{ tr("عدد البطاقات")
        }}<input
          type="number"
          min="1"
          :max="engine.printPolicy(engine.sellerID()).maxCards"
          v-model.number="saleForm.quantity"
      /></label>
      <div class="rowline">
        <span>{{ tr("الإجمالي") }}</span
        ><strong class="metricvalue" style="font-size: 26px"
          >{{
            tr(
              money(
                priceFor(selectedPOS?.agent, saleForm.product) *
                  saleForm.quantity,
              ),
            )
          }}
          <small>{{ tr("د.ع") }}</small></strong
        >
      </div>
      <div class="help">
        {{ tr("الحد الأقصى للبطاقات في الطلب الواحد ")
        }}{{ tr(engine.printPolicy(engine.sellerID()).maxCards)
        }}{{ tr(" بطاقات · الحد اليومي ")
        }}{{ tr(money(selectedProduct?.dailyAmount)) }}{{ tr(" د.ع") }}
      </div>
      <button
        class="btn primary"
        style="width: 100%; margin-top: 22px"
        @click="sell"
        :disabled="formPending.sell"
      >
        {{ tr("إصدار البطاقات ومعاينة الوصل ←") }}
      </button>
      <div class="notice warn" style="margin: 18px 0 0">
        {{ tr("بطاقات تجريبية غير قابلة للشحن.") }}
      </div>
    </div>
  </div>
</template>

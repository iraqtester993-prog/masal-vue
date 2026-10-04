<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <section class="metrics dashboard-kpis" :aria-label="$root.tr('ملخص الحساب')">
    <article
      v-for="item in dashboardCards"
      :key="item.key"
      class="card dashboard-kpi"
      :data-kpi="item.key"
    >
      <div class="dashboard-kpi-head">
        <span>{{ tr(item.title) }}</span
        ><span class="dashboard-kpi-icon" aria-hidden="true"
          ><svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.7"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <path :d="navIconPath(item.icon)"></path></svg
        ></span>
      </div>
      <div class="dashboard-kpi-number">
        <strong>{{ item.value === null ? "—" : tr(money(item.value)) }}</strong
        ><small>{{ tr(item.unit) }}</small>
      </div>
      <div class="dashboard-kpi-footer">
        <span class="caption">{{ tr(item.note) }}</span>
        <div class="dashboard-kpi-action">
          <button class="btn small" @click="showDashboardDetails(item)">
            {{ tr("عرض التفاصيل") }}
          </button>
        </div>
      </div>
    </article>
  </section>
  <section class="reference-bottom" data-purpose="bottom-charts-and-region-row">
    <div v-if="can('sales.view')" class="card statistics-card">
      <div class="cardhead">
        <div></div>
        <span class="wallet-pill">{{ tr("أسبوعي (Weekly)") }}</span>
      </div>
      <div class="statistics-body">
        <div class="chart-stat">
          <div class="metricvalue">{{ tr(money(weekTotal)) }}</div>
          <span class="badge"
            >{{tr(visibleSales.filter(t=&gt;Date.now()-Date.parse(t.time) &lt; 7*86400000).length)}}
            {{ tr("عملية") }}</span
          >
        </div>
        <div class="chart-area">
          <div class="chart">
            <div v-for="b in chart" class="barcol" :class="{ today: b.today }">
              <small>{{ tr(money(b.value)) }}</small>
              <div
                class="bar"
                :style="{
                  height:
                    (b.value ? Math.max(8, (b.value / chartMax) * 170) : 3) +
                    'px',
                }"
                :title="tr(b.label + ': ' + money(b.value) + ' د.ع')"
              ></div>
            </div>
          </div>
          <div class="chartlabels">
            <span v-for="b in chart">{{ tr(b.label) }}</span>
          </div>
        </div>
      </div>
      <div class="card-bottom">
        <span
          ><i class="dot"></i
          >{{ tr("بيانات المبيعات المسجلة في النظام") }}</span
        ><button class="btn ghost small" @click="go('reports')">
          {{ tr("تحميل التقرير التفصيلي الكامل ←") }}
        </button>
      </div>
    </div>
    <div
      v-if="can('agents.view')&amp;&amp;can('inventory.view')&amp;&amp;can('pos.view')"
      class="card region-card"
    >
      <div class="cardhead"><div></div></div>
      <div class="region-list">
        <div
          v-for="(a,i) in visibleAgents.filter(a=&gt;a.type==='رئيسي')"
          class="region-row"
        >
          <div class="rowidentity">
            <div
              class="square"
              :style="{
                background: [
                  'linear-gradient(135deg,#2563eb,#4338ca)',
                  'linear-gradient(135deg,#0d9488,#0e7490)',
                  'linear-gradient(135deg,#9333ea,#6d28d9)',
                ][i % 3],
                color: '#fff',
              }"
            >
              {{ tr(a.name.slice(0, 1)) }}
            </div>
            <div>
              <b>{{ tr(a.name) }}</b
              ><small
                >{{tr(s.pos.filter(p=&gt;engine.descendants(a.id).includes(p.agent)).length)
                }}{{ tr(" نقاط بيع • ")
                }}{{tr(availableCards.filter(c=&gt;c.agent===a.id).length)
                }}{{ tr(" بطاقة متاحة") }}</small
              >
              <div class="progress">
                <i
                  :style="{background:a.color,width:Math.min(100,availableCards.filter(c=&gt;c.agent===a.id).length/Math.max(1,availableCards.length)*100)+'%'}"
                ></i>
              </div>
            </div>
          </div>
          <span class="province-pill">{{ tr(a.city) }}</span>
        </div>
      </div>
      <div class="card-bottom">
        <span><i class="dot"></i>{{ tr("نطاق شبكة التوزيع") }}</span
        ><button class="btn ghost small" @click="go('map')">
          {{ tr("عرض الخريطة التفاعلية ←") }}
        </button>
      </div>
    </div>
  </section>
</template>

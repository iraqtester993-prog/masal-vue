<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="report-filters">
    <div class="cardhead">
      <button class="btn ghost small" @click="resetReportDraft">
        {{ tr("إعادة ضبط") }}
      </button>
    </div>
    <div class="report-presets">
      <button
        v-for="p in [
          ['today', 'اليوم'],
          ['week', 'آخر 7 أيام'],
          ['month', 'هذا الشهر'],
          ['all', 'كل الفترات'],
        ]"
        class="btn small"
        @click="reportDraftPreset(p[0])"
      >
        {{ tr(p[1]) }}
      </button>
    </div>
    <div class="report-filter-grid">
      <label
        >{{ tr("من")
        }}<input type="date" v-model="modal.draft.reportFrom" /></label
      ><label
        >{{ tr("إلى")
        }}<input type="date" v-model="modal.draft.reportTo" /></label
      ><label
        >{{ tr("نوع التقرير")
        }}<select v-model="modal.draft.reportKind">
          <option value="all">{{ tr("التقرير الشامل") }}</option>
          <option v-for="t in reportTypes" :value="t.id">
            {{ tr(t.label) }}
          </option>
        </select></label
      ><label v-if="scopeFilterAgents.some(a=&gt;a.type==='رئيسي')"
        >{{ tr("الوكيل الرئيسي")
        }}<select
          v-model="modal.draft.reportMain"
          @change="
            modal.draft.reportBranch = '';
            modal.draft.reportAgent = modal.draft.reportMain;
            modal.draft.reportPOS = '';
          "
          :aria-label="$root.tr('الوكيل الرئيسي')"
        >
          <option value="">{{ tr("كل النطاق المسموح") }}</option>
          <option
            v-for="a in scopeFilterAgents.filter(a=&gt;a.type==='رئيسي')"
            :value="a.id"
          >
            {{ tr(a.name) }}
          </option>
        </select></label
      ><label v-if="scopeFilterAgents.some(a=&gt;a.type!=='رئيسي')"
        >{{ tr("الفرع / الفرع الفرعي")
        }}<select
          v-model="modal.draft.reportBranch"
          @change="
            modal.draft.reportAgent =
              modal.draft.reportBranch || modal.draft.reportMain;
            modal.draft.reportPOS = '';
          "
          :aria-label="$root.tr('الفرع / الفرع الفرعي')"
        >
          <option value="">{{ tr("جميع الفروع ضمن النطاق") }}</option>
          <option
            v-for="a in scopeFilterAgents.filter(a=&gt;a.type!=='رئيسي'&amp;&amp;(!modal.draft.reportMain||engine.descendants(modal.draft.reportMain).includes(a.id)))"
            :value="a.id"
          >
            {{ tr(a.name) }} ·
            {{tr(s.agents.some(p=&gt;p.id===a.parent&amp;&amp;p.type!=='رئيسي')?'فرع فرعي':'فرع')}}
          </option>
        </select></label
      ><label
        >{{ tr("نقطة البيع")
        }}<select v-model="modal.draft.reportPOS">
          <option value="">
            {{ tr(actor.role === "pos" ? "ضمن نقطتي" : "الكل ضمن نطاقي") }}
          </option>
          <option
            v-for="p in visiblePOS.filter(p=&gt;!modal.draft.reportAgent||engine.descendants(modal.draft.reportAgent).includes(p.agent))"
            :value="p.id"
          >
            {{ tr(p.name) }}
          </option>
        </select></label
      ><label
        >{{ tr("المنتج")
        }}<select v-model="modal.draft.reportProduct">
          <option value="">
            {{ tr(actor.role === "pos" ? "ضمن نقطتي" : "الكل ضمن نطاقي") }}
          </option>
          <option v-for="p in scopeFilterProducts" :value="p.id">
            {{ tr(p.name) }}
          </option>
        </select></label
      ><label
        >{{ tr("المزود")
        }}<select v-model="modal.draft.reportProvider">
          <option value="">
            {{ tr(actor.role === "pos" ? "ضمن نقطتي" : "الكل ضمن نطاقي") }}
          </option>
          <option v-for="p in scopeFilterProviders" :value="p.id">
            {{ tr(p.name) }}
          </option>
        </select></label
      ><label
        >{{ tr("المحافظة")
        }}<select v-model="modal.draft.reportCity">
          <option value="">
            {{ tr(actor.role === "pos" ? "ضمن نقطتي" : "الكل ضمن نطاقي") }}
          </option>
          <option v-for="c in scopeFilterCities" :value="c">{{ tr(c) }}</option>
        </select></label
      ><label
        >{{ tr("حالة البيع")
        }}<select v-model="modal.draft.reportStatus">
          <option value="">
            {{ tr(actor.role === "pos" ? "ضمن نقطتي" : "الكل ضمن نطاقي") }}
          </option>
          <option
            v-for="x in [
              'Print Requested',
              'Print Failed',
              'Printed',
              'Reprinted',
              'Reprint Requested',
            ]"
            :value="x"
          >
            {{ tr(status(x)) }}
          </option>
        </select></label
      >
    </div>
    <p class="help">
      {{
        tr(
          "التاريخ يرشح الحركات. الحالة تخص المبيعات فقط. المنتج والمزود يرشحان المبيعات والمخزون والأسعار. المخزون والشبكة والإعدادات لقطات حالية.",
        )
      }}
    </p>
  </div>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ tr("إلغاء") }}</button
    ><button class="btn primary" @click="applyReportSettings">
      {{ tr("حفظ وتطبيق") }}
    </button>
  </div>
</template>

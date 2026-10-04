<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card">
    <form v-permit="can('integrations.edit')" @submit.prevent="saveIntegration">
      <div class="formgrid">
        <label
          >{{ tr("المزود")
          }}<select v-model="integrationForm.provider">
            <option v-for="p in s.providers" :value="p.id">
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الوكيل")
          }}<select v-model="integrationForm.agent">
            <option
              v-for="a in visibleAgents.filter(a=&gt;a.type==='رئيسي')"
              :value="a.id"
            >
              {{ tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("بيئة الربط")
          }}<select v-model="integrationForm.environment">
            <option value="Test">Test</option>
            <option value="Production">Production</option>
          </select></label
        ><label
          >{{ tr("تاريخ انتهاء الاعتماد")
          }}<input type="date" v-model="integrationForm.expiry"
        /></label>
      </div>
      <div class="formfoot">
        <button class="btn primary">{{ tr("حفظ إعداد الربط") }}</button>
      </div>
    </form>
  </div>
  <div class="card" style="margin-top: 20px">
    <div
      v-for="r in s.integrations.filter(r=&gt;engine.allowed(r.agent))"
      class="rowline"
    >
      <div>
        <b
          >{{ tr(nameOf("providers", r.provider)) }} ·
          {{ tr(nameOf("agents", r.agent)) }}</b
        >
        <div class="caption">
          {{ tr(r.environment) }} · {{ tr(r.expiry)
          }}{{ tr(" · لا توجد بيانات اعتماد محفوظة") }}
        </div>
      </div>
      <span class="badge warn">{{ tr("بانتظار الخادم") }}</span>
    </div>
    <div v-if="!s.integrations.length" class="empty">
      {{ tr("لا توجد تكاملات مهيأة") }}
    </div>
  </div>
</template>

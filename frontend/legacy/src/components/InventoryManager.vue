<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["inventory-manager"]);
export default options;
</script>

<template>
  <section v-if="b" class="inventory-manager" data-no-pagination="">
    <div class="inventory-summary">
      <b>{{ $root.tr(vm.nameOf("products", b.product)) }}</b
      ><span>{{ $root.tr(vm.nameOf("agents", b.agent)) }}</span
      ><span
        >{{ $root.tr(cards.length) }}{{ $root.tr(" بطاقة · ")
        }}{{ $root.tr(remaining.length) }}{{ $root.tr(" متبقية") }}</span
      >
    </div>
    <div class="inventory-controls">
      <input
        v-model="search"
        :placeholder="$root.tr('بحث بالسيريال')"
        :aria-label="$root.tr('بحث البطاقات')"
      /><select v-model="state" :aria-label="$root.tr('حالة البطاقة')">
        <option value="">{{ $root.tr("جميع الحالات") }}</option>
        <option v-for="s in [...new Set(cards.map(c=&gt;c.status))]" :value="s">
          {{ $root.tr(vm.status(s)) }}
        </option></select
      ><button class="btn small" @click="selectVisible">
        {{ $root.tr("تحديد نتائج البحث") }}</button
      ><button class="btn small" @click="selectRemaining">
        {{ $root.tr("تحديد المتبقي") }}</button
      ><span>{{ $root.tr(chosen.length) }}{{ $root.tr(" محددة") }}</span>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("تحديد") }}</th>
            <th>Serial</th>
            <th>{{ $root.tr("الانتهاء") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="c in rows" :key="c.id">
            <td>
              <input
                type="checkbox"
                v-model="chosen"
                :value="c.id"
                :aria-label="$root.tr('تحديد ' + c.serial)"
              />
            </td>
            <td>{{ c.serial || c.id }}</td>
            <td>{{ $root.tr(c.expiry) }}</td>
            <td>
              <span
                class="badge settlement-status"
                :class="vm.claimStatusTone(c.status)"
                >{{ $root.tr(c.damageClaim&amp;&amp;c.status==='Quarantined'?'تالف — قيد المعالجة':vm.status(c.status)) }}</span
              >
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="4">{{ $root.tr("لا توجد نتائج") }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <div class="actions">
      <button class="btn small" :disabled="page === 1" @click="page--">
        {{ $root.tr("السابق") }}</button
      ><span
        >{{ $root.tr(page) }} /
        {{ $root.tr(Math.max(1, Math.ceil(filtered.length / 20))) }}</span
      ><button
        class="btn small"
        :disabled="page*20&gt;=filtered.length"
        @click="page++"
      >
        {{ $root.tr("التالي") }}
      </button>
    </div>
    <div class="inventory-controls">
      <details>
        <summary>{{ $root.tr("إجراءات أخرى") }}</summary>
        <label
          >{{ $root.tr("الإجراء")
          }}<select v-model="action" :disabled="busy">
            <option value="">{{ $root.tr("اختر الإجراء") }}</option>
            <option v-if="vm.can('inventory.edit')" value="edit">
              {{ $root.tr("تعديل بيانات الطلبية") }}
            </option>
            <option v-if="vm.can('claims.create')" value="damage">
              {{ $root.tr("تعليم المحدد كتالف") }}
            </option>
            <option v-if="vm.can('exports.encrypt')" value="copy">
              {{ $root.tr("تصدير كشف دون رموز") }}
            </option>
            <option
              v-if="vm.can('inventory.cancel')&amp;&amp;remaining.length"
              value="cancel"
            >
              {{ $root.tr("إلغاء المتبقي من الطلبية") }}
            </option>
            <option
              v-if="vm.canBatchAction(b, 'quarantine')"
              value="quarantine"
            >
              {{ $root.tr("إيقاف البيع") }}
            </option>
            <option v-if="vm.canBatchAction(b, 'resume')" value="resume">
              {{ $root.tr("إعادة تفعيل") }}
            </option>
            <option v-if="canRestore" value="restore">
              {{ $root.tr("استرجاع الملغي") }}
            </option>
          </select></label
        >
      </details>
      <button
        v-if="vm.can('exports.view')&amp;&amp;vm.can('exports.request')"
        class="btn"
        @click="withdraw"
      >
        {{ $root.tr("إرجاع للمزود") }}
      </button>
    </div>
    <template v-if="action&amp;&amp;!preview"
      ><div v-if="action === 'edit'" class="formgrid">
        <label
          >{{ $root.tr("المحافظة") }}<input v-model="metadata.city" /></label
        ><label
          >{{ $root.tr("المصدر") }}<input v-model="metadata.supplier" /></label
        ><label
          >{{ $root.tr("ملاحظات")
          }}<textarea v-model="metadata.notes"></textarea>
        </label>
      </div>
      <label v-if="action==='restore'&amp;&amp;restores.length"
        >{{ $root.tr("عملية الإلغاء")
        }}<select v-model="restoreId">
          <option v-for="r in restores" :value="r.id">
            {{ $root.tr(r.id) }} · {{ $root.tr(r.quantity)
            }}{{ $root.tr(" بطاقة") }}
          </option>
        </select></label
      ><label
        >{{ $root.tr("سبب الإجراء") }}<textarea v-model="reason"></textarea>
      </label>
      <div class="formfoot">
        <button class="btn primary" @click="review">
          {{ $root.tr("معاينة الإجراء") }}
        </button>
      </div></template
    >
    <div v-if="preview" class="notice">
      <b>{{
        $root.tr(action === "return" ? "إرجاع للمزود" : "تأكيد الإجراء")
      }}</b>
      <p v-if="action === 'return'">
        {{ $root.tr(vm.nameOf("products", b.product)) }} ·
        {{ $root.tr(reason) }}
      </p>
      <p>{{ $root.tr(preview.quantity) }}{{ $root.tr(" بطاقة") }}</p>
      <p v-if="['damage', 'cancel', 'return'].includes(action)">
        {{ $root.tr("الرصيد التشغيلي المسحوب: ")
        }}{{ $root.tr(vm.can("data.cost") ? vm.money(preview.debit) : "••••")
        }}{{ $root.tr(" د.ع. لا يوجد إرجاع نقدي تلقائي.") }}
      </p>
      <p v-if="action === 'cancel'">
        {{ $root.tr("البطاقات المباعة والمستخدمة وسجلاتها تبقى دون تغيير.") }}
      </p>
      <p v-if="action === 'copy'">
        {{ $root.tr("نسخة فقط؛ لا يتغير رصيد المخزون أو حالة البطاقات.") }}
      </p>
      <div class="actions">
        <button class="btn" :disabled="busy" @click="preview = null">
          {{ $root.tr("رجوع") }}</button
        ><button class="btn primary" :disabled="busy" @click="confirm">
          {{
            $root.tr(
              busy
                ? "جارٍ التنفيذ…"
                : action === "return"
                  ? vm.actor.role === "owner"
                    ? "تأكيد الإرجاع وتنزيل الملف"
                    : "إرسال للاعتماد"
                  : "تأكيد الإجراء",
            )
          }}
        </button>
      </div>
    </div>
    <p v-if="error" role="alert" class="text-danger">{{ $root.tr(error) }}</p>
  </section>
</template>

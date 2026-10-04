<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["cash-order-form", "legacy-form"]);
export default options;
</script>

<template>
  <section class="card">
    <template v-if="done"
      ><div class="notice">
        {{
          $root.tr(
            done.submitted ? "أرسلت الطلبية للاعتماد" : "تم اعتماد الطلبية",
          )
        }}
        · {{ $root.tr(done.quantity) }}{{ $root.tr(" بطاقة") }}
      </div>
      <div v-if="done.rejectedCards?.length" class="notice warn">
        {{ $root.tr("تم استبعاد ") }}{{ $root.tr(done.rejectedCards.length)
        }}{{ $root.tr(" بطاقة مرفوضة من المخزون.") }}<br /><button
          class="btn small"
          @click="downloadRejected(done)"
        >
          {{ $root.tr("تنزيل ملف البطاقات المرفوضة") }}
        </button>
      </div>
      <div class="formfoot">
        <button class="btn" @click="restart">
          {{ $root.tr("طلبية جديدة") }}</button
        ><button
          v-if="!done.submitted"
          class="btn primary"
          @click="
            vm.inventoryAgent = done.agent;
            vm.go('inventory');
          "
        >
          {{ $root.tr("عرض المخزون") }}
        </button>
      </div></template
    ><template v-else=""
      ><div class="steps">
        <div
          v-for="(label, i) in [
            'بيانات الطلبية',
            'رفع الملف',
            'المعاينة والاعتماد',
          ]"
          class="step"
          :class="{ active: step === i }"
        >
          {{ $root.tr(i + 1) }}. {{ $root.tr(label) }}
        </div>
      </div>
      <div v-if="step === 0">
        <div class="formgrid">
          <label
            >{{ $root.tr("الوكيل الرئيسي")
            }}<input
              v-if="!isAdmin"
              :value="vm.nameOf('agents', draft.agent)"
              readonly=""
            /><select v-else="" v-model="draft.agent">
              <option value="" disabled="">
                {{ $root.tr("اختر الوكيل") }}
              </option>
              <option v-for="a in agents" :value="a.id">
                {{ $root.tr(a.name) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("الفئة")
            }}<select v-model="draft.product">
              <option value="" disabled="">{{ $root.tr("اختر الفئة") }}</option>
              <option v-for="p in products" :value="p.id">
                {{ $root.tr(p.name) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("المصدر")
            }}<select
              v-model="draft.sourceId"
              :disabled="!draft.product"
              required=""
            >
              <option value="" disabled="">
                {{
                  $root.tr(
                    !draft.product
                      ? "اختر الفئة أولًا"
                      : orderSources.length
                        ? "اختر المصدر"
                        : "لا توجد مصادر مفعلة لهذه الشركة",
                  )
                }}
              </option>
              <option v-for="r in orderSources" :key="r.id" :value="r.id">
                {{ $root.tr(r.name) }} —
                {{ $root.tr(vm.nameOf("providers", r.provider)) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("تكلفة البطاقة • د.ع")
            }}<input
              type="text"
              inputmode="decimal"
              v-money=""
              min="0.01"
              v-model.number="draft.cost" /></label
          ><label
            >{{ $root.tr("سعر البيع للوكيل • د.ع")
            }}<input
              :value="price || ''"
              readonly=""
              :placeholder="$root.tr('حدد السعر من قسم الأسعار')" /></label
          ><label
            >{{ $root.tr("مصاريف الدفعة • د.ع (اختياري)")
            }}<input
              type="text"
              inputmode="decimal"
              v-money=""
              min="0"
              v-model.number="draft.expenses" /></label
          ><label
            >{{ $root.tr("تاريخ الانتهاء عند عدم وجوده بالملف (اختياري)")
            }}<input type="date" v-model="draft.expiry"
          /></label>
        </div>
      </div>
      <div v-if="step === 1">
        <label
          >{{ $root.tr("ملف البطاقات")
          }}<input
            type="file"
            accept=".txt,.csv,.xlsx"
            @change="read"
            :disabled="busy"
        /></label>
        <div v-if="raw.length" class="rowline">
          <span>{{ $root.tr(fileName) }}</span
          ><b>{{ $root.tr(raw.length) }}{{ $root.tr(" بطاقة") }}</b>
        </div>
        <details v-if="raw.length" :open="needsMapping">
          <summary>{{ $root.tr("أعمدة الملف") }}</summary>
          <div class="formgrid">
            <label v-for="f in fields"
              >{{ $root.tr(f.label)
              }}<select v-model.number="mapping[f.key]">
                <option :value="-1">
                  {{
                    $root.tr(
                      f.key === "serial"
                        ? "توليد تلقائي"
                        : "غير موجود / الافتراضي",
                    )
                  }}
                </option>
                <option v-for="(h, i) in headers" :value="i">
                  {{ $root.tr(h) }}
                </option>
              </select></label
            >
          </div>
        </details>
      </div>
      <div v-if="step === 2">
        <div class="cash-order-summary">
          <div class="cash-order-detail">
            <span>{{ $root.tr("المصدر والشركة") }}</span
            ><b
              >{{ $root.tr(snapshot.supplier) }} —
              {{ $root.tr(snapshot.sourceCompany) }}</b
            >
          </div>
          <div class="cash-order-detail">
            <span>{{ $root.tr("الوكيل الرئيسي") }}</span
            ><b>{{ $root.tr(vm.nameOf("agents", snapshot.agent)) }}</b>
          </div>
          <div class="cash-order-detail">
            <span>{{ $root.tr("الفئة") }}</span
            ><b>{{ $root.tr(vm.nameOf("products", snapshot.product)) }}</b>
          </div>
          <div class="cash-order-detail">
            <span>{{ $root.tr("بطاقات صالحة") }}</span
            ><b>{{ $root.tr(checked.filter(r=&gt;!r.error).length) }}</b>
          </div>
          <div class="cash-order-detail">
            <span>{{ $root.tr("بطاقات مرفوضة") }}</span
            ><b>{{ $root.tr(checked.filter(r=&gt;r.error).length) }}</b>
          </div>
          <div class="cash-order-detail">
            <span>{{ $root.tr("سعر البطاقة للوكيل") }}</span
            ><b>{{ $root.tr(vm.money(snapshot.loadPrice)) }}</b>
          </div>
          <div class="cash-order-detail">
            <span>{{
              $root.tr(
                replacement ? "قيمة البديل التشغيلية" : "قيمة الطلبية المستحقة",
              )
            }}</span
            ><b>{{ $root.tr(vm.money(total)) }}</b>
          </div>
        </div>
        <div v-if="checked.some(r=&gt;r.error)" class="notice warn">
          {{
            $root.tr(
              "البطاقات المرفوضة لا تدخل المخزون. راجع أسباب الرفض في الجدول أدناه.",
            )
          }}
        </div>
        <div
          v-if="checked.some(r=&gt;r.error)"
          class="tablewrap rejected-import-table"
          style="max-height: 260px; overflow: auto"
        >
          <table>
            <thead>
              <tr>
                <th>{{ $root.tr("السطر") }}</th>
                <th>{{ $root.tr("السيريال") }}</th>
                <th>{{ $root.tr("الانتهاء") }}</th>
                <th>{{ $root.tr("سبب الرفض") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in checked.filter(r=&gt;r.error)">
                <td>{{ $root.tr(r.row) }}</td>
                <td>{{ r.serial || "—" }}</td>
                <td>{{ $root.tr(r.expiry || "—") }}</td>
                <td class="text-danger">{{ $root.tr(r.error) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="tablewrap" style="max-height: 360px; overflow: auto">
          <table>
            <thead>
              <tr>
                <th>{{ $root.tr("السطر") }}</th>
                <th>{{ $root.tr("السيريال") }}</th>
                <th>{{ $root.tr("الانتهاء") }}</th>
                <th>{{ $root.tr("نتيجة الفحص") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in checked">
                <td>{{ $root.tr(r.row) }}</td>
                <td>{{ r.serial || "—" }}</td>
                <td>{{ $root.tr(r.expiry) }}</td>
                <td>{{ $root.tr(r.error || "صالح") }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div v-if="error" class="notice warn" role="alert">
        {{ $root.tr(error) }}
      </div>
      <div class="formfoot">
        <button
          class="btn"
          :disabled="busy || step === 0"
          @click="
            step--;
            confirmed = false;
            error = '';
          "
        >
          {{ $root.tr("السابق") }}</button
        ><button
          v-if="step&lt;2"
          class="btn primary"
          :disabled="busy || !vm.can('import.preview')"
          @click="next"
        >
          {{ $root.tr("التالي") }}</button
        ><button
          v-else=""
          class="btn primary"
          :disabled="busy||!checked.some(r=&gt;!r.error)||(!isAdmin&amp;&amp;!vm.can('import.preview'))"
          @click="approve"
        >
          {{
            $root.tr(
              replacement
                ? "اعتماد البديل دون تحصيل"
                : isAdmin
                  ? "اعتماد الطلبية"
                  : "إرسال للإدارة",
            )
          }}
        </button>
      </div></template
    >
  </section>
</template>

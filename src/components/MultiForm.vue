<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["cash-order-form", "multi-form"]);
export default options;
</script>

<template>
  <section class="card multi-orders">
    <template v-if="done"
      ><h3>
        {{
          $root.tr(
            done.status === "معتمدة"
              ? "تم اعتماد الطلبية"
              : "أرسلت الطلبية للإدارة",
          )
        }}
      </h3>
      <div class="order-metrics">
        <span>{{ $root.tr(done.id) }}</span
        ><span>{{ $root.tr(done.lines.length) }}{{ $root.tr(" ملف") }}</span
        ><span>{{ $root.tr(done.quantity) }}{{ $root.tr(" بطاقة صالحة") }}</span
        ><span>{{ $root.tr(done.rejected) }}{{ $root.tr(" مستبعدة") }}</span>
      </div>
      <div class="formfoot">
        <rejected-export :lines="done.lines"></rejected-export
        ><button class="btn primary" @click="restart">
          {{ $root.tr("طلبية جديدة") }}</button
        ><button class="btn" @click="vm.importTab = 'records'">
          {{ $root.tr("سجل الطلبيات") }}
        </button>
      </div></template
    ><template v-else=""
      ><div class="steps">
        <div
          v-for="(title, i) in [
            'بيانات الطلبية',
            'ملفات الفئات',
            'المعاينة والإرسال',
          ]"
          class="step"
          :class="{ active: step === i }"
        >
          {{ $root.tr(i + 1) }}. {{ $root.tr(title) }}
        </div>
      </div>
      <div v-show="step === 0" class="formgrid">
        <label
          >{{ $root.tr("عدد الفئات")
          }}<input
            type="number"
            min="1"
            step="1"
            required=""
            v-model.number="draft.categoryCount"
            @input="changed"
            :aria-label="$root.tr('عدد الفئات')" /></label
        ><label
          >{{ $root.tr("الوكيل الرئيسي")
          }}<input
            v-if="!isAdmin"
            :value="vm.nameOf('agents', draft.agent)"
            readonly=""
          /><select
            v-else=""
            v-model="draft.agent"
            :disabled="!!editId"
            @change="changed"
            :aria-label="$root.tr('وكيل الطلبية')"
          >
            <option value="">{{ $root.tr("اختر الوكيل") }}</option>
            <option v-for="a in agents" :value="a.id">
              {{ $root.tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الشركة")
          }}<select
            v-model="draft.provider"
            @change="providerChanged"
            :aria-label="$root.tr('شركة الطلبية')"
          >
            <option value="">{{ $root.tr("اختر الشركة") }}</option>
            <option
              v-for="p in vm.s.providers.filter(p=&gt;p.active)"
              :value="p.id"
            >
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("الفئة (اختياري)")
          }}<select
            v-model="draft.product"
            @change="categoryChanged"
            :aria-label="$root.tr('فئة الطلبية (اختياري)')"
          >
            <option value="">{{ $root.tr("تحديد تلقائي من الملف") }}</option>
            <option v-for="p in products" :key="p.id" :value="p.id">
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("المصدر")
          }}<select
            v-model="draft.sourceId"
            @change="changed"
            :aria-label="$root.tr('مصدر الطلبية')"
          >
            <option value="">{{ $root.tr("اختر المصدر") }}</option>
            <option v-for="s in sources" :value="s.id">
              {{ $root.tr(s.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("المحافظة")
          }}<select
            v-model="draft.city"
            @change="changed"
            :aria-label="$root.tr('محافظة الطلبية')"
          >
            <option value="">{{ $root.tr("اختر المحافظة") }}</option>
            <option v-for="c in vm.activeGovernorates" :value="c">
              {{ $root.tr(c) }}
            </option>
          </select></label
        >
      </div>
      <div v-if="step === 1">
        <div class="order-upload actions">
          <button
            class="btn primary"
            type="button"
            :disabled="busy"
            @click="$refs.multipleFiles.click()"
          >
            {{ $root.tr("رفع ملفات الفئات") }}</button
          ><input
            ref="multipleFiles"
            hidden=""
            type="file"
            multiple=""
            accept=".txt,.csv,.xlsx"
            :disabled="busy"
            @change="read($event)"
          /><span
            >{{ $root.tr(draft.categoryCount) }}{{ $root.tr(" فئة · ")
            }}{{ $root.tr(draft.lines.length)
            }}{{ $root.tr(" ملف / ورقة") }}</span
          ><span v-if="busy">{{ $root.tr("جارٍ قراءة الملفات…") }}</span>
        </div>
        <ul class="order-upload-list">
          <li v-for="l in draft.lines" :key="l.id">
            <span>{{ $root.tr(l.name) }}</span
            ><button
              class="btn small"
              :disabled="busy"
              @click="draft.lines=draft.lines.filter(x=&gt;x.id!==l.id);changed()"
            >
              {{ $root.tr("إزالة") }}
            </button>
          </li>
        </ul>
        <details>
          <summary>{{ $root.tr("إدخال نص بدل ملف") }}</summary>
          <textarea
            v-model="text"
            rows="4"
            dir="auto"
            aria-label="بيانات البطاقات"
          ></textarea
          ><button
            class="btn small"
            :disabled="busy || !text.trim()"
            @click="paste"
          >
            {{ $root.tr("إضافة البيانات") }}
          </button>
        </details>
      </div>
      <div v-if="step === 2">
        <template v-if="!preview"
          ><div class="tablewrap" data-no-pagination="">
            <table class="order-preview-edit">
              <thead>
                <tr>
                  <th>{{ $root.tr("الملف") }}</th>
                  <th>{{ $root.tr("الفئة حسب المرجع") }}</th>
                  <th>{{ $root.tr("فئة المخزون") }}</th>
                  <th>{{ $root.tr("البطاقات") }}</th>
                  <th>{{ $root.tr("تكلفة البطاقة · د.ع") }}</th>
                  <th>{{ $root.tr("الإجراءات") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="l in draft.lines" :key="l.id">
                  <td>{{ $root.tr(l.name) }}</td>
                  <td>
                    {{
                      $root.tr(
                        l.referenceLabel || l.categoryCode || "غير محددة",
                      )
                    }}
                  </td>
                  <td>
                    <select
                      v-if="needsCategory(l)"
                      v-model="l.product"
                      :aria-label="$root.tr('فئة الملف ' + l.name)"
                      @change="changed"
                    >
                      <option value="">
                        {{ $root.tr("اختر فئة هذا الملف") }}
                      </option>
                      <option v-for="p in products" :key="p.id" :value="p.id">
                        {{ $root.tr(p.name) }}
                      </option></select
                    ><span v-else-if="l.product">{{
                      $root.tr(vm.nameOf("products", l.product))
                    }}</span
                    ><span v-else="" class="text-danger">{{
                      $root.tr("رمز مربوط بأكثر من فئة؛ صحح الربط")
                    }}</span
                    ><small v-if="needsCategory(l)">{{
                      $root.tr("تحديد يدوي لهذا الملف فقط")
                    }}</small>
                    <details v-if="l.rawRows">
                      <summary>{{ $root.tr("تحديد أعمدة الملف") }}</summary>
                      <label
                        v-for="field in [
                          { id: 'serial', name: 'Serial' },
                          { id: 'pin', name: 'PIN' },
                          { id: 'expiry', name: 'Expiry' },
                        ]"
                        :key="field.id"
                        >{{ $root.tr(field.name)
                        }}<select
                          v-model="l.columnMap[field.id]"
                          @change="mapColumns(l)"
                          :aria-label="$root.tr(field.name + ' ' + l.name)"
                        >
                          <option value="">{{ $root.tr("غير موجود") }}</option>
                          <option
                            v-for="(cell, i) in l.rawRows[0].cells"
                            :value="String(i)"
                          >
                            {{ $root.tr("عمود ") }}{{ $root.tr(i + 1) }} ·
                            {{ $root.tr(cell) }}
                          </option>
                        </select></label
                      >
                    </details>
                  </td>
                  <td>{{ $root.tr(l.rows.length) }}</td>
                  <td>
                    <input
                      type="number"
                      min="0.01"
                      step="any"
                      v-model.number="l.cost"
                      :aria-label="$root.tr('تكلفة البطاقة')"
                    />
                  </td>
                  <td>
                    <button
                      class="btn small"
                      @click="draft.lines=draft.lines.filter(x=&gt;x.id!==l.id);changed()"
                    >
                      {{ $root.tr("إزالة") }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div></template
        ><template v-else=""
          ><div class="order-metrics">
            <span
              >{{ $root.tr(vm.nameOf("agents", draft.agent)) }} ·
              {{ $root.tr(draft.city) }}</span
            ><span
              >{{ $root.tr(preview.quantity) }}{{ $root.tr(" صالحة") }}</span
            ><span
              >{{ $root.tr(preview.rejected) }}{{ $root.tr(" مرفوضة") }}</span
            ><b
              >{{ $root.tr(vm.money(preview.amount)) }}{{ $root.tr(" د.ع") }}</b
            ><button class="btn small" @click="changed">
              {{ $root.tr("تعديل بيانات المعاينة") }}
            </button>
          </div>
          <order-lines :lines="preview.lines"></order-lines>
          <div v-if="preview.lines.some(l=&gt;!l.accepted)" class="notice warn">
            {{
              $root.tr(
                "يوجد ملف بلا بطاقات صالحة؛ راجع أسباب الرفض ثم صححه أو أزله.",
              )
            }}
          </div>
          <label v-if="preview.rejected" class="order-exclude"
            ><input type="checkbox" v-model="exclude" />{{ $root.tr("استبعاد ")
            }}{{ $root.tr(preview.rejected)
            }}{{ $root.tr(" بطاقة مرفوضة واعتماد ")
            }}{{ $root.tr(preview.quantity)
            }}{{ $root.tr(" بطاقة صالحة فقط") }}</label
          ></template
        >
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
            changed();
            error = '';
          "
        >
          {{ $root.tr("السابق") }}</button
        ><button
          v-if="step&lt;2"
          class="btn primary"
          :disabled="busy"
          @click="next"
        >
          {{ $root.tr(step === 1 ? "معاينة الملفات" : "التالي") }}</button
        ><button
          v-else-if="!preview"
          class="btn primary"
          :disabled="busy || !draft.lines.length"
          @click="validate"
        >
          {{ $root.tr("فحص الملفات") }}</button
        ><template v-else=""
          ><button
            v-if="!isAdmin || !vm.can('import.approve')"
            class="btn primary"
            :disabled="busy||preview.lines.some(l=&gt;!l.accepted)||(preview.rejected&amp;&amp;!exclude)"
            @click="send(false)"
          >
            {{ $root.tr("إرسال للإدارة") }}</button
          ><button
            v-if="isAdmin&amp;&amp;vm.can('import.approve')"
            class="btn primary"
            :disabled="busy||preview.lines.some(l=&gt;!l.accepted)||(preview.rejected&amp;&amp;!exclude)"
            @click="send(true)"
          >
            {{ $root.tr("اعتماد الطلبية") }}
          </button></template
        >
      </div></template
    >
  </section>
</template>

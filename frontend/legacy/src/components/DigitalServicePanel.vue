<script>
import { componentOptions } from "../services/component-registry.js";
export default componentOptions(["digital-service-panel"]);
</script>
<template>
  <section class="digital-services" data-no-pagination="">
    <div v-if="!focusedSale" class="card digital-tabs">
      <div class="tabs">
        <button
          v-if="canSell"
          :class="{ active: tab === 'sell' }"
          @click="
            tab = 'sell';
            receipt = null;
          "
        >
          {{ tr("بيع خدمة") }}</button
        ><button :class="{ active: tab === 'log' }" @click="tab = 'log'">
          {{ tr("سجل العمليات") }}</button
        ><button
          v-if="canSetup || canAssign"
          :class="{ active: tab === 'settings' }"
          @click="tab = 'settings'"
        >
          {{ tr(canSetup ? "إعداد الربط" : "توزيع الفئات") }}
        </button>
      </div>
    </div>
    <p v-if="error" class="notice warn" role="alert">{{ tr(error) }}</p>
    <section v-if="tab === 'settings'" class="card">
      <div class="toolbar">
        <h3>{{ tr(canSetup ? "ربط الشركات والوكلاء" : "توزيع الفئات") }}</h3>
        <button v-if="canSetup" class="btn primary" @click="edit()">
          {{ tr("إضافة ربط") }}
        </button>
      </div>
      <form
        v-if="draft&amp;&amp;canSetup"
        @submit.prevent="save"
        class="digital-config"
      >
        <h4 v-if="draft.id" class="digital-edit-title">
          {{ tr("تعديل الربط الحالي") }}
        </h4>
        <div class="digital-connection-fields">
          <div class="formgrid">
            <label
              >{{ tr("الخدمة")
              }}<select
                :aria-label="tr('الخدمة')"
                v-model="draft.provider"
                @change="changeProvider"
              >
                <option v-for="(name, id) in providerNames" :value="id">
                  {{ tr(name) }}
                </option>
              </select></label
            ><label
              >{{ tr("الوكيل")
              }}<select
                :aria-label="tr('الوكيل صاحب الربط')"
                v-model="draft.agent"
                @change="changeAgent"
                required=""
              >
                <option value="" disabled="">{{ tr("اختر الوكيل") }}</option>
                <option v-for="a in agents" :value="a.id">
                  {{ tr(a.name) }}
                </option>
              </select></label
            ><label
              >{{ tr("التوكن / API Key")
              }}<input
                type="password"
                v-model="credentialValue"
                autocomplete="off"
                :disabled="busy"
            /></label>
          </div>
        </div>
        <div class="formfoot">
          <button
            type="button"
            class="btn"
            @click="loadCatalog"
            :disabled="busy || !draft.agent"
          >
            {{ tr(busy ? "جارٍ الجلب…" : "جلب فئات الشركة") }}
          </button>
        </div>
        <section
          v-if="catalogLoaded || catalogRows.length"
          class="digital-company-catalog"
          aria-live="polite"
        >
          <h4>
            {{ tr(catalogLoaded ? "فئات الشركة" : "الفئات المرتبطة حاليًا") }} ·
            {{ $root.tr(catalogRows.length) }}
          </h4>
          <p v-if="!catalogRows.length" class="empty">
            {{ tr("لا توجد فئات متاحة لدى الشركة") }}
          </p>
          <div
            v-for="item in catalogRows"
            :key="item.key"
            class="digital-company-category"
          >
            <div>
              <b>{{ $root.tr(item.name) }}</b
              ><small v-if="item.province">{{ $root.tr(item.province) }}</small>
            </div>
            <label
              >{{ tr("الفئة في النظام")
              }}<select
                :aria-label="tr('الفئة في النظام') + ' ' + item.name"
                :value="catalogOffer(item)?.productId || ''"
                @change="bindCatalogProduct(item, $event.target.value)"
              >
                <option value="">{{ tr("بدون ربط") }}</option>
                <option
                  v-for="p in catalogProducts(item)"
                  :key="p.id"
                  :value="p.id"
                >
                  {{ tr(p.name) }}
                </option>
              </select></label
            ><label
              >{{ tr("مبلغ البيع · د.ع")
              }}<input
                type="number"
                :aria-label="tr('مبلغ البيع') + ' ' + item.name"
                :value="catalogOffer(item)?.retail ?? item.retail"
                @input="catalogOffer(item).retail = Number($event.target.value)"
                :disabled="!catalogOffer(item)"
                :required="!!catalogOffer(item)"
                min="0.01"
                step="0.01"
            /></label>
          </div>
        </section>
        <label class="inline-check"
          ><input type="checkbox" v-model="draft.active" />{{
            tr("الربط مفعّل")
          }}</label
        >
        <div class="formfoot">
          <button class="btn primary" :disabled="busy">
            {{ tr(busy ? "جارٍ الحفظ…" : "حفظ إعداد الربط") }}</button
          ><button
            type="button"
            class="btn"
            @click="
              draft = null;
              credentialValue = '';
              error = '';
            "
          >
            {{ tr("إلغاء") }}
          </button>
        </div>
      </form>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr("الخدمة") }}</th>
              <th>{{ tr("الوكيل") }}</th>
              <th>{{ tr("الفئات") }}</th>
              <th>{{ tr("الحالة") }}</th>
              <th>{{ tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in connections" :key="c.id">
              <td>{{ tr(providerNames[c.provider]) }}</td>
              <td>{{ tr(vm.nameOf("agents", c.agent)) }}</td>
              <td>{{ $root.tr(c.offers.length) }}</td>
              <td>
                {{tr(!c.active?'معطل':c.offers.some(o=&gt;o.remoteId)?'مفعل':'بانتظار ربط الفئات')}}
              </td>
              <td>
                <div v-if="canSetup" class="actions">
                  <button class="btn small" @click="edit(c)">
                    {{ tr("تعديل") }}</button
                  ><button class="btn small" @click="toggle(c)">
                    {{ tr(c.active ? "تعطيل" : "تفعيل") }}
                  </button>
                </div>
                <button
                  v-if="canAssign&amp;&amp;e.digitalAgentOffers(c,vm.actor.agent).length"
                  class="btn small"
                  @click="assign(c)"
                >
                  {{ tr("توزيع الفئات") }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <form
        v-if="assignment&amp;&amp;canAssign"
        @submit.prevent="saveAllocation"
        class="digital-config"
      >
        <div class="formgrid">
          <label
            >{{ tr("نوع التابع")
            }}<select
              :aria-label="tr('نوع التابع')"
              v-model="assignment.kind"
              @change="changeAllocationKind"
            >
              <option value="agent">{{ tr("وكيل فرعي") }}</option>
              <option value="pos">{{ tr("نقطة بيع") }}</option>
            </select></label
          ><label
            >{{ tr("التابع")
            }}<select
              :aria-label="tr('التابع')"
              v-model="assignment.target"
              @change="loadAllocation"
              required=""
            >
              <option value="" disabled="">{{ tr("اختر التابع") }}</option>
              <option
                v-for="a in assignment.kind === 'agent'
                  ? assignmentAgents
                  : assignmentPoints"
                :value="a.id"
              >
                {{ tr(a.name) }}
              </option>
            </select></label
          >
        </div>
        <h4>{{ tr("الفئات المسموحة") }}</h4>
        <div class="digital-checks">
          <label v-for="o in assignmentOffers" :key="o.id"
            ><input
              type="checkbox"
              v-model="assignment.offerIds"
              :value="o.id"
            />{{ tr(o.name) }}</label
          >
        </div>
        <div class="formfoot">
          <button class="btn primary">{{ tr("حفظ الفئات") }}</button
          ><button type="button" class="btn" @click="assignment = null">
            {{ tr("إلغاء") }}
          </button>
        </div>
      </form>
      <div
        v-if="canAssign&amp;&amp;visibleGrants.length"
        class="tablewrap digital-grants"
      >
        <table>
          <thead>
            <tr>
              <th>{{ tr("الخدمة") }}</th>
              <th>{{ tr("التابع") }}</th>
              <th>{{ tr("الفئات") }}</th>
              <th>{{ tr("التفعيل") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="g in visibleGrants"
              :key="g.connection + ':' + g.kind + ':' + g.target"
            >
              <td>{{ tr(providerNames[g.provider]) }}</td>
              <td>
                {{
                  tr(vm.nameOf(g.kind === "agent" ? "agents" : "pos", g.target))
                }}
              </td>
              <td>{{ $root.tr(g.offerIds.length) }}</td>
              <td>
                <button
                  type="button"
                  role="switch"
                  class="digital-grant-switch"
                  :class="{ enabled: g.active !== false }"
                  :aria-checked="g.active !== false"
                  :aria-label="
                    tr(providerNames[g.provider]) +
                    ' · ' +
                    tr(
                      vm.nameOf(
                        g.kind === 'agent' ? 'agents' : 'pos',
                        g.target,
                      ),
                    )
                  "
                  :title="
                    tr(
                      g.active === false
                        ? 'اضغط لتفعيل الخدمة'
                        : 'اضغط لتعطيل الخدمة',
                    )
                  "
                  @click="toggleGrant(g)"
                >
                  <span class="digital-switch-track" aria-hidden="true"
                    ><span></span></span
                  ><span>{{
                    tr(g.active === false ? "الخدمة معطلة" : "الخدمة مفعلة")
                  }}</span>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="!connections.length" class="empty">
        {{ tr("لا توجد خدمات مربوطة") }}
      </p>
    </section>
    <section
      v-if="tab==='sell'&amp;&amp;canSell&amp;&amp;!(focusedSale&amp;&amp;receipt)"
      class="card"
    >
      <div v-if="focusedSale&amp;&amp;selected" class="pos-phone-title">
        <h3>{{ offer?.name }}</h3>
        <button class="btn small" :disabled="busy" @click="$emit('back')">
          {{ tr("رجوع") }}
        </button>
      </div>
      <div
        v-else-if="vm.posMobileEnabled&amp;&amp;selected"
        class="pos-phone-title"
      >
        <h3>{{ tr(providerNames[selected.provider]) }}</h3>
        <button class="btn small" @click="vm.go('sell')">
          {{ tr("الشركات") }} ←
        </button>
      </div>
      <form @submit.prevent="preview">
        <div class="formgrid">
          <label v-if="!focusedSale&amp;&amp;(!vm.posMobileEnabled||!selected)"
            >{{ tr("الخدمة والوكيل")
            }}<select
              :aria-label="tr('الخدمة والوكيل')"
              v-model="sale.connection"
              required=""
              :disabled="busy"
            >
              <option value="" disabled="">{{ tr("اختر الخدمة") }}</option>
              <option v-for="c in sellConnections" :value="c.id">
                {{ tr(providerNames[c.provider]) }} ·
                {{ tr(vm.nameOf("agents", c.agent)) }}
              </option>
            </select></label
          ><label v-if="!focusedSale&amp;&amp;!vm.posMobileEnabled"
            >{{ tr("نقطة البيع")
            }}<select
              :aria-label="tr('نقطة البيع')"
              v-model="sale.pos"
              required=""
              :disabled="busy"
            >
              <option value="" disabled="">{{ tr("اختر النقطة") }}</option>
              <option v-for="p in salePoints" :value="p.id">
                {{ tr(p.name) }}
              </option>
            </select></label
          ><label v-if="!focusedSale"
            >{{ tr("الفئة")
            }}<select
              :aria-label="tr('الفئة')"
              v-model="sale.offer"
              required=""
              :disabled="busy"
            >
              <option value="" disabled="">{{ tr("اختر الفئة") }}</option>
              <option v-for="o in offers" :value="o.id">
                {{ tr(o.name) }} · {{ money(o.retail) }} {{ tr("د.ع") }}
              </option>
            </select></label
          ><label v-if="needsPhone"
            >{{ tr("رقم الزبون")
            }}<input
              type="tel"
              v-model="sale.mobile"
              required=""
              dir="ltr"
              placeholder="07701234567"
              :disabled="busy" /></label
          ><label v-if="needsPhone&amp;&amp;!focusedSale"
            >{{ tr("تأكيد رقم الزبون")
            }}<input
              type="tel"
              v-model="sale.confirmMobile"
              required=""
              dir="ltr"
              :disabled="busy" /></label
          ><label v-if="offer?.packageType === 'premium'"
            >{{ tr("اسم المشترك")
            }}<input
              v-model="sale.firstName"
              required=""
              :disabled="busy" /></label
          ><label
            v-if="offer?.packageType==='premium'&amp;&amp;(selected.beinProvinces||[]).length"
            >{{ tr("محافظة المشترك")
            }}<select
              v-model="sale.beinProvinceId"
              required=""
              :disabled="busy"
            >
              <option value="" disabled="">{{ tr("اختر المحافظة") }}</option>
              <option v-for="p in selected.beinProvinces" :value="p.id">
                {{ p.name }}
              </option>
            </select></label
          ><label v-if="offer?.packageType === 'premium'"
            >{{ tr("اسم العائلة")
            }}<input v-model="sale.lastName" required="" :disabled="busy"
          /></label>
        </div>
        <div v-if="offer" class="digital-sale-summary">
          <span>{{ tr("مبلغ البيع") }}</span
          ><strong>{{ money(offer.retail) }} {{ tr("د.ع") }}</strong
          ><template v-if="costVisible"
            ><span>{{ tr("كلفة الشركة") }}</span
            ><b>{{ money(offer.cost) }} {{ tr("د.ع") }}</b></template
          >
        </div>
        <div class="formfoot">
          <button class="btn primary" :disabled="busy || !offer">
            {{ tr("مراجعة العملية") }}
          </button>
        </div>
      </form>
      <section
        v-if="confirmation"
        class="digital-confirm"
        role="region"
        :aria-label="tr('تأكيد العملية')"
      >
        <h3>{{ tr("تأكيد العملية") }}</h3>
        <p>
          {{ tr(vm.nameOf("pos", sale.pos)) }} · {{ tr(offer?.name) }} ·
          {{ money(offer?.retail) }} {{ tr("د.ع") }}
        </p>
        <p v-if="needsPhone" class="mono">{{ sale.mobile }}</p>
        <div class="actions">
          <button class="btn primary" @click="submit" :disabled="busy">
            {{ tr(busy ? "جارٍ التنفيذ…" : "تأكيد البيع") }}</button
          ><button class="btn" @click="confirmation = false" :disabled="busy">
            {{ tr("رجوع") }}
          </button>
        </div>
      </section>
      <p v-if="!sellConnections.length" class="empty">
        {{ tr("لا توجد خدمة مفعّلة لهذه النقطة؛ راجع الوكيل.") }}
      </p>
    </section>
    <section v-if="tab==='log'&amp;&amp;!focusedSale" class="card">
      <div class="toolbar">
        <h3>{{ tr("سجل الخدمات الإلكترونية") }}</h3>
        <button
          v-if="vm.actor.role!=='pos'&amp;&amp;connections.some(c=&gt;c.provider==='topup')"
          class="btn"
          @click="refreshBalances"
          :disabled="busy"
        >
          {{ tr("تحديث رصيد Topup") }}</button
        ><button
          v-if="vm.can('digital.export')"
          class="btn"
          @click="exportRows"
        >
          {{ tr("تصدير السجل") }}
        </button>
      </div>
      <div class="digital-filters">
        <label
          >{{ tr("الخدمة")
          }}<select :aria-label="tr('الخدمة')" v-model="filter.provider">
            <option value="">{{ tr("الكل") }}</option>
            <option v-for="(name, id) in providerNames" :value="id">
              {{ tr(name) }}
            </option>
          </select></label
        ><label v-if="vm.actor.role !== 'pos'"
          >{{ tr("الوكيل")
          }}<select :aria-label="tr('الوكيل')" v-model="filter.agent">
            <option value="">{{ tr("الكل") }}</option>
            <option
              v-for="a in agents.filter(a=&gt;connections.some(c=&gt;c.agent===a.id))"
              :value="a.id"
            >
              {{ tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("النقطة")
          }}<select :aria-label="tr('النقطة')" v-model="filter.pos">
            <option value="">{{ tr("الكل") }}</option>
            <option v-for="p in points" :value="p.id">{{ tr(p.name) }}</option>
          </select></label
        ><label v-if="!focusedSale"
          >{{ tr("الفئة")
          }}<select :aria-label="tr('الفئة')" v-model="filter.category">
            <option value="">{{ tr("الكل") }}</option>
            <option
              v-for="p in vm.s.products.filter(p=&gt;allRows.some(r=&gt;r.product===p.id))"
              :value="p.id"
            >
              {{ tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ tr("الحالة")
          }}<select :aria-label="tr('الحالة')" v-model="filter.status">
            <option value="">{{ tr("الكل") }}</option>
            <option v-for="(name, id) in stateNames" :value="id">
              {{ tr(name) }}
            </option>
          </select></label
        ><label
          >{{ tr("من تاريخ")
          }}<input type="date" v-model="filter.from" /></label
        ><label
          >{{ tr("إلى تاريخ") }}<input type="date" v-model="filter.to" /></label
        ><label
          >{{ tr("بحث")
          }}<input
            type="search"
            v-model="filter.query"
            :placeholder="tr('الموظف، رقم الزبون أو العملية')"
        /></label>
      </div>
      <div class="digital-totals">
        <div v-if="topupBalance" class="digital-balance-card">
          <span>{{
            tr(
              topupBalance.agent
                ? "الرصيد المتبقي للوكيل"
                : "إجمالي الرصيد المتبقي · Topup",
            )
          }}</span
          ><b>{{
            topupBalance.amount === null
              ? tr("لم يُجلب بعد")
              : money(topupBalance.amount) + " " + tr("د.ع")
          }}</b
          ><small v-if="topupBalance.agent">{{
            tr(vm.nameOf("agents", topupBalance.agent))
          }}</small
          ><small v-if="topupBalance.known&lt;topupBalance.total"
            >{{ tr("الرصيد المتوفر") }}: {{ $root.tr(topupBalance.known) }} /
            {{ $root.tr(topupBalance.total) }} ·
            {{ tr("حدّث الرصيد لإكمال الإجمالي") }}</small
          >
        </div>
        <div v-if="costVisible">
          <span>{{ tr("المصروف لدى الشركة") }}</span
          ><b>{{ $root.tr(money(summary.cost)) }} {{ tr("د.ع") }}</b>
        </div>
        <div v-if="!topupBalance || !costVisible">
          <span>{{ tr("مبيعات ناجحة") }}</span
          ><b>{{ $root.tr(money(summary.retail)) }} {{ tr("د.ع") }}</b>
        </div>
        <div>
          <span>{{ tr("عدد المبيعات") }}</span
          ><b>{{ $root.tr(summary.quantity) }}</b>
        </div>
      </div>
      <div
        v-if="summary.refunds || summary.pending"
        class="digital-status-summary"
      >
        <span v-if="summary.refunds"
          >{{ tr("مرتجعات") }}: {{ $root.tr(summary.refunds) }}</span
        ><span v-if="summary.pending"
          >{{ tr("بانتظار التحقق / التنفيذ") }}:
          {{ $root.tr(summary.pending) }}</span
        >
      </div>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr("العملية / الوقت") }}</th>
              <th>{{ tr("الخدمة / الوكيل") }}</th>
              <th>{{ tr("النقطة / الموظف") }}</th>
              <th>{{ tr("الفئة / رقم الزبون") }}</th>
              <th v-if="costVisible">{{ tr("الكلفة") }}</th>
              <th>{{ tr("مبلغ البيع") }}</th>
              <th>{{ tr("الحالة") }}</th>
              <th>{{ tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="r in shown" :key="r.id">
              <td>
                <span class="mono">{{ $root.tr(r.id) }}</span
                ><small>{{ $root.tr(vm.formatTime(r.time)) }}</small>
              </td>
              <td>
                {{ tr(providerNames[r.provider])
                }}<small>{{ tr(vm.nameOf("agents", r.mainAgent)) }}</small>
              </td>
              <td>
                {{ tr(vm.nameOf("pos", r.pos))
                }}<small>{{ tr(r.employee) }}</small>
              </td>
              <td>
                {{ tr(r.category)
                }}<small class="mono">{{ $root.tr(r.mobile || "—") }}</small>
              </td>
              <td v-if="costVisible">{{ $root.tr(money(r.cost)) }}</td>
              <td>{{ $root.tr(money(r.retail)) }}</td>
              <td>
                <span
                  class="badge"
                  :class="
                    r.status === 'succeeded'
                      ? 'good'
                      : ['pending', 'review'].includes(r.status)
                        ? 'warn'
                        : 'neutral'
                  "
                  >{{ tr(stateNames[r.status]) }}</span
                >
              </td>
              <td>
                <div class="actions">
                  <button
                    v-if="!r.legacy&amp;&amp;r.mode==='server'&amp;&amp;r.status==='succeeded'&amp;&amp;vm.can('digital.receipt')"
                    class="btn small"
                    @click="viewReceipt(r)"
                  >
                    {{ tr("الإيصال") }}</button
                  ><button
                    v-if="!r.legacy&amp;&amp;['pending','review'].includes(r.status)&amp;&amp;canSell"
                    class="btn small"
                    @click="verify(r)"
                    :disabled="busy"
                  >
                    {{ tr("تحقق من النتيجة") }}
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!shown.length">
              <td colspan="8" class="empty">
                {{ tr("لا توجد عمليات مطابقة") }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="formfoot">
        <button class="btn small" :disabled="page&lt;=1" @click="page--">
          {{ tr("السابق") }}</button
        ><span
          >{{ $root.tr(page) }} / {{ $root.tr(pages) }} ·
          {{ $root.tr(filtered.length) }}</span
        ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
          {{ tr("التالي") }}
        </button>
      </div>
    </section>
    <section
      v-if="receipt&amp;&amp;receipt.status==='succeeded'"
      class="card digital-receipt"
    >
      <div class="digital-receipt-content">
        <h3>{{ tr("إيصال خدمة إلكترونية") }}</h3>
        <p>
          {{ tr(providerNames[receipt.provider]) }} · {{ tr(receipt.category) }}
        </p>
        <p>
          {{ tr(vm.nameOf("pos", receipt.pos)) }} · {{ tr(receipt.employee) }}
        </p>
        <p>{{ vm.formatTime(receipt.time) }}</p>
        <p v-if="receipt.mobile" class="mono">{{ receipt.mobile }}</p>
        <p v-if="receipt.code" class="digital-code mono">{{ receipt.code }}</p>
        <p v-if="receipt.serial" class="mono">{{ receipt.serial }}</p>
        <strong>{{ money(receipt.retail) }} {{ tr("د.ع") }}</strong>
        <p class="mono">{{ receipt.companyTransactionId }}</p>
      </div>
      <div class="actions digital-receipt-actions">
        <button class="btn primary" @click="print">
          {{ tr("طباعة الإيصال") }}</button
        ><button
          class="btn"
          @click="focusedSale ? $emit('back') : (receipt = null)"
        >
          {{
            tr(
              initialReceipt
                ? "رجوع للعمليات"
                : focusedSale
                  ? "بيع جديد"
                  : "إغلاق",
            )
          }}
        </button>
      </div>
    </section>
  </section>
</template>

<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["operations-panel"]);
export default options;
</script>

<template>
  <section v-if="hasPanel" class="operations-panel">
    <template v-if="page === 'wallets'"
      ><section class="wallet-advanced">
        <div class="card">
          <div class="cardhead wallet-filter-toolbar">
            <div class="tabs wallet-navigation-tabs">
              <button
                v-for="x in [
                  { id: 'balances', name: 'الأرصدة والحركات' },
                  {
                    id: 'fundingRequests',
                    name:
                      e.walletIdentity() === '@owner'
                        ? 'طلبات تمويل الوكلاء'
                        : 'طلبات وسجل التمويل',
                  },
                  ...(can('wallets.bulk')
                    ? [{ id: 'bulk', name: 'تمويل متعدد' }]
                    : []),
                  { id: 'recovery', name: 'استرجاع الرصيد' },
                  { id: 'invoice', name: 'الفواتير والتحصيل' },
                ]"
                :class="{ active: tab === x.id }"
                @click="tab = x.id"
              >
                {{
                  vm.tr(
                    vm.posMobileEnabled
                      ? {
                          balances: "الرصيد والحركات",
                          fundingRequests: "التمويل",
                          invoice: "الفواتير",
                          bulk: "تمويل متعدد",
                        }[x.id] || x.name
                      : x.name,
                  )
                }}<span
                  v-if="x.id==='fundingRequests'&amp;&amp;walletFundingPending"
                  class="badge wallet-funding-count"
                  >{{ $root.tr(walletFundingPending) }}</span
                >
              </button>
            </div>
            <div class="actions">
              <button class="btn" @click="vm.openWalletFilters()">
                {{ $root.tr("فلترة وبحث")
                }}<span v-if="vm.walletFiltersActive"> •</span></button
              ><button
                v-if="vm.walletFiltersActive"
                class="btn small"
                @click="vm.clearWalletFilters()"
              >
                {{ $root.tr("مسح الفلاتر") }}
              </button>
            </div>
            <div
              class="wallet-service-cards"
              role="group"
              :aria-label="$root.tr('نوع المحفظة')"
            >
              <button
                type="button"
                v-for="x in services"
                :key="x.id"
                :aria-pressed="service === x.id"
                :class="{ active: service === x.id }"
                @click="service = x.id"
              >
                {{ vm.tr(x.name) }}
              </button>
            </div>
          </div>
          <div
            v-if="tab !== 'fundingRequests'"
            class="ops-stats wallet-summary-cards"
          >
            <div data-wallet-metric="current">
              <span>{{
                vm.tr(
                  walletOwnTotals
                    ? "رصيدي الحالي"
                    : "الرصيد الحالي للحسابات المعروضة",
                )
              }}</span
              ><strong
                >{{ $root.tr(money(walletDisplayTotals.current))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
            <div data-wallet-metric="available">
              <span>{{
                vm.tr(
                  walletOwnTotals
                    ? "رصيدي المتاح"
                    : "الرصيد المتاح للحسابات المعروضة",
                )
              }}</span
              ><strong
                >{{ $root.tr(money(walletDisplayTotals.available))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
            <div data-wallet-metric="held">
              <span>{{
                vm.tr(
                  walletOwnTotals
                    ? "رصيدي المحجوز"
                    : "الرصيد المحجوز للحسابات المعروضة",
                )
              }}</span
              ><strong
                >{{ $root.tr(money(walletDisplayTotals.held))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
            <div
              v-if="walletChildrenTotal !== null"
              data-wallet-metric="children"
            >
              <span>{{ vm.tr("أرصدة التابعين المعروضين") }}</span
              ><strong
                >{{ $root.tr(money(walletChildrenTotal))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
            <div>
              <span>{{ vm.tr("طلبات بانتظار التمويل") }}</span
              ><strong
                >{{ $root.tr(requests.filter(r=&gt;r.status==='بانتظار التمويل').length) }}</strong
              >
            </div>
            <div>
              <span>{{ vm.tr("تحويلات منفذة") }}</span
              ><strong
                >{{ $root.tr(transfers.filter(r=&gt;r.status==='منفذ').length) }}</strong
              >
            </div>
            <div v-if="showStockMetrics" data-wallet-metric="stock-count">
              <span>{{ vm.tr("عدد بطاقات مخزون الوكيل الرئيسي") }}</span
              ><strong>{{ $root.tr(cardMetrics.count) }}</strong>
            </div>
            <div
              v-if="showStockMetrics&amp;&amp;can('data.cost')"
              data-wallet-metric="stock-cost"
            >
              <span>{{ vm.tr("تكلفة مخزون الوكيل الرئيسي") }}</span
              ><strong
                >{{ $root.tr(money(cardMetrics.cost))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
            <div v-if="showStockMetrics" data-wallet-metric="stock-value">
              <span>{{ vm.tr("قيمة مخزون البطاقات") }}</span
              ><strong
                >{{ $root.tr(money(cardMetrics.credit))
                }}{{ $root.tr(" د.ع") }}</strong
              >
            </div>
          </div>
        </div>
        <simple-wallets
          v-if="tab === 'fundingRequests'"
          :wallet-service="service"
        ></simple-wallets
        ><template v-if="tab === 'balances'"
          ><wallet-accounts-table
            :rows="walletAccounts"
            :service="service"
            @movements="account=$event; $nextTick(()=&gt; $el.querySelector('.wallet-movements')?.scrollIntoView({behavior:'smooth'}))"
          ></wallet-accounts-table>
          <div class="card wallet-movements">
            <label
              >{{ vm.tr("حركات الحساب")
              }}<select v-model="account">
                <option value="">{{ vm.tr("كل الحسابات") }}</option>
                <option v-for="a in walletAccounts" :value="a.id">
                  {{ vm.tr(a.name) }}
                </option>
              </select></label
            >
            <div class="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>{{ vm.tr("الوقت") }}</th>
                    <th>{{ vm.tr("الحساب") }}</th>
                    <th>{{ vm.tr("الحركة") }}</th>
                    <th>{{ vm.tr("المبلغ") }}</th>
                    <th>{{ vm.tr("المرجع") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-if="!ledger.length">
                    <td colspan="5" class="empty">
                      {{ $root.tr("لا توجد حركات مطابقة") }}
                    </td>
                  </tr>
                  <tr v-for="l in ledger">
                    <td>{{ vm.tr(vm.formatTime(l.time)) }}</td>
                    <td>{{ vm.tr(name(l.account)) }}</td>
                    <td>{{ vm.tr(l.kind) }}</td>
                    <td :class="{'ops-negative':l.amount&lt;0}">
                      {{ vm.tr(money(l.amount)) }}
                    </td>
                    <td>{{ vm.tr(l.group) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div></template
        >
        <div v-if="tab === 'fund'" class="two">
          <div class="card">
            <div class="formgrid">
              <label
                >{{ vm.tr("الوكيل الممول")
                }}<select
                  v-model="from"
                  @change="
                    exception = '';
                    newKey();
                  "
                >
                  <option v-for="a in agents" :value="a.id">
                    {{ vm.tr(a.name) }}
                  </option>
                </select></label
              ><label
                >{{ vm.tr("المستفيد")
                }}<select v-model="to" @change="newKey">
                  <option
                    v-for="a in accounts.filter(a=&gt;a.id!==from&amp;&amp;e.descendants(from).includes(e.accountAgent(a.id)))"
                    :value="a.id"
                  >
                    {{ vm.tr(a.name) }}
                  </option>
                </select></label
              ><label
                >{{ vm.tr("المبلغ")
                }}<input
                  type="text"
                  inputmode="decimal"
                  v-money=""
                  min="0.01"
                  v-model.number="amount"
                  @input="newKey" /></label
              ><label
                >{{ vm.tr("الغرض من التمويل")
                }}<textarea v-model="reason"></textarea></label
              ><label
                >{{ vm.tr("مرجع الإيداع / التحصيل")
                }}<input v-model="reference"
              /></label>
            </div>
            <div class="actions">
              <button
                v-if="can('wallets.request')"
                class="btn"
                @click="request"
                :disabled="formPending.request || !to || !(Number(amount)&gt;0) || !from || !reason.trim()"
              >
                {{ vm.tr("تقديم طلب تمويل") }}</button
              ><button
                v-if="can('wallets.exception')"
                class="btn"
                @click="authorize"
                :disabled="formPending.authorize"
              >
                {{ vm.tr("توثيق استثناء تمويل") }}</button
              ><button
                v-if="can('wallets.transfer')"
                class="btn primary"
                @click="fund"
                :disabled="formPending.fund || !to || !(Number(amount)&gt;0) || !from"
              >
                {{ vm.tr("تنفيذ التمويل") }}</button
              ><button
                v-if="service!=='voucher'&amp;&amp;can('wallets.deposit')"
                class="btn"
                @click="credit"
                :disabled="formPending.credit || !to || !(Number(amount)&gt;0)"
              >
                {{ vm.tr("إيداع لهذه الخدمة") }}
              </button>
            </div>
          </div>
          <div class="card">
            <button
              v-if="can('wallets.transfer')&amp;&amp;fundSelected.length"
              class="btn primary"
              @click="approveRequests"
              :disabled="formPending.approveRequests"
            >
              {{ vm.tr("معاينة الطلبات المحددة") }}
            </button>
            <div v-if="!requests.length" class="empty">
              {{ $root.tr("لا توجد طلبات مطابقة") }}
            </div>
            <article v-for="r in requests" class="ops-request">
              <label v-if="r.status === 'بانتظار التمويل'"
                ><input
                  type="checkbox"
                  :value="r.id"
                  v-model="fundSelected"
                />{{ vm.tr("تحديد") }}</label
              ><b>{{ vm.tr(name(r.to)) }} ← {{ vm.tr(name(r.from)) }}</b>
              <p>
                {{ vm.tr(serviceName(r.service)) }} · {{ vm.tr(money(r.amount))
                }}{{ vm.tr("د.ع ·") }}{{ vm.tr(r.purpose) }}
              </p>
              <p v-if="r.approver" class="muted">
                {{ vm.tr("المعتمد") }}: {{ vm.tr(name(r.approver)) }} ·
                {{ vm.tr(vm.formatTime(r.approvedAt)) }}
              </p>
              <span class="badge">{{ vm.tr(r.status) }}</span>
              <div v-if="r.status === 'معتمد ومحجوز'" class="actions">
                <button
                  v-if="can('wallets.transfer')"
                  class="btn primary"
                  @click="completeRequest(r)"
                  :disabled="formPending.completeRequest"
                >
                  {{ vm.tr("تسليم الرصيد للمستفيد") }}</button
                ><button
                  v-if="can('wallets.reverse')"
                  class="btn"
                  @click="cancelRequestHold(r)"
                  :disabled="formPending.cancelRequestHold"
                >
                  {{ vm.tr("إلغاء الحجز") }}
                </button>
              </div>
              <div
                v-if="r.status==='بانتظار التمويل'&amp;&amp;can('wallets.transfer')"
                class="actions"
              >
                <input
                  type="text"
                  inputmode="decimal"
                  v-money=""
                  :placeholder="$root.tr(String(r.amount))"
                  v-model.number="fundAmounts[r.id]"
                  :aria-label="$root.tr('المبلغ المعتمد')"
                /><button
                  class="btn"
                  @click="process(r)"
                  :disabled="formPending.process"
                >
                  {{ vm.tr("اعتماد وحجز المبلغ") }}
                </button>
              </div>
            </article>
          </div>
        </div>
        <div v-if="tab === 'recovery'" class="recovery-page">
          <form
            v-if="vm.can('security.fundingRecovery')"
            class="card recovery-policy"
            @submit.prevent="saveRecoveryHours"
          >
            <label
              >{{ $root.tr("المهلة · ساعات")
              }}<input
                type="number"
                min="0.01"
                step="any"
                required=""
                v-model.number="recoveryHoursDraft"
                :aria-label="
                  $root.tr('مدة السماح باسترجاع الرصيد بالساعات')
                " /></label
            ><button class="btn primary">{{ $root.tr("حفظ المهلة") }}</button>
          </form>
          <form
            v-if="recoveryFrom &amp;&amp; can('wallets.reverse') &amp;&amp; service!=='all'"
            class="card recovery-form"
            @submit.prevent="submitRecovery"
          >
            <h3>{{ $root.tr("استرجاع الرصيد") }}</h3>
            <div class="formgrid">
              <label
                >{{ $root.tr("الحساب")
                }}<select
                  v-model="recovery.to"
                  @change="
                    recovery.amount = '';
                    recovery.transferId = '';
                    recoveryError = '';
                  "
                  required=""
                >
                  <option value="" disabled="">
                    {{ $root.tr("اختر الحساب") }}
                  </option>
                  <option
                    v-for="a in recoveryAccounts"
                    :key="a.id"
                    :value="a.id"
                  >
                    {{ $root.tr(name(a.id)) }}
                  </option>
                </select></label
              ><label
                >{{ $root.tr("عملية التمويل")
                }}<select
                  v-model="recovery.transferId"
                  @change="
                    recovery.amount = '';
                    recoveryError = '';
                  "
                  required=""
                  :aria-label="$root.tr('عملية التمويل للاسترجاع')"
                >
                  <option value="" disabled="">
                    {{ $root.tr("اختر عملية التمويل") }}
                  </option>
                  <option
                    v-for="t in recoveryTransfers"
                    :key="t.id"
                    :value="t.id"
                    :disabled="!e.fundingRecoveryOpen(t, recoveryClock)"
                  >
                    {{ $root.tr(t.id) }} · {{ $root.tr(money(t.amount))
                    }}{{ $root.tr(" د.ع · ")
                    }}{{ $root.tr(recoveryTimeLabel(t)) }}
                  </option>
                </select></label
              ><label
                >{{ $root.tr("المبلغ المسترجع · د.ع")
                }}<input
                  type="text"
                  inputmode="decimal"
                  v-money=""
                  min="0.01"
                  step="0.01"
                  :max="recoveryLimit"
                  v-model.number="recovery.amount"
                  required="" /></label
              ><label
                >{{ $root.tr("سبب الاسترجاع")
                }}<input v-model="recovery.reason" required=""
              /></label>
              <div class="recovery-available">
                <span>{{ $root.tr("المتاح للاسترجاع") }}</span
                ><strong class="ops-number"
                  >{{ $root.tr(money(recoveryLimit))
                  }}{{ $root.tr(" د.ع") }}</strong
                ><button
                  type="button"
                  class="btn"
                  :disabled="!recoveryLimit"
                  @click="recovery.amount = recoveryLimit"
                >
                  {{ $root.tr("كامل المتاح") }}
                </button>
              </div>
            </div>
            <p v-if="selectedRecoveryTransfer" class="help">
              {{ $root.tr(recoveryTimeLabel(selectedRecoveryTransfer))
              }}{{ $root.tr(" · آخر موعد: ")
              }}{{
                $root.tr(
                  selectedRecoveryTransfer.recoveryDeadline
                    ? vm.formatTime(selectedRecoveryTransfer.recoveryDeadline)
                    : "غير متوفر",
                )
              }}
            </p>
            <p v-if="recoveryError" class="notice warn" role="alert">
              {{ $root.tr(recoveryError) }}
            </p>
            <div class="actions">
              <button
                class="btn primary"
                :disabled="!recovery.to || !recovery.transferId || !(recovery.amount&gt;0) || recovery.amount&gt;recoveryLimit || !recovery.reason.trim()"
              >
                {{ $root.tr("استرجاع إلى محفظتي") }}
              </button>
            </div>
          </form>
          <p v-else-if="service === 'all'" class="card empty">
            {{ $root.tr("اختر محفظة محددة للاسترجاع") }}
          </p>
          <section class="card recovery-history">
            <h3>{{ $root.tr("سجل استرجاع الرصيد") }}</h3>
            <div class="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>{{ $root.tr("التاريخ") }}</th>
                    <th>{{ $root.tr("الحساب المسترجع منه") }}</th>
                    <th>{{ $root.tr("الوكيل المستلم") }}</th>
                    <th>{{ $root.tr("المبلغ") }}</th>
                    <th>{{ $root.tr("السبب") }}</th>
                    <th>{{ $root.tr("رقم العملية") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in recoveryRows" :key="r.id">
                    <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
                    <td>{{ $root.tr(name(r.to)) }}</td>
                    <td>{{ $root.tr(name(r.from)) }}</td>
                    <td>
                      {{ $root.tr(money(r.amount)) }}{{ $root.tr(" د.ع") }}
                    </td>
                    <td>{{ r.reason }}</td>
                    <td>{{ $root.tr(r.id) }}</td>
                  </tr>
                  <tr v-if="!recoveryRows.length">
                    <td colspan="6" class="empty">
                      {{ $root.tr("لا توجد عمليات استرجاع") }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>
        <div v-if="tab==='bulk'&amp;&amp;can('wallets.bulk')" class="card">
          <label
            >{{ vm.tr("الممول")
            }}<select
              v-model="from"
              :aria-label="$root.tr('الممول للتمويل المتعدد')"
            >
              <option v-for="a in bulkFunders" :value="a.id">
                {{ vm.tr(a.name) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("بحث باسم المستفيد")
            }}<input
              v-model="bulkSearch"
              :placeholder="$root.tr('اسم النقطة أو الوكيل أو المحافظة')"
          /></label>
          <p class="help">
            {{
              $root.tr(
                "حدد المستفيدين وأدخل مبلغ كل واحد، ثم اضغط معاينة المجموعة. المحفظة المستخدمة: ",
              )
            }}{{ $root.tr(serviceName(service)) }}.
          </p>
          <div class="tablewrap" style="max-height: 420px; overflow: auto">
            <table class="bulk-recipient-table">
              <thead>
                <tr>
                  <th>{{ $root.tr("اختيار") }}</th>
                  <th>{{ $root.tr("المستفيد") }}</th>
                  <th>{{ $root.tr("النوع") }}</th>
                  <th>{{ $root.tr("المبلغ • د.ع") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="a in bulkVisibleRecipients" :key="a.id">
                  <td>
                    <input
                      type="checkbox"
                      v-model="bulkSelected"
                      :value="a.id"
                      :aria-label="$root.tr('اختيار ' + a.name)"
                      @change="bulkText = ''"
                    />
                  </td>
                  <td>{{ $root.tr(a.name) }}</td>
                  <td>
                    {{ $root.tr(s.pos.some(p=&gt;p.id===a.id)?'نقطة بيع':a.type||'وكيل') }}
                  </td>
                  <td>
                    <input
                      type="text"
                      inputmode="decimal"
                      v-money=""
                      min="0.01"
                      step="0.01"
                      v-model.number="bulkAmounts[a.id]"
                      :disabled="!bulkSelected.includes(a.id)"
                      :aria-label="$root.tr('مبلغ ' + a.name)"
                      :placeholder="$root.tr('أدخل المبلغ')"
                    />
                  </td>
                </tr>
                <tr v-if="!bulkVisibleRecipients.length">
                  <td colspan="4">
                    {{ $root.tr("لا توجد جهات مطابقة متاحة للتمويل.") }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <p>
            {{ $root.tr("المستفيدون المحددون: ")
            }}{{ $root.tr(bulkSelected.length) }}
          </p>
          <details>
            <summary>{{ $root.tr("استيراد قائمة من ملف — اختياري") }}</summary>
            <label
              >{{ vm.tr("استيراد المستفيد، المبلغ، المحفظة، مرجع الطلب")
              }}<input
                type="file"
                accept=".xlsx,.csv,.txt,.tsv"
                @change="readBulk" /></label
            ><textarea
              v-model="bulkText"
              dir="ltr"
              rows="5"
              placeholder="POS1,25000
A3,50000"
              @input="bulkSelected = []"
            ></textarea>
          </details>
          <div class="actions">
            <button
              v-if="can('wallets.bulk')"
              class="btn primary"
              @click="bulk"
              :disabled="formPending.bulk"
            >
              {{ vm.tr("معاينة المجموعة") }}</button
            ><button class="btn" @click="clearBulkSelection">
              {{ vm.tr("بدء مجموعة جديدة") }}
            </button>
          </div>
          <div v-for="r in bulkResult" class="rowline">
            <span>{{ vm.tr(name(r.to)) }}</span
            ><span>{{ vm.tr(r.status) }} {{ vm.tr(r.reason || "") }}</span>
          </div>
        </div>
        <teleport to="body"
          ><dialog
            v-if="fundingPreview&amp;&amp;can('wallets.bulk')"
            ref="fundingDialog"
            class="funding-preview-dialog"
            tabindex="-1"
            @cancel.prevent="closeFundingPreview"
            :aria-label="$root.tr('معاينة مجموعة التمويل')"
          >
            <div class="support-section-heading">
              <h2>{{ $root.tr("معاينة مجموعة التمويل") }}</h2>
              <button
                class="iconbtn"
                @click="closeFundingPreview"
                :disabled="fundingBusy"
                :aria-label="$root.tr('إغلاق معاينة التمويل')"
              >
                ×
              </button>
            </div>
            <p>
              <b>{{ $root.tr("الممول:") }}</b>
              {{ $root.tr(name(fundingPreview.rows[0]?.from)) }}
            </p>
            <div class="tablewrap">
              <table>
                <thead>
                  <tr>
                    <th>{{ $root.tr("المستفيد") }}</th>
                    <th>{{ $root.tr("المحفظة") }}</th>
                    <th>{{ $root.tr("المبلغ") }}</th>
                    <th>{{ $root.tr("مرجع الطلب") }}</th>
                    <th>{{ $root.tr("الفحص") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in fundingPreview.rows">
                    <td>{{ $root.tr(name(r.to)) }}</td>
                    <td>{{ $root.tr(serviceName(r.service)) }}</td>
                    <td>{{ $root.tr(money(r.amount)) }}</td>
                    <td>{{ $root.tr(r.reference || "—") }}</td>
                    <td>{{ $root.tr(r.error || "صالح") }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div v-for="b in fundingPreview.balances" class="rowline">
              <b>{{ $root.tr(serviceName(b.service)) }}</b
              ><span
                >{{ $root.tr("الإجمالي: ")
                }}{{ $root.tr(money(b.total)) }}</span
              ><span
                >{{ $root.tr("المتاح: ")
                }}{{ $root.tr(money(b.available)) }}</span
              ><span
                >{{ $root.tr("المتبقي: ")
                }}{{ $root.tr(money(b.remaining)) }}</span
              >
            </div>
            <p v-if="fundingError" role="alert" class="notice warn">
              {{ $root.tr(fundingError) }}
            </p>
            <p
              v-else-if="fundingPreview.balances.some(b=&gt;b.remaining&lt;0)"
              role="alert"
              class="notice warn"
            >
              {{ $root.tr("الرصيد لا يكفي لكامل المجموعة") }}
            </p>
            <div class="actions">
              <button
                class="btn primary"
                :disabled="!fundingPreview.valid || fundingBusy"
                @click="confirmFunding"
              >
                {{ $root.tr("تأكيد التمويل") }}</button
              ><button class="btn" @click="downloadFunding()">
                {{ $root.tr("تنزيل الفحص") }}</button
              ><button
                class="btn"
                @click="fundingPreview = null"
                :disabled="fundingBusy"
              >
                {{ $root.tr("إلغاء") }}
              </button>
            </div>
          </dialog></teleport
        >
        <div v-if="tab==='bulk'&amp;&amp;can('wallets.bulk')" class="card">
          <div v-for="b in fundingGroups" class="rowline">
            <b>{{ $root.tr(b.id) }}</b
            ><span
              >{{ $root.tr(vm.formatTime(b.time)) }} ·
              {{ $root.tr(b.results.length) }}{{ $root.tr(" مستفيد") }}</span
            ><button class="btn" @click="downloadFunding(b)">
              {{ $root.tr("تنزيل النتائج") }}
            </button>
          </div>
        </div>
        <div v-if="tab === 'invoice'" class="card">
          <label
            >{{ vm.tr("حساب الوكيل")
            }}<select v-model="account">
              <option value="">{{ vm.tr("الكل") }}</option>
              <option v-for="a in walletAgents" :value="a.id">
                {{ vm.tr(a.name) }}
              </option>
            </select></label
          >
          <p v-if="account" class="notice">
            {{ vm.tr("المتبقي بعد التحصيل:") }}{{ vm.tr(money(debt))
            }}{{ vm.tr("د.ع") }}
          </p>
          <div class="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>{{ vm.tr("الفاتورة") }}</th>
                  <th>{{ vm.tr("الوكيل") }}</th>
                  <th>{{ vm.tr("الكمية") }}</th>
                  <th>{{ vm.tr("سعر التحميل") }}</th>
                  <th>{{ vm.tr("القيمة") }}</th>
                  <th v-if="can('data.profit')">
                    {{ vm.tr("الربح المتوقع") }}
                  </th>
                  <th>{{ vm.tr("الحالة") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-if="!invoices.filter(i=&gt;!account||i.agent===account).length"
                >
                  <td :colspan="can('data.profit') ? 7 : 6" class="empty">
                    {{ $root.tr("لا توجد فواتير مطابقة") }}
                  </td>
                </tr>
                <tr
                  v-for="i in invoices.filter(i=&gt;!account||i.agent===account)"
                >
                  <td>{{ vm.tr(i.id) }}</td>
                  <td>{{ vm.tr(name(i.agent)) }}</td>
                  <td>{{ vm.tr(i.quantity) }}</td>
                  <td>{{ vm.tr(money(i.loadPrice)) }}</td>
                  <td>{{ vm.tr(money(i.amount)) }}</td>
                  <td v-if="can('data.profit')">
                    {{ vm.tr(money(i.profit)) }} · {{ vm.tr(i.contract) }}
                  </td>
                  <td>{{ vm.tr(i.status) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="can('wallets.collect')" class="formgrid">
            <label
              >{{ vm.tr("المدين")
              }}<select
                v-model="collectionForm.account"
                :disabled="collectionBusy"
              >
                <option value="" disabled="">{{ vm.tr("اختر المدين") }}</option>
                <option v-for="a in agents" :value="a.id">
                  {{ vm.tr(a.name) }}
                </option>
              </select></label
            ><label
              >{{ vm.tr("مبلغ التحصيل")
              }}<input
                type="text"
                inputmode="decimal"
                v-money=""
                min="0.01"
                step="any"
                v-model.number="collectionForm.amount"
                :disabled="collectionBusy" /></label
            ><label
              >{{ vm.tr("الطريقة")
              }}<select
                v-model="collectionForm.method"
                :disabled="collectionBusy"
              >
                <option value="" disabled="">
                  {{ vm.tr("اختر طريقة التحصيل") }}
                </option>
                <option value="مندوب">{{ vm.tr("مندوب") }}</option>
                <option value="QiCard">QiCard</option>
                <option value="تحويل مصرفي">{{ vm.tr("تحويل مصرفي") }}</option>
              </select></label
            ><label
              >{{ vm.tr("رقم السند")
              }}<input
                v-model="collectionForm.reference"
                :disabled="collectionBusy" /></label
            ><button
              class="btn primary"
              :disabled="collectionBusy || !collectionForm.account || !(Number(collectionForm.amount)&gt;0) || !collectionForm.method || !collectionForm.reference.trim()"
              @click="collect"
            >
              {{ vm.tr(collectionBusy ? "جارٍ التسجيل…" : "تسجيل تحصيل") }}
            </button>
          </div>
        </div>
      </section></template
    >

    <div v-if="page === 'exceptions'" class="card">
      <label v-if="vm.ownReprintSales.length"
        >{{ vm.tr("سبب الطلب") }}<input v-model="reason"
      /></label>
      <div v-for="t in vm.ownReprintSales" class="rowline">
        <span
          >{{ vm.tr(name(t.pos)) }} · {{ vm.tr(t.id) }} ·
          {{ vm.tr(t.reprints) }} / {{ vm.tr(e.reprintLimit(t.pos)) }}</span
        ><button
          v-if="can('sell.reprint')"
          class="btn"
          @click="requestPrint(t)"
          :disabled="formPending.requestPrint"
        >
          {{ vm.tr("طلب إذن إعادة الطباعة") }}
        </button>
      </div>
      <div v-for="r in printRequests" class="ops-request">
        <b>{{ vm.tr(r.tx) }} · {{ vm.tr(r.reason) }}</b
        ><span class="badge">{{ vm.tr(r.status) }}</span
        ><span>{{ $root.tr("لدى: ") }}{{ $root.tr(printRecipient(r)) }}</span>
        <p v-if="r.failureReason">
          {{ $root.tr("سبب فشل الطباعة: ") }}{{ $root.tr(r.failureReason) }}
        </p>
        <details v-if="r.history?.length&gt;1">
          <summary>{{ $root.tr("سجل التصعيد") }}</summary>
          <div v-for="h in r.history" class="rowline">
            <span
              >{{ $root.tr(name(h.from)) }} ←
              {{
                $root.tr(h.to === "@system" ? "إدارة النظام" : name(h.to))
              }}</span
            ><span>{{ h.reason }}</span>
          </div>
        </details>
        <div v-if="e.canEscalatePrint(r)">
          <label
            >{{ $root.tr("سبب التصعيد")
            }}<input v-model="requestReasons[r.id]" /></label
          ><button class="btn" @click="escalatePrint(r)">
            {{ $root.tr("رفع إلى الأعلى") }}
          </button>
        </div>
        <div
          class="actions"
          v-if="r.status==='قيد المراجعة'&amp;&amp;can('exceptions.approve')&amp;&amp;e.canApproveReprint(r)"
        >
          <button
            class="btn primary"
            @click="approvePrint(r, true)"
            :disabled="formPending.approvePrint"
          >
            {{ vm.tr("اعتماد محاولة واحدة") }}</button
          ><button
            class="btn danger"
            @click="approvePrint(r, false)"
            :disabled="formPending.approvePrint"
          >
            {{ vm.tr("رفض") }}
          </button>
        </div>
      </div>
    </div>
    <div v-if="page === 'claims'" class="card">
      <div class="tablewrap">
        <table class="claims-register">
          <thead>
            <tr>
              <th>{{ $root.tr("رقم الطلب") }}</th>
              <th>{{ $root.tr("الوكيل") }}</th>
              <th>{{ $root.tr("الفئة") }}</th>
              <th>{{ $root.tr("عدد البطاقات") }}</th>
              <th>{{ $root.tr("تاريخ الطلب") }}</th>
              <th>{{ $root.tr("الحالة") }}</th>
              <th>{{ $root.tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in vm.visibleClaims" :key="c.id">
              <td>{{ $root.tr(c.id) }}</td>
              <td>{{ $root.tr(name(c.agent)) }}</td>
              <td>
                {{ $root.tr(vm.nameOf('products',c.product||s.batches.find(b=&gt;b.id===c.batch)?.product)) }}
              </td>
              <td>{{ $root.tr(c.quantity) }}</td>
              <td>{{ $root.tr(vm.formatTime(c.time)) }}</td>
              <td>
                <span
                  class="badge"
                  :class="{ neutral: c.status === 'معلقة' }"
                  >{{
                    $root.tr(
                      c.status === "معلقة" ? "بانتظار المعالجة" : c.status,
                    )
                  }}</span
                >
              </td>
              <td>
                <button class="btn small" @click="openClaimPreview(c)">
                  {{ $root.tr("معاينة") }}
                </button>
              </td>
            </tr>
            <tr v-if="!vm.visibleClaims.length">
              <td colspan="7" class="empty">{{ $root.tr("لا توجد طلبات") }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
    <teleport to="body"
      ><dialog
        v-if="claimPreview"
        ref="claimPreviewDialog"
        class="funding-preview-dialog claim-preview-dialog"
        @cancel.prevent="closeClaimPreview"
        :aria-label="$root.tr('معاينة طلب التالف')"
      >
        <header class="support-section-heading">
          <h2>
            {{ $root.tr("معاينة الطلب · ") }}{{ $root.tr(claimPreview.id) }}
          </h2>
          <button
            class="iconbtn"
            :disabled="replacementBusy"
            @click="closeClaimPreview"
            :aria-label="$root.tr('إغلاق معاينة الطلب')"
          >
            ×
          </button>
        </header>
        <article class="ops-request" v-for="c in [claimPreview]" :key="c.id">
          <div class="claim-preview-fields">
            <div>
              <small>{{ $root.tr("الوكيل") }}</small
              ><b>{{ $root.tr(name(c.agent)) }}</b>
            </div>
            <div>
              <small>{{ $root.tr("الفئة") }}</small
              ><b
                >{{ $root.tr(vm.nameOf('products',c.product||s.batches.find(b=&gt;b.id===c.batch)?.product)) }}</b
              >
            </div>
            <div>
              <small>{{ $root.tr("عدد البطاقات") }}</small
              ><b>{{ $root.tr(c.quantity) }}</b>
            </div>
            <div>
              <small>{{ $root.tr("الحالة") }}</small
              ><span
                class="badge settlement-status"
                :class="vm.claimStatusTone(c.status)"
                >{{ $root.tr(c.status) }}</span
              >
            </div>
            <div>
              <small>{{ $root.tr("رقم الدفعة") }}</small
              ><b>{{ $root.tr(c.batch) }}</b>
            </div>
            <div>
              <small>{{ $root.tr("تاريخ الطلب") }}</small
              ><b>{{ $root.tr(vm.formatTime(c.time)) }}</b>
            </div>
            <div class="claim-reason">
              <small>{{ $root.tr("سبب الطلب") }}</small
              ><b>{{ c.reason || "—" }}</b>
            </div>
            <div v-if="c.status === 'تعويض'">
              <small>{{ $root.tr("المبلغ المعوض · محفظة البطاقات") }}</small
              ><b
                >{{ $root.tr(money(c.compensation)) }}{{ $root.tr(" د.ع") }}</b
              >
            </div>
            <div v-if="c.replacement">
              <small>{{ $root.tr("الدفعة البديلة") }}</small
              ><b>{{ $root.tr(c.replacement) }}</b>
            </div>
          </div>
          <div
            class="actions"
            v-if="c.status==='معلقة'&amp;&amp;can('claims.settle')"
          >
            <button
              class="btn"
              :class="{ primary: vm.settlements[c.id] === 'استبدال' }"
              @click="vm.settlements[c.id] = 'استبدال'"
            >
              {{ $root.tr("استبدال بطاقات") }}</button
            ><button
              class="btn"
              :class="{ primary: vm.settlements[c.id] === 'تعويض' }"
              @click="vm.settlements[c.id] = 'تعويض'"
            >
              {{ $root.tr("تعويض مالي") }}
            </button>
          </div>
          <template
            v-if="c.status==='معلقة'&amp;&amp;can('claims.settle')&amp;&amp;vm.settlements[c.id]==='استبدال'"
            ><label class="btn claim-upload" v-if="can('import.approve')"
              >{{ $root.tr("رفع ملف البطاقات البديلة")
              }}<input
                class="claim-file-input"
                type="file"
                accept=".txt,.csv,.xlsx"
                :disabled="replacementBusy"
                @change="readReplacement(c, $event)"
                :aria-label="$root.tr('رفع ملف البطاقات البديلة')"
            /></label>
            <div
              v-if="replacementPreviews[c.id]"
              class="tablewrap"
              style="max-height: 280px; overflow: auto"
            >
              <table>
                <thead>
                  <tr>
                    <th>{{ $root.tr("السيريال") }}</th>
                    <th>{{ $root.tr("الانتهاء") }}</th>
                    <th>{{ $root.tr("الفحص") }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in replacementPreviews[c.id].checked">
                    <td>{{ r.serial }}</td>
                    <td>{{ $root.tr(r.expiry) }}</td>
                    <td>{{ $root.tr(r.error || "صالح") }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p v-if="replacementErrors[c.id]" class="notice warn" role="alert">
              {{ $root.tr(replacementErrors[c.id]) }}
            </p>
            <button
              class="btn primary"
              :disabled="replacementBusy||!replacementPreviews[c.id]||replacementPreviews[c.id].checked.some(r=&gt;r.error)"
              @click="confirmClaim(c)"
            >
              {{ $root.tr("تأكيد الاستبدال") }}
            </button></template
          >
          <div
            v-if="c.status==='معلقة'&amp;&amp;can('claims.settle')&amp;&amp;vm.settlements[c.id]==='تعويض'"
            class="formfoot"
          >
            <span
              >{{ $root.tr("محفظة البطاقات · ")
              }}{{ $root.tr(name(c.agent)) }}</span
            ><b>{{
              $root.tr(
                refundAmount(c) === null
                  ? "سعر الشراء الأصلي غير موثق"
                  : money(refundAmount(c)) + " د.ع",
              )
            }}</b
            ><button
              class="btn primary"
              :disabled="replacementBusy || refundAmount(c) === null"
              @click="confirmClaim(c)"
            >
              {{ $root.tr("تأكيد التعويض") }}
            </button>
          </div>
        </article>
      </dialog></teleport
    >

    <div v-if="page === 'integrations'" class="card">
      <details>
        <summary>{{ vm.tr("كتالوج منتجات المزود المحلي") }}</summary>
        <textarea
          v-model="catalogText"
          dir="ltr"
          placeholder="TOP5,Top-up 5000,4500,4750"
        ></textarea
        ><button
          v-if="can('integrations.edit')"
          class="btn"
          @click="syncCatalog"
          :disabled="formPending.syncCatalog"
        >
          {{ vm.tr("فحص وحفظ الكتالوج") }}
        </button>
      </details>
      <label
        >{{ vm.tr("إعداد التكامل")
        }}<select v-model="integration">
          <option v-for="i in integrations" :value="i.id">
            {{ vm.tr(name(i.agent)) }} · {{ vm.tr(name(i.provider)) }}
          </option>
        </select></label
      >
      <div class="actions">
        <button
          v-if="can('integrations.edit')"
          class="btn"
          @click="testIntegration"
          :disabled="formPending.testIntegration"
        >
          {{ vm.tr("اختبار الإعداد وتفعيله محليًا") }}</button
        ><button
          v-if="can('integrations.rotate')"
          class="btn"
          @click="rotate"
          :disabled="formPending.rotate"
        >
          {{ vm.tr("تدوير تعريف الرمز") }}
        </button>
      </div>
      <p v-if="integration">
        {{vm.tr(s.integrations.find(i=&gt;i.id===integration)?.tested?'تم اختبار المحاكاة':'بحاجة إلى اختبار')}}
        ·
        {{vm.tr(s.integrations.find(i=&gt;i.id===integration)?.tokenHint||'لا يوجد تعريف رمز')}}
      </p>
      <div class="formgrid">
        <label
          >{{ vm.tr("محفظة الخدمة")
          }}<select v-model="service">
            <option
              v-for="x in services.filter(x=&gt;x.id==='topup'||x.id.startsWith('api:'))"
              :value="x.id"
            >
              {{ vm.tr(x.name) }}
            </option>
          </select></label
        ><label
          >{{ vm.tr("منتج الخدمة")
          }}<select v-model="serviceSKU" @change="selectSKU">
            <option value="">{{ vm.tr("قيمة مخصصة للاختبار") }}</option>
            <option
              v-for="p in (s.integrations.find(i=&gt;i.id===integration)?.products||[])"
              :value="p.code"
            >
              {{ vm.tr(p.name) }}
            </option>
          </select></label
        ><label
          >{{ vm.tr("رقم المستفيد") }}<input v-model="apiRecipient" /></label
        ><label
          >{{ vm.tr("تكلفة الجملة")
          }}<input
            type="text"
            inputmode="decimal"
            v-money=""
            v-model.number="apiCost" /></label
        ><label
          >{{ vm.tr("سعر البيع")
          }}<input
            type="text"
            inputmode="decimal"
            v-money=""
            v-model.number="apiPrice"
        /></label>
      </div>
      <div class="actions">
        <button
          v-if="can('integrations.transact')"
          class="btn primary"
          @click="serviceOrder"
          :disabled="formPending.serviceOrder"
        >
          {{ vm.tr("إنشاء / إعادة إرسال نفس الطلب") }}</button
        ><button class="btn" @click="apiKey = vm.newWorkflowKey()">
          {{ vm.tr("طلب جديد") }}
        </button>
      </div>
      <article v-for="o in orders" class="ops-request">
        <b
          >{{ vm.tr(o.id) }} · {{ vm.tr(o.recipient) }} ·
          {{ vm.tr(money(o.price)) }}{{ vm.tr("د.ع") }}</b
        ><span class="badge">{{ vm.tr(o.status) }}</span>
        <div
          v-if="['قيد المعالجة','غير معروف'].includes(o.status)&amp;&amp;can('integrations.transact')"
          class="actions"
        >
          <button
            class="btn"
            @click="resolve(o, 'ناجح')"
            :disabled="formPending.resolve"
          >
            {{ vm.tr("محاكاة تأكيد النجاح") }}</button
          ><button
            class="btn danger"
            @click="resolve(o, 'فاشل')"
            :disabled="formPending.resolve"
          >
            {{ vm.tr("محاكاة الفشل واسترجاع الحجز") }}</button
          ><button
            class="btn"
            @click="resolve(o, 'غير معروف')"
            :disabled="formPending.resolve"
          >
            {{ vm.tr("نتيجة غير معروفة") }}
          </button>
        </div>
      </article>
    </div>
    <div v-if="page === 'monitoring'" class="card">
      <div class="ops-stats">
        <div>
          <span>{{ vm.tr("طلبات مزود غير محسومة") }}</span
          ><strong
            >{{vm.tr(orders.filter(o=&gt;['قيد المعالجة','غير معروف'].includes(o.status)).length)}}</strong
          >
        </div>
        <div>
          <span>{{ vm.tr("طلبات تمويل معلقة") }}</span
          ><strong
            >{{vm.tr(requests.filter(r=&gt;r.status==='بانتظار التمويل').length)}}</strong
          >
        </div>
        <div>
          <span>{{ vm.tr("حجوزات لم تصدر") }}</span
          ><strong
            >{{vm.tr(reservations.filter(r=&gt;r.status==='محجوز').length)}}</strong
          >
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["operations-panel", "simple-wallets"]);
export default options;
</script>

<template>
  <section class="simple-wallets">
    <div class="card wallet-funding-content">
      <div class="wallet-funding-body">
        <p v-if="tab === 'request' || tab === 'direct'" class="caption">
          {{ $root.tr("محفظة التمويل: ")
          }}{{ $root.tr(kind ? service(kind) : "اختر محفظة من كروت المحافظ") }}
        </p>
        <div class="tabs">
          <button
            v-for="t in tabs"
            :class="{ active: tab === t.id }"
            @click="
              tab = t.id;
              error = '';
              directConfirm = false;
            "
          >
            {{ $root.tr(t.name)
            }}<span
              v-if="t.id==='incoming'&amp;&amp;incoming.length"
              class="badge"
              >{{ $root.tr(incoming.length) }}</span
            >
          </button>
        </div>
        <form v-if="tab === 'request'" @submit.prevent="request">
          <div class="formgrid funding-request-fields">
            <label
              >{{ $root.tr("إلى الجهة الأعلى")
              }}<input :value="name(parent)" readonly="" /></label
            ><label v-if="pointRequester"
              >{{ $root.tr("المبلغ المطلوب")
              }}<select
                v-model.number="value"
                required=""
                :aria-label="$root.tr('مبلغ طلب التمويل')"
              >
                <option value="" disabled="">
                  {{ $root.tr("اختر مبلغ التمويل") }}
                </option>
                <option v-for="n in requestPolicy.amounts" :key="n" :value="n">
                  {{ $root.tr(vm.money(n)) }}{{ $root.tr(" د.ع") }}
                </option>
              </select></label
            ><label v-else=""
              >{{ $root.tr("المبلغ المطلوب")
              }}<input
                type="number"
                min="0.01"
                step="0.01"
                v-model="value"
                required="" /></label
            ><label
              >{{ $root.tr("ملاحظة اختيارية") }}<input v-model="note"
            /></label>
          </div>
          <p v-if="parent==='@owner'&amp;&amp;kind==='voucher'" class="help">
            {{
              $root.tr("رصيد البطاقات يجهّز بطلبية مخزون معتمدة بنفس القيمة.")
            }}
          </p>
          <div v-if="pointRequester" class="funding-daily-status">
            <span
              >{{ $root.tr("طلبات اليوم: ") }}{{ $root.tr(requestsToday) }} /
              {{ $root.tr(requestPolicy.dailyLimit) }}</span
            ><span
              >{{ $root.tr("المتبقي اليوم: ")
              }}{{ $root.tr(requestRemaining) }}</span
            >
          </div>
          <p
            v-if="pointRequester&amp;&amp;!requestPolicy.amounts.length"
            class="notice warn"
          >
            {{
              $root.tr(
                "طلبات التمويل غير متاحة حاليًا؛ لم تعتمد الإدارة مبالغ للطلبات.",
              )
            }}
          </p>
          <p
            v-else-if="pointRequester&amp;&amp;!requestRemaining"
            class="notice warn"
          >
            {{
              $root.tr(
                "وصلت إلى عدد الطلبات المسموح اليوم؛ يمكنك الطلب غدًا بتوقيت بغداد.",
              )
            }}
          </p>
          <div class="actions">
            <button
              class="btn primary"
              :disabled="busy||!(Number(value)&gt;0)||(pointRequester&amp;&amp;(!requestRemaining||!requestPolicy.amounts.includes(Number(value))))"
            >
              {{ $root.tr("إرسال طلب التمويل") }}
            </button>
          </div>
        </form>
        <form v-if="tab === 'direct'" @submit.prevent="direct">
          <div class="formgrid">
            <label
              >{{ $root.tr("المستفيد")
              }}<select
                v-model="to"
                required=""
                :aria-label="$root.tr('المستفيد')"
              >
                <option value="">{{ $root.tr("اختر تابعًا") }}</option>
                <option v-for="a in children" :value="a.id">
                  {{ $root.tr(a.name) }}
                </option>
              </select></label
            ><label
              >{{ $root.tr("مبلغ التمويل")
              }}<input
                type="number"
                min="0.01"
                step="0.01"
                :max="available"
                v-model="value"
                required=""
            /></label>
          </div>
          <p>
            {{ $root.tr("المتاح: ") }}{{ $root.tr(vm.money(available))
            }}{{ $root.tr(" د.ع · المتبقي بعد التحويل: ")
            }}{{ $root.tr(vm.money(available - Number(value || 0)))
            }}{{ $root.tr(" د.ع") }}
          </p>
          <p v-if="directConfirm" class="notice">
            {{ $root.tr("تأكيد تحويل ") }}{{ $root.tr(vm.money(value))
            }}{{ $root.tr(" د.ع إلى ") }}{{ $root.tr(name(to))
            }}{{ $root.tr(" من ") }}{{ $root.tr(service(kind)) }}.
          </p>
          <button
            class="btn primary"
            :disabled="busy||!to||!(Number(value)&gt;0)||Number(value)&gt;available"
          >
            {{ $root.tr(directConfirm ? "تأكيد التمويل" : "تمويل") }}
          </button>
        </form>
        <div v-if="tab === 'incoming'">
          <article v-for="r in incoming" :key="r.id" class="wallet-request">
            <div class="rowline">
              <strong>{{ $root.tr(name(r.to)) }}</strong
              ><strong
                >{{ $root.tr(vm.money(r.amount)) }}{{ $root.tr(" د.ع · ")
                }}{{ $root.tr(service(r.service)) }}</strong
              ><span class="badge">{{ $root.tr(status(r)) }}</span>
            </div>
            <p v-if="r.purpose">{{ $root.tr(r.purpose) }}</p>
            <small
              >{{ $root.tr(vm.formatTime(r.time)) }} ·
              {{ $root.tr(r.id) }}</small
            >
            <div v-if="admin&amp;&amp;r.service==='voucher'" class="notice">
              {{
                $root.tr(
                  "الرصيد يُضاف عند اعتماد الطلبية. ربطها هنا لا يضيف رصيدًا ثانيًا.",
                )
              }}<button
                v-if="vm.can('import.view')"
                class="btn small"
                @click="loadOrder(r)"
              >
                {{ $root.tr("تحميل طلبية") }}
              </button>
            </div>
            <div v-if="!admin" class="caption">
              {{ $root.tr("رصيدك المتاح لهذه المحفظة: ")
              }}{{ $root.tr(vm.money(e.serviceAvailable(me, r.service)))
              }}{{ $root.tr(" د.ع") }}
            </div>
            <button v-if="!review[r.id]" class="btn" @click="edit(r)">
              {{ $root.tr("مراجعة الطلب") }}
            </button>
            <div v-else="">
              <label v-if="admin&amp;&amp;r.service==='voucher'"
                >{{ $root.tr("الطلبية المعتمدة")
                }}<select v-model="review[r.id].batch">
                  <option value="">
                    {{ $root.tr("اختر طلبية بنفس القيمة") }}
                  </option>
                  <option v-for="b in batches(r)" :value="b.id">
                    {{ $root.tr(vm.batchLabel(b)) }}
                  </option>
                </select></label
              ><label v-if="admin&amp;&amp;r.service!=='voucher'"
                >{{ $root.tr("مرجع الإيداع")
                }}<input
                  v-model="review[r.id].reference"
                  :placeholder="$root.tr('رقم الإيداع أو السند')"
              /></label>
              <div class="actions">
                <button
                  class="btn primary"
                  :disabled="busy||(admin&amp;&amp;(r.service==='voucher'?!review[r.id].batch:!review[r.id].reference.trim()))"
                  @click="decide(r, 'approve')"
                >
                  {{ $root.tr(admin&amp;&amp;r.service==='voucher'?'اعتماد وربط الطلبية':'موافقة وتمويل') }}
                </button>
              </div>
              <label
                >{{ $root.tr("سبب الرفض")
                }}<input v-model="review[r.id].reason" /></label
              ><button
                class="btn danger"
                :disabled="
                  busy ||
                  !review[r.id].reason.trim() ||
                  r.status === 'معتمد ومحجوز'
                "
                @click="decide(r, 'reject')"
              >
                {{ $root.tr("رفض الطلب") }}
              </button>
            </div>
          </article>
          <p v-if="!incoming.length" class="empty">
            {{ $root.tr("لا توجد طلبات واردة بانتظار الإجراء") }}
          </p>
        </div>
        <div v-if="tab === 'history'">
          <label
            >{{ $root.tr("حالة الطلب")
            }}<select v-model="filter">
              <option value="all">{{ $root.tr("كل الحالات") }}</option>
              <option
                v-for="s in [
                  'بانتظار الموافقة',
                  'بانتظار التسليم',
                  'منفذ',
                  'مرفوض',
                  'ملغي',
                ]"
                :value="s"
              >
                {{ $root.tr(s) }}
              </option>
            </select></label
          >
          <div class="tablewrap">
            <table>
              <thead>
                <tr>
                  <th>{{ $root.tr("التاريخ") }}</th>
                  <th>{{ $root.tr("الجهة الأعلى / الممول") }}</th>
                  <th>{{ $root.tr("المستفيد") }}</th>
                  <th>{{ $root.tr("المحفظة") }}</th>
                  <th>{{ $root.tr("المبلغ") }}</th>
                  <th>{{ $root.tr("الحالة") }}</th>
                  <th>{{ $root.tr("التفاصيل") }}</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="r in history" :key="r.id">
                  <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
                  <td>{{ $root.tr(name(r.from)) }}</td>
                  <td>{{ $root.tr(name(r.to)) }}</td>
                  <td>{{ $root.tr(service(r.service)) }}</td>
                  <td>
                    {{ $root.tr(vm.money(r.approvedAmount ?? r.amount)) }}
                  </td>
                  <td>
                    <span class="badge">{{ $root.tr(status(r)) }}</span>
                  </td>
                  <td>
                    <button
                      type="button"
                      class="btn small"
                      :aria-expanded="detailId === r.id"
                      @click="detailId = detailId === r.id ? '' : r.id"
                    >
                      {{ $root.tr("التفاصيل") }}</button
                    ><button
                      v-if="r.recordType==='request'&amp;&amp;r.to===me&amp;&amp;r.status==='بانتظار التمويل'&amp;&amp;vm.can('wallets.request')"
                      class="btn small"
                      :disabled="busy"
                      @click="cancel(r)"
                    >
                      {{ $root.tr("إلغاء الطلب") }}
                    </button>
                  </td>
                </tr>
                <tr v-if="!history.length">
                  <td colspan="7" class="empty">
                    {{ $root.tr("لا توجد عمليات") }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <section v-if="fundingDetail" class="funding-record-detail">
            <div class="funding-settings-heading">
              <h4>
                {{ $root.tr("تفاصيل طلب التمويل · ")
                }}{{ $root.tr(fundingDetail.id) }}
              </h4>
              <button type="button" class="btn small" @click="detailId = ''">
                {{ $root.tr("إغلاق التفاصيل") }}
              </button>
            </div>
            <dl class="account-fields">
              <div>
                <dt>{{ $root.tr("المستفيد") }}</dt>
                <dd>{{ $root.tr(name(fundingDetail.to)) }}</dd>
              </div>
              <div>
                <dt>{{ $root.tr("الجهة الممولة") }}</dt>
                <dd>{{ $root.tr(name(fundingDetail.from)) }}</dd>
              </div>
              <div>
                <dt>{{ $root.tr("المبلغ المطلوب") }}</dt>
                <dd>
                  {{ $root.tr(vm.money(fundingDetail.amount))
                  }}{{ $root.tr(" د.ع") }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("المبلغ المنفذ") }}</dt>
                <dd>
                  {{
                    $root.tr(
                      fundingDetail.status === "منفذ"
                        ? vm.money(
                            fundingDetail.approvedAmount ??
                              fundingDetail.amount,
                          ) + " د.ع"
                        : "—",
                    )
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("وقت الطلب") }}</dt>
                <dd>{{ $root.tr(vm.formatTime(fundingDetail.time)) }}</dd>
              </div>
              <div>
                <dt>{{ $root.tr("مقدم الطلب") }}</dt>
                <dd>
                  {{
                    $root.tr(
                      requestUser(
                        fundingDetail.user,
                        fundingDetail.requesterName,
                      ),
                    )
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("الحالة") }}</dt>
                <dd>{{ $root.tr(status(fundingDetail)) }}</dd>
              </div>
              <div>
                <dt>{{ $root.tr("وقت الموافقة أو الرفض") }}</dt>
                <dd>
                  {{
                    $root.tr(
                      fundingDetail.reviewedAt || fundingDetail.approvedAt
                        ? vm.formatTime(
                            fundingDetail.reviewedAt ||
                              fundingDetail.approvedAt,
                          )
                        : "—",
                    )
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("منفذ الإجراء") }}</dt>
                <dd>
                  {{
                    $root.tr(
                      requestUser(
                        fundingDetail.approverId,
                        fundingDetail.approverName || fundingDetail.approver,
                      ),
                    )
                  }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("المحفظة") }}</dt>
                <dd>{{ $root.tr(service(fundingDetail.service)) }}</dd>
              </div>
              <div>
                <dt>{{ $root.tr("سبب الرفض أو الملاحظة") }}</dt>
                <dd>
                  {{ fundingDetail.reason || fundingDetail.purpose || "—" }}
                </dd>
              </div>
              <div>
                <dt>{{ $root.tr("مرجع التنفيذ") }}</dt>
                <dd>
                  {{
                    $root.tr(
                      fundingDetail.transfer ||
                        fundingDetail.batch ||
                        fundingDetail.reference ||
                        "—",
                    )
                  }}
                </dd>
              </div>
              <div v-if="fundingDetail.cancelledAt">
                <dt>{{ $root.tr("الإلغاء") }}</dt>
                <dd>
                  {{ $root.tr(vm.formatTime(fundingDetail.cancelledAt)) }} ·
                  {{
                    $root.tr(
                      requestUser(
                        fundingDetail.cancelledBy,
                        fundingDetail.cancelledByName,
                      ),
                    )
                  }}
                </dd>
              </div>
            </dl>
          </section>
        </div>
        <p v-if="error" class="notice warn" role="alert">
          {{ $root.tr(error) }}
        </p>
      </div>
    </div>
  </section>
</template>

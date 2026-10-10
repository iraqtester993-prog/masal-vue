<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["account-time-settings"]);
export default options;
</script>

<template>
  <section
    v-if="vm.page==='accountTime'&amp;&amp;vm.actor.role==='owner'"
    class="card account-time-settings"
  >
    <div class="account-time-heading">
      <h3>{{ $root.tr("أوقات صلاحية الحساب") }}</h3>
      <span class="badge">{{ $root.tr("توقيت بغداد") }}</span>
    </div>
    <div class="account-time-toolbar">
      <label
        >{{ $root.tr("بحث المستخدمين")
        }}<input
          v-model="query"
          :placeholder="$root.tr('ابحث بالاسم أو اسم الدخول')"
          :aria-label="$root.tr('بحث مستخدمي الأوقات')" /></label
      ><label
        >{{ $root.tr("المستخدم")
        }}<select v-model="userId" :aria-label="$root.tr('مستخدم ضوابط الوقت')">
          <option value="">{{ $root.tr("اختر المستخدم") }}</option>
          <option v-for="u in users" :key="u.id" :value="u.id">
            {{ $root.tr(u.name) }} · {{ u.username || u.id }}
          </option>
        </select></label
      ><label v-if="userId" class="account-time-switch account-time-master"
        ><input
          type="checkbox"
          v-model="draft.enabled"
          :aria-label="$root.tr('تفعيل ضوابط وقت الحساب')"
        /><span>{{ $root.tr("تفعيل ضوابط وقت الحساب") }}</span></label
      >
    </div>
    <template v-if="userId"
      ><fieldset class="account-time-grid" :disabled="!draft.enabled">
        <section
          class="account-time-rule"
          :class="{enabled:draft.enabled&amp;&amp;draft.dateEnabled}"
        >
          <label class="account-time-switch"
            ><input
              type="checkbox"
              v-model="draft.dateEnabled"
              :aria-label="$root.tr('تفعيل مدة الصلاحية')"
            /><span
              >{{ $root.tr("مدة صلاحية الحساب")
              }}<small>{{
                $root.tr("فترة محددة بتاريخ بداية ونهاية")
              }}</small></span
            ></label
          >
          <div v-if="draft.dateEnabled" class="account-time-pair">
            <label
              >{{ $root.tr("تاريخ ووقت البداية — بغداد")
              }}<input type="datetime-local" v-model="draft.startAt" /></label
            ><label
              >{{ $root.tr("تاريخ ووقت النهاية — بغداد")
              }}<input type="datetime-local" v-model="draft.endAt"
            /></label>
          </div>
          <p v-else="" class="account-time-hint">
            {{ $root.tr("بدون تقييد بتاريخ") }}
          </p>
        </section>
        <section
          class="account-time-rule"
          :class="{enabled:draft.enabled&amp;&amp;draft.hoursEnabled}"
        >
          <label class="account-time-switch"
            ><input
              type="checkbox"
              v-model="draft.hoursEnabled"
              :aria-label="$root.tr('تفعيل ساعات الدوام')"
            /><span
              >{{ $root.tr("ساعات الدوام")
              }}<small>{{ $root.tr("وقت دخول يومي مسموح") }}</small></span
            ></label
          >
          <div v-if="draft.hoursEnabled" class="account-time-pair">
            <label
              >{{ $root.tr("بدء الدوام — بغداد")
              }}<input type="time" v-model="draft.startTime" /></label
            ><label
              >{{ $root.tr("انتهاء الدوام — بغداد")
              }}<input type="time" v-model="draft.endTime"
            /></label>
          </div>
          <p v-else="" class="account-time-hint">
            {{ $root.tr("بدون تقييد بساعات دوام") }}
          </p>
        </section>
        <section
          class="account-time-rule"
          :class="{enabled:draft.enabled&amp;&amp;draft.idleEnabled}"
        >
          <label class="account-time-switch"
            ><input
              type="checkbox"
              v-model="draft.idleEnabled"
              :aria-label="$root.tr('تفعيل مهلة الخمول')"
            /><span
              >{{ $root.tr("الخروج عند الخمول")
              }}<small>{{
                $root.tr("يُحسب عند عدم استخدام الحساب")
              }}</small></span
            ></label
          >
          <div v-if="draft.idleEnabled" class="account-time-duration">
            <label
              >{{ $root.tr("مهلة الخمول بالدقائق")
              }}<input
                type="number"
                min="1"
                step="1"
                v-model.number="draft.idleMinutes"
            /></label>
            <div
              class="account-time-presets"
              role="group"
              :aria-label="$root.tr('اختصارات مهلة الخمول')"
            >
              <button
                v-for="n in [15, 30, 60, 120]"
                type="button"
                :class="{ active: Number(draft.idleMinutes) === n }"
                :aria-pressed="Number(draft.idleMinutes) === n"
                @click="draft.idleMinutes = n"
              >
                {{ $root.tr(n) }}{{ $root.tr(" دقيقة") }}
              </button>
            </div>
          </div>
          <p v-else="" class="account-time-hint">
            {{ $root.tr("الخروج بسبب الخمول غير مفعّل") }}
          </p>
        </section>
        <section
          class="account-time-rule"
          :class="{enabled:draft.enabled&amp;&amp;draft.sessionEnabled}"
        >
          <label class="account-time-switch"
            ><input
              type="checkbox"
              v-model="draft.sessionEnabled"
              :aria-label="$root.tr('تفعيل مدة الجلسة')"
            /><span
              >{{ $root.tr("مدة الجلسة الإجبارية")
              }}<small>{{
                $root.tr("خروج دوري حتى أثناء الاستخدام")
              }}</small></span
            ></label
          >
          <div v-if="draft.sessionEnabled" class="account-time-duration">
            <label
              >{{ $root.tr("مدة الجلسة بالدقائق")
              }}<input
                type="number"
                min="1"
                step="1"
                v-model.number="draft.sessionMinutes"
            /></label>
            <div
              class="account-time-presets"
              role="group"
              :aria-label="$root.tr('اختصارات مدة الجلسة')"
            >
              <button
                v-for="n in [30, 60, 120, 240]"
                type="button"
                :class="{ active: Number(draft.sessionMinutes) === n }"
                :aria-pressed="Number(draft.sessionMinutes) === n"
                @click="draft.sessionMinutes = n"
              >
                {{ $root.tr(n) }}{{ $root.tr(" دقيقة") }}
              </button>
            </div>
          </div>
          <p v-else="" class="account-time-hint">
            {{ $root.tr("الخروج الدوري غير مفعّل") }}
          </p>
        </section>
      </fieldset>
      <div class="account-time-save">
        <span>{{
          $root.tr(
            draft.enabled
              ? "تُطبّق الخيارات المفعّلة فقط"
              : "ضوابط وقت هذا الحساب معطّلة",
          )
        }}</span
        ><button class="btn primary" @click="save">
          {{ $root.tr("حفظ أوقات الحساب") }}
        </button>
      </div></template
    >
    <p v-else="" class="empty">
      {{ $root.tr("اختر مستخدمًا لضبط أوقات حسابه") }}
    </p>
    <div class="toolbar account-time-saved-heading">
      <h3>{{ $root.tr("الإعدادات المحفوظة") }}</h3>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("المستخدم") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("مدة الصلاحية") }}</th>
            <th>{{ $root.tr("الدوام — بغداد") }}</th>
            <th>{{ $root.tr("الخمول / الجلسة بالدقائق") }}</th>
            <th>{{ $root.tr("الإجراء") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="u in saved">
            <td>{{ $root.tr(u.name) }}</td>
            <td>{{ $root.tr(u.timePolicy.enabled ? "مفعّل" : "معطّل") }}</td>
            <td>
              {{
                $root.tr(
                  u.timePolicy.dateEnabled
                    ? u.timePolicy.startAt + " — " + u.timePolicy.endAt
                    : "—",
                )
              }}
            </td>
            <td>
              {{
                $root.tr(
                  u.timePolicy.hoursEnabled
                    ? u.timePolicy.startTime + " — " + u.timePolicy.endTime
                    : "—",
                )
              }}
            </td>
            <td>
              {{
                $root.tr(
                  u.timePolicy.idleEnabled ? u.timePolicy.idleMinutes : "—",
                )
              }}
              /
              {{
                $root.tr(
                  u.timePolicy.sessionEnabled
                    ? u.timePolicy.sessionMinutes
                    : "—",
                )
              }}
            </td>
            <td>
              <button class="btn small" @click="edit(u)">
                {{ $root.tr("تعديل") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

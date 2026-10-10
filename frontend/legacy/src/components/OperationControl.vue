<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["operation-control"]);
export default options;
</script>

<template>
  <section v-if="vm.page === 'security'" class="security-center">
    <div class="card">
      <div class="cardhead">
        <div>
          <h3>{{ $root.tr("التحكم بالتوقيف") }}</h3>
        </div>
        <span class="badge"
          >{{ $root.tr(disabledAccounts.length)
          }}{{ $root.tr(" حساب مقيّد") }}</span
        >
      </div>
      <div v-if="allowed">
        <label class="security-scope-select"
          >{{ $root.tr("نطاق التوقيف")
          }}<select v-model="scope" :aria-label="$root.tr('نطاق التوقيف')">
            <option v-for="s in scopes" :value="s.id">
              {{ $root.tr(s.label) }}
            </option>
          </select></label
        >
        <details v-if="scope === 'custom'" class="security-account-picker">
          <summary>
            {{
              $root.tr(
                selected.length
                  ? "الحسابات المحددة: " + selected.length
                  : "اختر الحسابات",
              )
            }}<span aria-hidden="true">⌄</span>
          </summary>
          <div class="security-account-menu">
            <input
              v-model="query"
              :placeholder="$root.tr('بحث باسم الحساب')"
              :aria-label="$root.tr('بحث الحسابات')"
            />
            <div class="security-account-options">
              <label v-for="a in choices" :key="a.id"
                ><input type="checkbox" :value="a.id" v-model="selected" />{{
                  $root.tr(a.name)
                }}
                <small>{{ $root.tr(label(a.kind)) }}</small></label
              >
              <p v-if="!choices.length" class="caption">
                {{ $root.tr("لا توجد نتائج") }}
              </p>
            </div>
          </div>
        </details>
        <h4>{{ $root.tr("خيارات التوقيف") }}</h4>
        <div class="security-action-grid">
          <label
            v-for="f in fields"
            :class="{ selected: actions.includes(f.key) }"
            ><input
              type="checkbox"
              v-model="actions"
              :value="f.key"
              :aria-label="$root.tr(f.label)"
            /><span>{{ $root.tr(f.label) }}</span></label
          >
        </div>
        <label class="security-reason"
          >{{ $root.tr("سبب التوقيف") }}<input v-model="reason" maxlength="300"
        /></label>
        <div class="security-apply">
          <span class="badge"
            >{{ $root.tr(preview) }}{{ $root.tr(" حساب") }}</span
          ><button
            class="btn danger"
            :disabled="!actions.length||!reason.trim()||scope==='custom'&amp;&amp;!selected.length"
            @click="apply"
          >
            {{ $root.tr("تطبيق التوقيف") }}
          </button>
        </div>
      </div>
      <span v-else="" class="badge">{{ $root.tr("عرض فقط") }}</span>
    </div>
    <div
      v-if="['app','login','sales','printing'].some(k=&gt;vm.s.settings[k]===false)"
      class="card security-legacy"
    >
      <b>{{ $root.tr("يوجد توقيف عام سابق") }}</b
      ><button v-if="allowed" class="btn" @click="restoreGlobal">
        {{ $root.tr("إلغاء التوقيف العام السابق") }}
      </button>
    </div>
    <div class="card">
      <div class="cardhead">
        <h3>{{ $root.tr("قرارات التوقيف الفعّالة") }}</h3>
        <span class="badge">{{ $root.tr(active.length) }}</span>
      </div>
      <p v-if="!active.length" class="empty">
        {{ $root.tr("لا توجد قرارات توقيف جديدة") }}
      </p>
      <article v-for="r in active" class="security-rule">
        <div>
          <b
            >{{ $root.tr(label(r.scope)) }}
            <span class="badge warn"
              >{{ $root.tr(count(r)) }}{{ $root.tr(" حساب") }}</span
            ></b
          >
          <p>{{ r.reason }}</p>
          <div class="actions">
            <span class="badge" v-for="a in r.actions">{{
              $root.tr(action(a))
            }}</span>
          </div>
          <small>{{ $root.tr(vm.formatTime(r.time)) }}</small>
        </div>
        <button v-if="allowed" class="btn" @click="resume(r)">
          {{ $root.tr("إلغاء هذا التوقيف") }}
        </button>
      </article>
    </div>
    <div class="card">
      <div class="cardhead">
        <h3>{{ $root.tr("الحسابات المعطّلة أو المقيّدة") }}</h3>
        <input
          v-model="filter"
          :placeholder="$root.tr('بحث الحسابات المعطّلة')"
          :aria-label="$root.tr('بحث الحسابات المعطّلة')"
        />
      </div>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ $root.tr("الحساب") }}</th>
              <th>{{ $root.tr("النوع") }}</th>
              <th>{{ $root.tr("الإيقاف الفعلي") }}</th>
              <th>{{ $root.tr("الإجراء") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="a in rows">
              <td>{{ $root.tr(a.name) }}</td>
              <td>{{ $root.tr(label(a.kind)) }}</td>
              <td>
                <div class="actions">
                  <span class="badge warn" v-for="s in a.stops">{{
                    $root.tr(s.label)
                  }}</span>
                </div>
              </td>
              <td>
                <button
                  v-if="allowed&amp;&amp;legacy(a)"
                  class="btn small"
                  @click="clearLegacy(a)"
                >
                  {{ $root.tr("إلغاء التوقيف المباشر السابق") }}</button
                ><span v-else="">—</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="!disabledAccounts.length" class="empty">
        {{ $root.tr("لا توجد حسابات مقيّدة") }}
      </p>
      <div class="actions">
        <button class="btn small" :disabled="page&lt;=1" @click="page--">
          {{ $root.tr("السابق") }}</button
        ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
        ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
          {{ $root.tr("التالي") }}
        </button>
      </div>
    </div>
  </section>
</template>

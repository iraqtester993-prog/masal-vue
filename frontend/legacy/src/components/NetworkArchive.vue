<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["network-archive"]);
export default options;
</script>

<template>
  <section class="card">
    <div class="cardhead">
      <h2>{{ $root.tr("أرشيف المحذوفات") }}</h2>
      <span class="badge">{{ $root.tr(rows.length) }}</span>
    </div>
    <input
      type="search"
      v-model="query"
      :placeholder="$root.tr('بحث بالاسم أو إيميل الدخول')"
      :aria-label="$root.tr('بحث المحذوفات')"
    />
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("الحساب") }}</th>
            <th>{{ $root.tr("النوع") }}</th>
            <th>{{ $root.tr("تاريخ الأرشفة") }}</th>
            <th>{{ $root.tr("المنفّذ") }}</th>
            <th>{{ $root.tr("الإجراء") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in shown" :key="r.id">
            <td>{{ $root.tr(r.name) }}</td>
            <td>
              {{
                $root.tr(
                  r.kind === "pos"
                    ? "نقطة بيع"
                    : r.before.type === "رئيسي"
                      ? "وكيل رئيسي"
                      : "وكيل فرعي",
                )
              }}
            </td>
            <td>{{ $root.tr(vm.formatTime(r.time)) }}</td>
            <td>{{ $root.tr(vm.nameOf("users", r.user)) }}</td>
            <td>
              <button class="btn small" @click="selected = r.id">
                {{ $root.tr("معاينة") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="!rows.length" class="empty">
      {{ $root.tr("لا توجد حسابات مؤرشفة") }}
    </p>
    <div v-if="pages&gt;1" class="actions">
      <button class="btn" :disabled="page&lt;=1" @click="page--">
        {{ $root.tr("السابق") }}</button
      ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
      ><button class="btn" :disabled="page&gt;=pages" @click="page++">
        {{ $root.tr("التالي") }}
      </button>
    </div>
    <section v-if="record" class="card" style="margin-top: 18px">
      <div class="cardhead">
        <h3>{{ $root.tr(record.name) }}</h3>
        <button class="btn small" @click="selected = ''">
          {{ $root.tr("إغلاق المعاينة") }}
        </button>
      </div>
      <img
        v-if="record.before.image"
        :src="record.before.image"
        style="max-width: 120px; max-height: 120px"
        :alt="$root.tr('صورة الحساب المؤرشف')"
      />
      <dl class="account-fields">
        <div>
          <dt>{{ $root.tr("معرّف الحساب") }}</dt>
          <dd>{{ $root.tr(record.entity) }}</dd>
        </div>
        <div>
          <dt>{{ $root.tr("الجهة الأعلى") }}</dt>
          <dd>
            {{
              $root.tr(
                vm.accountName(record.before.parent || record.before.agent) ||
                  "—",
              )
            }}
          </dd>
        </div>
        <div>
          <dt>{{ $root.tr("المحافظة") }}</dt>
          <dd>{{ $root.tr(record.before.city || "—") }}</dd>
        </div>
        <div>
          <dt>{{ $root.tr("الهاتف") }}</dt>
          <dd>{{ $root.tr(record.before.phone || "—") }}</dd>
        </div>
        <div>
          <dt>{{ $root.tr("العنوان") }}</dt>
          <dd>{{ $root.tr(record.before.address || "—") }}</dd>
        </div>
        <div>
          <dt>{{ $root.tr("سبب الأرشفة") }}</dt>
          <dd>{{ record.reason }}</dd>
        </div>
        <div v-for="u in record.users" :key="u.id">
          <dt>{{ $root.tr(u.name) }}{{ $root.tr(" — إيميل الدخول") }}</dt>
          <dd>{{ u.email || "—" }}</dd>
        </div>
      </dl>
      <div class="actions">
        <span class="badge"
          >{{ $root.tr("العمليات السابقة: ")
          }}{{ $root.tr(vm.s.sales.filter(t=&gt;t.pos===record.entity||t.agent===record.entity).length) }}</span
        ><span class="badge"
          >{{ $root.tr("القيود المالية: ")
          }}{{ $root.tr(vm.s.serviceLedger.filter(l=&gt;l.account===record.entity).length) }}</span
        >
      </div>
    </section>
  </section>
</template>

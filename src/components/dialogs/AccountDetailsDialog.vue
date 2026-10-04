<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="account-details">
    <dl class="account-fields">
      <div v-for="f in accountDetails.fields">
        <dt>{{ tr(f.key) }}</dt>
        <dd>{{ $root.tr(f.value || "—") }}</dd>
      </div>
      <div>
        <dt>{{ tr("كلمة المرور") }}</dt>
        <dd>
          {{ tr(accountDetails.user.credentials ? "محددة" : "غير محددة") }}
        </dd>
      </div>
    </dl>
    <h3>{{ tr("نطاق بيانات الحساب") }}</h3>
    <p v-if="!accountDetails.user.active" class="notice">
      {{ tr("الحساب موقوف؛ يظهر أدناه نطاقه المسند عند التفعيل.") }}
    </p>
    <p v-if="accountDetails.user.role === 'owner'">
      {{ tr("جميع بيانات النظام وجميع الفروع") }}
    </p>
    <div class="account-links">
      <span v-for="a in accountDetails.agents" class="badge"
        >{{ $root.tr(a.name) }} · {{ tr(a.active ? "مفعل" : "موقوف") }}</span
      >
    </div>
    <p v-if="!accountDetails.agents.length">
      {{ tr("لا يوجد نطاق وكلاء مسند") }}
    </p>
    <template v-if="accountDetails.user.role !== 'owner'"
      ><h3>{{ tr("نقاط البيع والأجهزة ضمن النطاق") }}</h3>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th>{{ tr("الاسم") }}</th>
              <th>{{ tr("الوكيل") }}</th>
              <th>{{ tr("الجهاز") }}</th>
              <th>{{ tr("الحالة") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="p in accountDetails.pos">
              <td>{{ $root.tr(p.name) }}</td>
              <td>{{ $root.tr(nameOf("agents", p.agent)) }}</td>
              <td>{{ p.serial }} · {{ $root.tr(p.model) }}</td>
              <td>{{ tr(p.active ? "مفعل" : "موقوف") }}</td>
            </tr>
          </tbody>
        </table>
      </div></template
    ><template v-if="accountDetails.user.role === 'owner'"
      ><h3>{{ tr("الصلاحيات") }}</h3>
      <span class="badge">{{ tr("صلاحيات كاملة") }}</span></template
    ><template v-else=""
      ><h3>
        {{ tr("الصلاحيات الفعلية") }} ·
        {{ $root.tr(accountDetails.permissions.length) }}
      </h3>
      <details>
        <summary>{{ tr("عرض جميع الصلاحيات") }}</summary>
        <div class="account-links">
          <span class="badge" v-for="p in accountDetails.permissions"
            >{{ tr(p.group) }} · {{ tr(p.label) }}</span
          >
        </div>
      </details></template
    >
    <div class="formfoot">
      <button class="btn" @click="closeModal">{{ tr("إغلاق") }}</button
      ><button class="btn primary" @click="openEdit(accountDetails.user)">
        {{
          tr(
            accountDetails.user.role === "owner"
              ? "تعديل الاسم"
              : "تعديل بيانات الحساب",
          )
        }}
      </button>
    </div>
  </div>
</template>

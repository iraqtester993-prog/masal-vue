<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["network-points-table"]);
export default options;
</script>

<template>
  <section class="pos-register">
    <div class="toolbar pos-unified-toolbar">
      <page-actions v-if="vm.page === 'pos'"></page-actions
      ><input
        v-model="vm.search"
        type="search"
        :placeholder="$root.tr('بحث بالاسم أو الهاتف أو المندوب')"
        :aria-label="$root.tr('بحث نقاط البيع')"
      /><span>{{ $root.tr(rows.length) }}{{ $root.tr(" سجل") }}</span>
      <details
        class="pos-column-picker"
        ref="columns"
        @keydown.esc.stop.prevent="$refs.columns.open = false"
      >
        <summary class="btn">
          {{ $root.tr("إظهار الأعمدة ") }}<span aria-hidden="true">▾</span>
        </summary>
        <div class="pos-column-options">
          <strong>{{ $root.tr("الأعمدة الظاهرة") }}</strong
          ><label
            v-for="c in columns.filter(c=&gt;c[0]!=='name')"
            class="pos-column-option"
            ><input
              type="checkbox"
              :checked="!hidden.includes(c[0])"
              @change="toggle(c[0])"
            /><span>{{ $root.tr(c[1]) }}</span></label
          >
        </div>
      </details>
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th v-for="c in shown" :key="c[0]">{{ $root.tr(c[1]) }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="p in rows" :key="p.id">
            <td v-for="c in shown" :key="c[0]">
              <span
                v-if="c[0] === 'active'"
                class="badge"
                :class="{ neutral: !p.active }"
                >{{ $root.tr(p.active ? "مفعل" : "موقوف") }}</span
              ><span
                v-else-if="c[0] === 'online'"
                class="pos-presence-dot"
                :class="{ online: online(p) }"
                role="img"
                :aria-label="$root.tr(online(p) ? 'متصل' : 'غير متصل')"
                :title="$root.tr(online(p) ? 'متصل' : 'غير متصل')"
              ></span
              ><span v-else="" :dir="c[0] === 'phone' ? 'ltr' : undefined">{{
                $root.tr(value(p, c[0]))
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button class="btn small" @click="details(p)">
                  {{ $root.tr("التفاصيل") }}</button
                ><button
                  v-if="resettable(p)"
                  class="btn small"
                  @click="requestReset(p)"
                >
                  {{ $root.tr("إعادة إرسال الرمز") }}</button
                ><button
                  v-if="vm.can('pos.edit')"
                  class="btn small"
                  @click="edit(p)"
                >
                  {{ $root.tr("تعديل") }}</button
                ><button
                  v-if="vm.can('pos.toggle')&amp;&amp;vm.page==='pos'"
                  class="btn small"
                  @click="vm.toggleEntity(p)"
                >
                  {{ $root.tr(p.active ? "إيقاف" : "تفعيل") }}</button
                ><button
                  v-if="vm.canArchiveNetwork('pos', p.id)"
                  class="btn small danger"
                  @click="vm.askArchiveNetwork('pos', p.id)"
                >
                  {{ $root.tr("حذف") }}</button
                ><button
                  v-if="vm.canManageNetwork('pos', p.id)"
                  class="btn small"
                  @click="vm.openNetworkPermissions('pos', p.id)"
                >
                  {{ $root.tr("صلاحيات التابع") }}
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td :colspan="shown.length + 1" class="empty">
              {{ $root.tr("لا توجد نقاط بيع مطابقة") }}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

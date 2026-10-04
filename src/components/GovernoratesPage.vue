<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["governorates-page"]);
export default options;
</script>

<template>
  <section
    v-if="vm.page==='governorates'&amp;&amp;vm.actor.role==='owner'"
    class="card"
  >
    <div class="toolbar">
      <input
        v-model="vm.search"
        :placeholder="$root.tr('بحث عن محافظة')"
        :aria-label="$root.tr('بحث عن محافظة')"
      /><span
        >{{ $root.tr(vm.governorateRows.filter(r=&gt;r.active).length)
        }}{{ $root.tr(" محافظة مفعلة") }}</span
      >
    </div>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("المحافظة") }}</th>
            <th>{{ $root.tr("الوكلاء الرئيسيون") }}</th>
            <th>{{ $root.tr("الوكلاء الفرعيون") }}</th>
            <th>{{ $root.tr("نقاط البيع") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("الإجراء") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.name">
            <td>{{ $root.tr(r.name) }}</td>
            <td>
              {{ $root.tr(vm.s.agents.filter(a=&gt;a.city===r.name&amp;&amp;a.type==='رئيسي').length) }}
            </td>
            <td>
              {{ $root.tr(vm.s.agents.filter(a=&gt;a.city===r.name&amp;&amp;a.type==='فرعي').length) }}
            </td>
            <td>
              {{ $root.tr(vm.s.pos.filter(p=&gt;p.city===r.name).length) }}
            </td>
            <td>
              <span class="badge" :class="{ neutral: !r.active }">{{
                $root.tr(r.active ? "مفعلة" : "معطلة")
              }}</span>
            </td>
            <td>
              <button class="btn small" @click="vm.toggleGovernorate(r.name)">
                {{ $root.tr(r.active ? "تعطيل" : "تفعيل") }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>

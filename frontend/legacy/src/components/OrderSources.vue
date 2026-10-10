<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["order-sources"]);
export default options;
</script>

<template>
  <section
    v-if="vm.page==='sources'&amp;&amp;vm.can('sources.view')"
    class="card"
  >
    <div class="toolbar">
      <button
        v-if="vm.can('sources.create')"
        class="btn primary"
        @click="edit()"
      >
        {{ $root.tr("إضافة مصدر") }}</button
      ><input
        v-model="query"
        :placeholder="$root.tr('بحث باسم المصدر أو الشركة')"
      />
    </div>
    <form v-if="editing" @submit.prevent="save">
      <div class="formgrid">
        <label
          >{{ $root.tr("اسم المصدر")
          }}<input v-model="draft.name" required="" maxlength="150" /></label
        ><label
          >{{ $root.tr("الشركة")
          }}<select v-model="draft.provider" required="">
            <option value="" disabled="">{{ $root.tr("اختر الشركة") }}</option>
            <option
              v-for="p in vm.s.providers.filter(p=&gt;p.active)"
              :value="p.id"
            >
              {{ $root.tr(p.name) }}
            </option>
          </select></label
        >
      </div>
      <div v-if="error" class="notice warn" role="alert">
        {{ $root.tr(error) }}
      </div>
      <div class="formfoot">
        <button class="btn primary" type="submit">
          {{ $root.tr("حفظ المصدر") }}</button
        ><button class="btn" type="button" @click="editing = false">
          {{ $root.tr("إلغاء") }}
        </button>
      </div>
    </form>
    <div class="tablewrap">
      <table>
        <thead>
          <tr>
            <th>{{ $root.tr("اسم المصدر") }}</th>
            <th>{{ $root.tr("الشركة") }}</th>
            <th>{{ $root.tr("الحالة") }}</th>
            <th>{{ $root.tr("الإجراءات") }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td>{{ $root.tr(r.name) }}</td>
            <td>{{ $root.tr(vm.nameOf("providers", r.provider)) }}</td>
            <td>
              <span class="badge" :class="{ neutral: !r.active }">{{
                $root.tr(r.active ? "مفعل" : "معطل")
              }}</span>
            </td>
            <td>
              <div class="actions">
                <button
                  v-if="vm.can('sources.edit')"
                  class="btn small"
                  @click="edit(r)"
                >
                  {{ $root.tr("تعديل") }}</button
                ><button
                  v-if="vm.can('sources.toggle')"
                  class="btn small"
                  @click="vm.run(()=&gt;vm.engine.toggleOrderSource(r.id))"
                >
                  {{ $root.tr(r.active ? "تعطيل" : "تفعيل") }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <div v-if="!rows.length" class="empty">{{ $root.tr("لا توجد مصادر") }}</div>
  </section>
</template>

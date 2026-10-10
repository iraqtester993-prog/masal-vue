<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["support-contacts"]);
export default options;
</script>

<template>
  <section class="support-contact-bar">
    <support-phone-links
      v-if="!vm.supportSelectedTicket"
      :account="recipient"
    ></support-phone-links>
    <details v-if="canEdit" @toggle="load" :key="vm.currentUser">
      <summary>{{ $root.tr("إدارة أرقام الدعم الخاصة بي") }}</summary>
      <form @submit.prevent="save">
        <div v-for="(r, i) in draft" :key="i" class="support-phone-edit">
          <span class="support-phone-order">{{ $root.tr(i + 1) }}</span
          ><input
            v-model="r.label"
            :aria-label="$root.tr('اسم رقم الدعم ' + (i + 1))"
            :placeholder="$root.tr('اسم الرقم')"
          /><input
            type="tel"
            inputmode="numeric"
            minlength="11"
            maxlength="11"
            pattern="07[78][0-9]{8}"
            v-model="r.number"
            required=""
            :aria-label="$root.tr('رقم الدعم ' + (i + 1))"
            dir="ltr"
            :placeholder="$root.tr('077xxxxxxxx أو 078xxxxxxxx')"
            :title="$root.tr('11 رقمًا تبدأ بـ077 أو 078')"
          /><button type="button" class="btn small" @click="draft.splice(i, 1)">
            {{ $root.tr("حذف") }}
          </button>
        </div>
        <div class="actions">
          <button
            type="button"
            class="btn"
            :disabled="draft.length&gt;=5"
            @click="draft.push({ label: 'الدعم الفني', number: '' })"
          >
            {{ $root.tr("+ إضافة رقم دعم") }}</button
          ><button class="btn primary">{{ $root.tr("حفظ الأرقام") }}</button>
        </div>
      </form>
    </details>
  </section>
</template>

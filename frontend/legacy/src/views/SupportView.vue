<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card workspace-card support-workspace">
    <support-contacts></support-contacts><support-inbox></support-inbox>
    <form
      v-if="can('support.create')"
      class="support-composer"
      @submit.prevent="saveTicket"
    >
      <div class="support-section-heading">
        <h2>{{ $root.tr("رسالة جديدة") }}</h2>
        <span class="caption">{{
          $root.tr("تواصل مع المستلم وتابع الردود هنا")
        }}</span>
      </div>
      <div class="support-compose-grid">
        <label v-if="can('support.broadcast')"
          >{{ $root.tr("المستخدمين")
          }}<select
            v-model="supportAudience"
            :aria-label="$root.tr('مستخدمو رسالة الدعم')"
          >
            <option value="all">
              {{ $root.tr("عام لكل المستخدمين ضمن نطاقي") }}
            </option>
            <option value="agents">{{ $root.tr("الوكلاء الرئيسيون") }}</option>
            <option value="branches">{{ $root.tr("الفروع") }}</option>
            <option value="custom">{{ $root.tr("مخصص") }}</option>
            <option value="direct">{{ $root.tr("مستلم مباشر") }}</option>
          </select></label
        >
        <div v-if="supportAudience === 'custom'">
          <input
            v-model="supportSearch"
            :placeholder="$root.tr('بحث المستخدمين')"
            :aria-label="$root.tr('بحث مستلمي الدعم')"
          />
          <div class="scope-options">
            <label v-for="u in supportBroadcastChoices" :key="u.id"
              ><input
                type="checkbox"
                v-model="supportSelected"
                :value="u.id"
              />{{ $root.tr(u.name) }}</label
            >
          </div>
        </div>
        <label v-if="supportAudience === 'direct'"
          >{{ $root.tr("المرسل إليه")
          }}<select v-model="ticketForm.recipient" required="">
            <option value="" disabled="">{{ $root.tr("اختر المستلم") }}</option>
            <option v-for="a in supportRecipients" :value="a.id">
              {{ $root.tr(a.name) }}
            </option>
          </select></label
        ><label
          >{{ $root.tr("العنوان")
          }}<input v-model="ticketForm.title" required="" /></label
        ><label class="support-message-field"
          >{{ $root.tr("محتوى الرسالة")
          }}<textarea v-model="ticketForm.description" required=""></textarea>
        </label>
      </div>
      <image-attachment
        v-if="can('support.attach')"
        v-model="ticketForm.image"
        @busy="attachmentBusy = $event"
      ></image-attachment>
      <div class="formfoot">
        <button
          class="btn primary"
          :disabled="formPending.saveTicket || attachmentBusy"
        >
          {{ $root.tr("إرسال الرسالة") }}
        </button>
      </div>
    </form>
  </div>
</template>

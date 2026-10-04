<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <article class="notice-detail">
    <h3 translate="no" dir="auto">{{ noticeText(modal.notice).title }}</h3>
    <div class="notice-detail-meta">
      <span
        >{{ $root.tr("من: ")
        }}{{ $root.tr(s.users.find(u=&gt;u.id===modal.notice.user)?.name||'إدارة النظام') }}</span
      ><time>{{ $root.tr(formatTime(modal.notice.time)) }}</time>
    </div>
    <p class="notice-detail-body" translate="no" dir="auto">
      {{ noticeText(modal.notice).body }}
    </p>
    <img
      v-if="modal.notice.image&amp;&amp;/^data:image\/(png|jpeg|webp);base64,/.test(modal.notice.image)"
      :src="modal.notice.image"
      class="notice-detail-image"
      :alt="$root.tr('الصورة المرفقة بالإشعار')"
    />
  </article>
  <div class="formfoot">
    <button class="btn" @click="closeModal">{{ $root.tr("إغلاق") }}</button>
  </div>
</template>

<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["support-inbox"]);
export default options;
</script>

<template>
  <section class="support-inbox">
    <div class="support-section-heading">
      <h2>{{ $root.tr("المحادثات") }}</h2>
      <span class="badge"
        >{{ $root.tr(unread) }}{{ $root.tr(" غير مقروءة") }}</span
      >
    </div>
    <div class="support-chat-layout">
      <aside class="support-chat-list">
        <input
          type="search"
          v-model="query"
          :placeholder="$root.tr('بحث بالعنوان أو الطرف')"
          :aria-label="$root.tr('بحث محادثات الدعم')"
        />
        <div class="actions">
          <button
            class="btn small"
            :class="{ primary: filter === 'all' }"
            @click="filter = 'all'"
          >
            {{ $root.tr("الكل") }}</button
          ><button
            class="btn small"
            :class="{ primary: filter === 'unread' }"
            @click="filter = 'unread'"
          >
            {{ $root.tr("غير المقروءة") }}
          </button>
        </div>
        <button
          v-for="t in shown"
          :key="t.id"
          class="support-thread support-chat-item"
          :class="{unread:e.supportUnread(t)&gt;0,selected:selected?.id===t.id}"
          @click="open(t)"
        >
          <span class="support-chat-item-head"
            ><b>{{ $root.tr(t.title) }}</b
            ><span v-if="e.supportUnread(t)" class="support-unread-dot">{{
              $root.tr(e.supportUnread(t))
            }}</span></span
          ><span
            >{{ $root.tr(e.supportName(t.origin || t.agent)) }} ←
            {{ $root.tr(e.supportName(t.recipient || "@owner")) }}</span
          ><small class="support-snippet">{{ preview(t) }}</small
          ><span class="support-chat-item-head"
            ><small>{{ $root.tr(vm.formatTime(lastTime(t))) }}</small
            ><small>{{ $root.tr(t.status) }}</small></span
          >
        </button>
        <p v-if="!shown.length" class="empty">
          {{ $root.tr("لا توجد محادثات مطابقة") }}
        </p>
        <div v-if="pages&gt;1" class="actions">
          <button class="btn small" :disabled="page&lt;=1" @click="page--">
            {{ $root.tr("السابق") }}</button
          ><span>{{ $root.tr(page) }} / {{ $root.tr(pages) }}</span
          ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
            {{ $root.tr("التالي") }}
          </button>
        </div>
      </aside>
      <section
        class="support-chat-detail"
        :aria-label="$root.tr('المحادثة المفتوحة')"
      >
        <template v-if="selected"
          ><header>
            <h3>{{ $root.tr(selected.title) }}</h3>
            <div>
              {{ $root.tr(e.supportName(selected.origin || selected.agent)) }} ←
              {{ $root.tr(e.supportName(selected.recipient)) }}
            </div>
            <div class="actions">
              <span class="badge">{{ $root.tr(selected.status) }}</span
              ><button
                v-if="e.supportCanManage(selected)&amp;&amp;vm.can('support.close')"
                class="btn small"
                @click="status('مغلقة')"
              >
                {{ $root.tr("إغلاق المحادثة") }}</button
              ><button
                v-if="!selected.broadcastRecipient&amp;&amp;e.supportCanManage(selected)&amp;&amp;e.supportParent(selected.recipient)&amp;&amp;vm.can('support.escalate')"
                class="btn small"
                @click="status('مصعّدة')"
              >
                {{ $root.tr("تصعيد") }}
              </button>
            </div>
            <support-phone-links :account="counterpart"></support-phone-links>
          </header>
          <div ref="messages" class="support-message-stream" aria-live="polite">
            <article
              v-for="m in messages"
              :key="m.key"
              class="support-message"
              :class="{ mine: m.mine }"
            >
              <b>{{ $root.tr(m.mine ? "أنت" : m.name) }}</b>
              <p>{{ m.body }}</p>
              <img
                v-if="m.image"
                :src="m.image"
                :alt="$root.tr('مرفق الرسالة')"
              /><time>{{ $root.tr(vm.formatTime(m.time)) }}</time>
            </article>
          </div>
          <form
            v-if="vm.can('support.reply')&amp;&amp;e.supportReplyAllowed(selected)"
            class="support-chat-reply"
            @submit.prevent="send"
          >
            <textarea
              v-model="draft"
              aria-label="الرد على المحادثة"
              placeholder="اكتب ردك…"
              required=""
            ></textarea
            ><button class="btn primary" :disabled="sending || !draft.trim()">
              {{ $root.tr("إرسال الرد") }}
            </button>
          </form>
          <p v-else="" class="caption">
            {{
              $root.tr(
                selected.status === "مغلقة"
                  ? "المحادثة مغلقة"
                  : "بانتظار رد الجهة المسؤولة",
              )
            }}
          </p></template
        >
        <p v-else="" class="empty">
          {{ $root.tr("اختر محادثة لعرض الرسائل والرد عليها") }}
        </p>
      </section>
    </div>
  </section>
</template>

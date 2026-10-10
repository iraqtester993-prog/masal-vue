<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="card" v-if="can('notifications.send')">
    <form @submit.prevent="sendNotification">
      <div class="formgrid">
        <label
          >{{ $root.tr("المستخدمين")
          }}<select v-model="noticeMode" :aria-label="$root.tr('المستخدمين')">
            <option value="all">{{ $root.tr("عام ضمن نطاقي") }}</option>
            <option
              v-if="actor.role==='owner'||(actor.role==='employee'&amp;&amp;actor.staffAccount==='@system')"
              value="agents"
            >
              {{ $root.tr("الوكلاء الرئيسيون فقط") }}
            </option>
            <option value="branches">{{ $root.tr("الأفرع فقط") }}</option>
            <option value="points">{{ $root.tr("نقاط البيع فقط") }}</option>
            <option value="custom">{{ $root.tr("مخصص") }}</option>
          </select></label
        >
      </div>
      <details
        v-if="noticeMode === 'custom'"
        class="notice-picker"
        @keydown.esc.prevent="
          $event.currentTarget.open = false;
          $event.currentTarget.querySelector('summary').focus();
        "
      >
        <summary>
          {{
            $root.tr(
              noticeSelected.length
                ? "تم تحديد " + noticeSelected.length + " مستخدم"
                : "اختر المستخدمين",
            )
          }}
        </summary>
        <div class="notice-dropdown">
          <input
            v-model="noticeSearch"
            :placeholder="$root.tr('بحث بالاسم أو البريد')"
            :aria-label="$root.tr('بحث المستخدمين')"
          />
          <div class="notice-users">
            <label v-for="u in noticeChoices" :key="u.id"
              ><input
                type="checkbox"
                v-model="noticeSelected"
                :value="u.id"
              />{{ $root.tr(u.name) }}</label
            >
            <p v-if="!noticeChoices.length">{{ $root.tr("لا توجد نتائج") }}</p>
          </div>
        </div>
      </details>
      <div class="notice-language-grid">
        <fieldset>
          <legend>{{ $root.tr("العربية") }}</legend>
          <label
            >{{ $root.tr("العنوان")
            }}<input
              v-model="notificationForm.title"
              required=""
              dir="rtl"
              :aria-label="$root.tr('عنوان الإشعار العربي')" /></label
          ><label
            >{{ $root.tr("النص")
            }}<textarea
              v-model="notificationForm.body"
              required=""
              dir="rtl"
              aria-label="نص الإشعار العربي"
            ></textarea>
          </label>
        </fieldset>
        <fieldset
          :disabled="!can('notifications.translations')"
          v-for="language in [
            { id: 'en', name: 'English' },
            { id: 'ckb', name: 'کوردی' },
          ]"
          :key="language.id"
        >
          <legend translate="no">{{ language.name }}</legend>
          <label
            >{{ $root.tr("العنوان")
            }}<input
              v-model="notificationForm.translations[language.id].title"
              :dir="language.id === 'en' ? 'ltr' : 'rtl'"
              :aria-label="$root.tr(language.name + ' title')" /></label
          ><label
            >{{ $root.tr("النص")
            }}<textarea
              v-model="notificationForm.translations[language.id].body"
              :dir="language.id === 'en' ? 'ltr' : 'rtl'"
              :aria-label="language.name + ' text'"
            ></textarea>
          </label>
        </fieldset>
      </div>
      <p
        class="help"
        v-if="
          !notificationForm.translations.en.title ||
          !notificationForm.translations.en.body ||
          !notificationForm.translations.ckb.title ||
          !notificationForm.translations.ckb.body
        "
      >
        {{
          $root.tr(
            "الترجمة غير المكتملة لا تُرسل. عند ترك لغة فارغة بالكامل، يظهر النص العربي بدلًا منها.",
          )
        }}
      </p>
      <image-attachment
        v-if="can('notifications.attach')"
        v-model="notificationForm.image"
        @busy="attachmentBusy = $event"
      ></image-attachment>
      <div class="formfoot">
        <button
          class="btn primary"
          :disabled="formPending.sendNotification || attachmentBusy"
        >
          {{ $root.tr("إرسال") }}
        </button>
      </div>
    </form>
  </div>
  <div class="card" style="margin-top: 20px">
    <div v-for="n in visibleNotifications" :key="n.id" class="rowline">
      <div>
        <b translate="no" dir="auto">{{ noticeText(n).title }}</b>
        <p translate="no" dir="auto" style="white-space: pre-wrap">
          {{ noticeText(n).body }}
        </p>
        <img
          v-if="n.image"
          :src="n.image"
          class="message-image"
          :alt="$root.tr('صورة الإشعار')"
        /><small
          >{{ $root.tr(formatTime(n.time)) }} ·
          {{ $root.tr(n.user === currentUser ? "صادر" : "وارد") }}</small
        >
      </div>
      <button class="btn small" @click="openNotice(n)">
        {{
          $root.tr(
            n.supportTicket
              ? "فتح الرسالة"
              : n.readBy?.includes(currentUser)
                ? "مقروء"
                : "عرض",
          )
        }}
      </button>
    </div>
    <div v-if="!visibleNotifications.length" class="empty">
      {{ $root.tr("لا توجد إشعارات") }}
    </div>
  </div>
</template>

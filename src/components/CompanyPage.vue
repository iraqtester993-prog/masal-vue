<script>
import { componentOptions } from "../services/component-registry.js";
import source from "./CompanyPage.vue?raw";
const options = componentOptions(["company-page"]);
options.template = source.slice(
  source.indexOf("\n<template>") + 11,
  source.lastIndexOf("</template>"),
);
export default options;
</script>

<template>
  <section
    v-if="vm.page === 'company'"
    class="company-profile-page"
    :data-site-theme="siteTheme"
  >
    <dialog
      v-if="opening"
      ref="introDialog"
      class="company-intro-dialog"
      @cancel.prevent="endIntro"
      @click.self="endIntro"
      :aria-label="$root.tr('مرحبًا بك في موقع الشركة')"
    >
      <div class="company-opening">
        <button
          type="button"
          class="company-intro-close"
          @click="endIntro"
          :aria-label="$root.tr('تخطي المقدمة')"
        >
          ×
        </button>
        <div class="company-animation">
          <div class="company-orbit"></div>
          <div
            v-for="(icon, i) in ['▣', '◇', '✦']"
            :class="'company-float company-float-' + i"
          >
            <svg viewBox="0 0 48 32" fill="none" aria-hidden="true">
              <rect
                x="1"
                y="1"
                width="46"
                height="30"
                rx="5"
                stroke="currentColor"
                stroke-width="2"
              ></rect>
              <path
                d="M2 10h44M8 23h12"
                stroke="currentColor"
                stroke-width="3"
              ></path></svg
            ><span>{{ $root.tr(icon) }}</span>
          </div>
          <div class="company-emblem">
            <img v-if="p.logo" :src="p.logo" alt="" /><span v-else="">{{
              $root.tr(p.name)
            }}</span>
          </div>
        </div>
        <h2>{{ $root.tr(p.name) }}</h2>
        <p>{{ $root.tr(p.tagline) }}</p>
        <div class="company-opening-progress"><i></i></div>
      </div>
    </dialog>
    <section class="masal-site company-profile-content">
      <div class="site-tools">
        <button
          v-if="editable&amp;&amp;!editing"
          class="btn primary"
          @click="edit"
        >
          {{ $root.tr("إدارة الموقع") }}
        </button>
      </div>
      <form v-if="editing" class="card site-editor" @submit.prevent="save">
        <div class="site-editor-tabs">
          <button
            type="button"
            v-for="t in [...sections, ['inbox', 'طلبات العملاء']]"
            :key="t[0]"
            :class="['btn', { primary: tab === t[0] }]"
            @click="tab = t[0]"
          >
            {{ $root.tr(t[1]) }}
            <span
              v-if="t[0] === 'inbox'"
              >{{ $root.tr(inbox.filter(x=&gt;x.status==='جديدة').length) }}</span
            >
          </button>
        </div>
        <label v-if="tab !== 'inbox'" class="site-switch"
          ><input type="checkbox" v-model="draft.visibility[tab]" />{{
            $root.tr("إظهار القسم")
          }}</label
        >
        <div v-if="tab === 'about'" class="formgrid">
          <label
            >{{ $root.tr("اسم الشركة")
            }}<input v-model="draft.name" required="" /></label
          ><label
            >{{ $root.tr("عبارة الواجهة")
            }}<input v-model="draft.tagline" /></label
          ><label
            >{{ $root.tr("شعار الشركة")
            }}<image-attachment
              v-model="draft.logo"
              @busy="busy.logo = $event"
            ></image-attachment></label
          ><label class="full"
            >{{ $root.tr("نبذة عن الشركة")
            }}<textarea v-model="draft.about" rows="5"></textarea></label
          ><label
            >{{ $root.tr("العنوان") }}<input v-model="draft.address" /></label
          ><label
            >{{ $root.tr("الموقع الإلكتروني")
            }}<input type="url" v-model="draft.website" dir="ltr"
          /></label>
        </div>
        <template
          v-else-if="
            ['slides', 'activities', 'offers', 'projects'].includes(tab)
          "
          ><p v-if="tab === 'slides'" class="caption">
            {{
              $root.tr(
                "الصورة اختيارية؛ بدون صورة يظهر العنوان والتفاصيل بخلفية التصميم.",
              )
            }}
          </p>
          <p v-if="!draft.visibility[tab]" class="notice warn">
            {{
              $root.tr(
                "هذا القسم مخفي. فعّل «إظهار القسم» ليظهر محتواه في البروفايل.",
              )
            }}
          </p>
          <article
            v-for="(item, i) in draft[tab]"
            :key="tab + '-' + i"
            class="site-editor-item"
          >
            <div class="formgrid">
              <label
                >{{ $root.tr("العنوان")
                }}<input
                  v-model="item.title"
                  :placeholder="$root.tr(item.caption)" /></label
              ><label
                >{{ $root.tr("الصورة")
                }}<image-attachment
                  v-model="item.image"
                  @busy="busy[tab + i] = $event"
                ></image-attachment></label
              ><label class="full"
                >{{ $root.tr("التفاصيل")
                }}<textarea
                  v-model="item.description"
                  rows="3"
                ></textarea></label
              ><label
                >{{ $root.tr("الرابط")
                }}<input type="url" v-model="item.link" dir="ltr" /></label
              ><label class="site-switch"
                ><input type="checkbox" v-model="item.visible" />{{
                  $root.tr("إظهار العنصر")
                }}</label
              >
            </div>
            <div class="actions">
              <button
                type="button"
                class="btn small"
                @click="move(i, -1)"
                :disabled="i === 0"
              >
                ↑</button
              ><button
                type="button"
                class="btn small"
                @click="move(i, 1)"
                :disabled="i === draft[tab].length - 1"
              >
                ↓</button
              ><button
                type="button"
                class="btn small"
                @click="draft[tab].splice(i, 1)"
              >
                {{ $root.tr("حذف") }}
              </button>
            </div>
          </article>
          <button type="button" class="btn" @click="add">
            {{ $root.tr("إضافة ")
            }}{{ $root.tr(sections.find(x=&gt;x[0]===tab)[1]==='السلايدر'?'صورة':'عنصر') }}
          </button></template
        >
        <template v-else-if="tab === 'social'"
          ><div
            v-for="(item, i) in draft.social"
            class="site-editor-item formgrid"
          >
            <label
              >{{ $root.tr("المنصة")
              }}<input
                v-model="item.name"
                :placeholder="$root.tr('اسم منصة التواصل')" /></label
            ><label
              >{{ $root.tr("الرابط")
              }}<input type="url" v-model="item.url" dir="ltr" /></label
            ><button
              type="button"
              class="btn small"
              @click="draft.social.splice(i, 1)"
            >
              {{ $root.tr("حذف") }}
            </button>
          </div>
          <button
            type="button"
            class="btn"
            @click="draft.social.push({ name: '', url: '' })"
          >
            {{ $root.tr("إضافة موقع تواصل") }}
          </button></template
        >
        <div v-else-if="tab === 'care'" class="formgrid">
          <label
            >{{ $root.tr("الهاتف")
            }}<input v-model="draft.phone" type="tel" dir="ltr" /></label
          ><label
            >{{ $root.tr("البريد الإلكتروني")
            }}<input v-model="draft.email" type="email" dir="ltr" /></label
          ><label
            >{{ $root.tr("واتساب مع رمز الدولة")
            }}<input v-model="draft.whatsapp" type="tel" dir="ltr" /></label
          ><label
            >{{ $root.tr("أوقات خدمة العملاء") }}<input v-model="draft.hours"
          /></label>
        </div>
        <div v-else-if="tab === 'inbox'">
          <p v-if="!inbox.length" class="empty">
            {{ $root.tr("لا توجد طلبات") }}
          </p>
          <article v-for="r in inbox" class="site-editor-item">
            <div class="rowline">
              <b>{{ $root.tr(r.name) }}</b
              ><span class="badge">{{ $root.tr(r.status) }}</span>
            </div>
            <small
              >{{ $root.tr(r.contact) }} ·
              {{ $root.tr(vm.formatTime(r.time)) }}</small
            >
            <p class="site-copy">{{ $root.tr(r.message) }}</p>
            <button type="button" class="btn small" @click="resolve(r.id)">
              {{
                $root.tr(r.status === "جديدة" ? "تمت المتابعة" : "إعادة فتح")
              }}
            </button>
          </article>
        </div>
        <p v-if="saveError" class="notice warn" role="alert">
          {{ $root.tr(saveError) }}
        </p>
        <div class="formfoot">
          <button type="button" class="btn" @click="editing = false">
            {{ $root.tr("إلغاء") }}</button
          ><button class="btn primary" :disabled="pending">
            {{ $root.tr("حفظ") }}
          </button>
        </div>
      </form>
      <div v-else="" class="site-surface site-premium">
        <div class="site-ambient" aria-hidden="true"><i></i><i></i><i></i></div>
        <div v-if="p.phone || p.email || visible('social')" class="site-topbar">
          <div>
            <a v-if="p.phone" :href="phone(p.phone)" dir="ltr">{{
              $root.tr(p.phone)
            }}</a
            ><a v-if="p.email" :href="'mailto:' + p.email">{{ p.email }}</a>
          </div>
          <div v-if="visible('social')">
            <a
              v-for="s in p.social.filter(s=&gt;safe(s.url))"
              :href="safe(s.url)"
              target="_blank"
              rel="noopener noreferrer"
              >{{ $root.tr(s.name) }}</a
            >
          </div>
        </div>
        <header class="site-header">
          <button
            ref="themeButton"
            type="button"
            class="site-theme-toggle"
            @click="toggleSiteTheme"
            :aria-pressed="siteTheme === 'dark'"
            :aria-label="
              $root.tr(
                siteTheme === 'dark'
                  ? 'تفعيل الوضع النهاري'
                  : 'تفعيل الوضع الليلي',
              )
            "
          >
            {{ $root.tr(siteTheme === "dark" ? "☀ نهاري" : "☾ ليلي") }}</button
          ><a
            class="site-wordmark"
            href="#company"
            @click.prevent="
              $el.querySelector('.site-hero').scrollIntoView({ block: 'start' })
            "
            ><img v-if="p.logo" :src="p.logo" :alt="$root.tr(p.name)" /><span>{{
              $root.tr(p.name)
            }}</span
            ><i></i></a
          ><button
            class="site-menu-toggle"
            @click="profileNavOpen = !profileNavOpen"
            :aria-expanded="profileNavOpen"
            aria-controls="profile-navigation"
          >
            {{ $root.tr(profileNavOpen ? "إغلاق ×" : "القائمة ☰") }}
          </button>
          <nav
            id="profile-navigation"
            :class="{ 'is-open': profileNavOpen }"
            :aria-label="$root.tr('أقسام موقع الشركة')"
          >
            <button v-for="[key, label] in links" @click="jump(key)">
              {{ $root.tr(label) }}
            </button>
          </nav>
          <button
            v-if="visible('care')"
            class="site-header-contact"
            @click="jump('care')"
          >
            {{ $root.tr("لنتواصل ") }}<span>↗</span>
          </button>
        </header>
        <section
          class="site-hero"
          :class="{ 'site-hero-with-image': !!current?.image }"
          @mouseenter="paused = true"
          @mouseleave="paused = false"
          @focusin="paused = true"
          @focusout="paused = false"
          @keydown.left.prevent="next(1)"
          @keydown.right.prevent="next(-1)"
        >
          <div v-if="current?.image" class="site-hero-visual">
            <img
              :key="index"
              :src="current.image"
              :alt="$root.tr(current.title || current.caption)"
              class="site-hero-image"
            /><span class="site-visual-mark" aria-hidden="true"
              >{{
                $root.tr(String((index % slides.length) + 1).padStart(2, "0"))
              }}
              / {{ $root.tr(String(slides.length).padStart(2, "0")) }}</span
            >
          </div>
          <div v-if="!current?.image" class="site-quick-panel">
            <h2>{{ $root.tr("اكتشف ") }}{{ $root.tr(p.name) }}</h2>
            <button
              v-for="[key, label] in links.slice(0, 5)"
              @click="jump(key)"
            >
              <span class="site-quick-icon">{{
                $root.tr(
                  {
                    about: "◇",
                    activities: "▤",
                    offers: "✦",
                    projects: "▦",
                    social: "↗",
                    care: "☏",
                  }[key],
                )
              }}</span
              ><span>{{ $root.tr(label) }}</span
              ><span>‹</span>
            </button>
            <p v-if="!links.length">{{ $root.tr(p.tagline) }}</p>
          </div>
          <div class="site-hero-content">
            <span class="site-eyebrow"
              ><i aria-hidden="true"></i>{{ $root.tr(p.name) }}</span
            >
            <h1>
              {{
                $root.tr(
                  current?.title || current?.caption || p.tagline || p.name,
                )
              }}
            </h1>
            <p v-if="current?.description || p.about">
              {{ $root.tr(current?.description || p.about) }}
            </p>
            <div class="site-hero-actions">
              <a
                v-if="safe(current?.link)"
                :href="safe(current.link)"
                target="_blank"
                rel="noopener noreferrer"
                class="site-button"
                >{{ $root.tr("اكتشف المزيد ↗") }}</a
              ><button
                v-if="visible('care')"
                class="site-button site-contact-action"
                @click="jump('care')"
              >
                {{ $root.tr("تواصل معنا ←") }}</button
              ><button
                v-if="visible('about')"
                class="site-text-link"
                @click="jump('about')"
              >
                {{ $root.tr("تعرّف على ") }}{{ $root.tr(p.name) }}
              </button>
            </div>
          </div>
          <div v-if="slides.length&gt;1" class="site-slider-controls">
            <button @click="next(-1)" :aria-label="$root.tr('الصورة السابقة')">
              →</button
            ><button
              v-for="(_, i) in slides"
              :class="{ selected: index % slides.length === i }"
              @click="index = i"
              :aria-label="$root.tr('الصورة ' + (i + 1))"
              :aria-current="index % slides.length === i ? 'true' : undefined"
            >
              {{ $root.tr(String(i + 1).padStart(2, "0")) }}</button
            ><button @click="next(1)" :aria-label="$root.tr('الصورة التالية')">
              ←</button
            ><button
              @click="autoplayPaused = !autoplayPaused"
              :aria-label="
                $root.tr(autoplayPaused ? 'تشغيل السلايدر' : 'إيقاف السلايدر')
              "
            >
              {{ $root.tr(autoplayPaused ? "▶" : "Ⅱ") }}
            </button>
          </div>
        </section>

        <section
          v-for="key in ['activities', 'offers', 'projects'].filter(visible)"
          :id="'company-' + key"
          :key="key"
          class="site-section"
          :class="'site-' + key"
        >
          <div class="site-section-heading">
            <div>
              <span class="site-eyebrow">{{ $root.tr(p.name) }}</span>
              <h2>{{ $root.tr(sections.find(x=&gt;x[0]===key)[1]) }}</h2>
            </div>
            <span>{{
              $root.tr(String(items(key).length).padStart(2, "0"))
            }}</span>
          </div>
          <div
            :class="[
              'site-grid',
              { 'site-grid-single': items(key).length === 1 },
            ]"
          >
            <article
              v-for="(item, i) in items(key)"
              :key="i"
              class="site-content-card"
            >
              <div class="site-card-media">
                <img
                  v-if="item.image"
                  :src="item.image"
                  :alt="$root.tr(item.title)"
                  loading="lazy"
                /><span v-else="">{{
                  $root.tr(String(i + 1).padStart(2, "0"))
                }}</span>
              </div>
              <div class="site-card-body">
                <h3>{{ $root.tr(item.title || item.caption) }}</h3>
                <p v-if="item.description">{{ $root.tr(item.description) }}</p>
                <a
                  v-if="safe(item.link)"
                  :href="safe(item.link)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="site-text-link"
                  >{{ $root.tr("عرض التفاصيل ↗") }}</a
                ><button
                  v-else-if="item.description"
                  class="site-text-link"
                  @click="details = item"
                >
                  {{ $root.tr("عرض التفاصيل ↗") }}
                </button>
              </div>
            </article>
          </div>
        </section>
        <section
          v-if="visible('about')"
          id="company-about"
          class="site-section site-about"
        >
          <div>
            <span class="site-eyebrow">{{ $root.tr("عن الشركة") }}</span>
            <h2>{{ $root.tr(p.name) }}</h2>
          </div>
          <div>
            <p class="site-copy">{{ $root.tr(p.about) }}</p>
            <a
              v-if="safe(p.website)"
              :href="safe(p.website)"
              target="_blank"
              rel="noopener noreferrer"
              class="site-text-link"
              >{{ $root.tr("الموقع الإلكتروني ↗") }}</a
            >
          </div>
        </section>
        <section
          v-if="visible('social')"
          id="company-social"
          class="site-section site-social"
        >
          <h2>{{ $root.tr("تابعنا") }}</h2>
          <div>
            <a
              v-for="s in p.social.filter(s=&gt;safe(s.url))"
              :href="safe(s.url)"
              target="_blank"
              rel="noopener noreferrer"
              >{{ $root.tr(s.name) }} ↗</a
            >
          </div>
        </section>
        <section
          v-if="visible('care')"
          id="company-care"
          class="site-section site-care"
        >
          <div>
            <span class="site-eyebrow">{{ $root.tr("خدمة العملاء") }}</span>
            <h2>{{ $root.tr("يسعدنا تواصلك") }}</h2>
            <p v-if="p.hours">{{ $root.tr(p.hours) }}</p>
            <div class="site-contact-links">
              <a v-if="p.phone" :href="phone(p.phone)"
                ><small>{{ $root.tr("اتصل بنا") }}</small
                ><b dir="ltr">{{ $root.tr(p.phone) }}</b></a
              ><a v-if="p.email" :href="'mailto:' + p.email"
                ><small>{{ $root.tr("البريد الإلكتروني") }}</small
                ><b>{{ p.email }}</b></a
              ><a
                v-if="p.whatsapp"
                :href="wa()"
                target="_blank"
                rel="noopener noreferrer"
                >{{ $root.tr("واتساب ↗") }}</a
              ><span v-if="p.address">{{ $root.tr(p.address) }}</span>
            </div>
          </div>
          <form @submit.prevent="send" class="site-contact-form">
            <label
              >{{ $root.tr("الاسم")
              }}<input
                v-model="inquiry.name"
                maxlength="120"
                required=""
                autocomplete="name" /></label
            ><label
              >{{ $root.tr("الهاتف أو البريد الإلكتروني")
              }}<input
                v-model="inquiry.contact"
                maxlength="150"
                required="" /></label
            ><label
              >{{ $root.tr("الرسالة")
              }}<textarea
                v-model="inquiry.message"
                maxlength="3000"
                rows="4"
                required=""
              ></textarea></label
            ><button class="site-button" :disabled="sending">
              {{ $root.tr(sending ? "جارٍ الإرسال…" : "إرسال") }}
            </button>
            <p v-if="saveError" role="alert">{{ $root.tr(saveError) }}</p>
            <p v-if="sent" role="status">
              {{ $root.tr("تم تسجيل رسالتك لدى إدارة الشركة.") }}
            </p>
          </form>
        </section>
        <footer class="site-footer">
          <b>{{ $root.tr(p.name) }}</b
          ><span
            >© {{ $root.tr(new Date().getFullYear())
            }}{{ $root.tr(" · جميع الحقوق محفوظة") }}</span
          ><button @click="dismiss">
            {{ $root.tr("رجوع إلى الصفحة السابقة ←") }}
          </button>
        </footer>
      </div>
      <div
        v-if="details&amp;&amp;!editing"
        class="overlay"
        @click.self="details = null"
      >
        <section
          class="modal"
          role="dialog"
          aria-modal="true"
          :aria-label="$root.tr(details.title)"
        >
          <div class="cardhead">
            <h2>{{ $root.tr(details.title) }}</h2>
            <button
              class="iconbtn"
              @click="details = null"
              :aria-label="$root.tr('إغلاق')"
            >
              ×
            </button>
          </div>
          <img
            v-if="details.image"
            :src="details.image"
            :alt="$root.tr(details.title)"
            class="site-detail-image"
          />
          <p class="site-copy">{{ $root.tr(details.description) }}</p>
          <button class="btn" @click="details = null">
            {{ $root.tr("إغلاق") }}
          </button>
        </section>
      </div>
    </section>
  </section>
</template>

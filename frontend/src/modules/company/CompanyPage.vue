<script setup>
import {computed,inject,nextTick,onBeforeUnmount,onMounted,onServerPrefetch,reactive,ref,watch} from 'vue';
import {useRouter} from 'vue-router';
import {usePortal} from '../auth/session.js';
import {createCompanyApi} from './company-api.js';
import {COMPANY_SECTIONS,COMPANY_GALLERIES,displayProfile,moveCompanyItem,profilePayload,safeHttps,sectionVisible,companyTime} from './company-model.js';
import ImageAttachment from './CompanyImage.vue';
import './company-parity.css';
import './inquiries.css';
import {inquiryTrackingUrl} from './inquiry-model.js';
import {inquiryNotificationsKey} from './inquiry-notifications.js';
const inquiryNotifications = inject(inquiryNotificationsKey, null);
const props=defineProps({management:Boolean,publicPage:Boolean});
const {session}=usePortal(),router=useRouter(),api=createCompanyApi(session.api),sections=COMPANY_SECTIONS;
const profileRoot=ref(null),introDialog=ref(null),themeButton=ref(null),record=ref(null);
const trackingLink=ref(''),copyStatus=ref('');
async function copyTracking(){try{await navigator.clipboard.writeText(trackingLink.value);copyStatus.value='تم نسخ رابط المتابعة.';}catch{copyStatus.value='تعذر النسخ؛ انسخ الرابط من الحقل.';}}
const loading=ref(true),saveError=ref(''),success=ref(''),opening=ref(false),editing=ref(false),tab=ref('about'),draft=ref(null),index=ref(0),siteTheme=ref('light'),profileNavOpen=ref(false),paused=ref(false),autoplayPaused=ref(false),details=ref(null),sending=ref(false),sent=ref(false),saving=ref(false),resolving=ref(null);
const busy=reactive({}),inquiry=reactive({name:'',contact:'',message:'',website_honeypot:''});
const inbox=ref([]),inboxMeta=reactive({current_page:1,last_page:1,total:0,new_count:0}),inboxLoading=ref(false),inboxFilter=reactive({status:'',q:''});
const editable=computed(()=>props.management&&session.state.identity?.account.type==='system'&&session.can('company.edit'));
const p=computed(()=>record.value?displayProfile(record.value.profile):null);
const slides=computed(()=>p.value?.visibility.slides?p.value.slides.filter(item=>item.visible!==false):[]);
const current=computed(()=>slides.value[index.value%Math.max(1,slides.value.length)]);
const pending=computed(()=>saving.value||Object.values(busy).some(Boolean));
const links=computed(()=>sections.filter(([key])=>key!=='slides'&&visible(key)));
const tr=value=>value,formatTime=companyTime,safe=safeHttps;
let reader,inboxReader,loadRevision=0,inboxRevision=0,alive=true,mounted=false,timer,introTimer,inquiryAttempt;
const writer=new AbortController();
function visible(key){return sectionVisible(p.value,key);}
function items(key){return (p.value?.[key]||[]).filter(item=>item.visible!==false);}
function phone(value){return 'tel:'+String(value||'').replace(/[^+\d]/g,'');}
function wa(){return 'https://wa.me/'+p.value.whatsapp.replace(/\D/g,'');}
function toggleSiteTheme(){siteTheme.value=siteTheme.value==='dark'?'light':'dark';}
function endIntro(){clearTimeout(introTimer);introDialog.value?.close();opening.value=false;void nextTick(()=>themeButton.value?.focus());}
function stop(){clearInterval(timer);clearTimeout(introTimer);introDialog.value?.close();opening.value=false;}
function next(amount){if(slides.value.length)index.value=(index.value+amount+slides.value.length)%slides.value.length;}
function jumpHero(){profileRoot.value?.querySelector('.site-hero')?.scrollIntoView({block:'start'});}
function jump(key){profileNavOpen.value=false;profileRoot.value?.querySelector('#company-'+key)?.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'auto':'smooth',block:'start'});}
function activate(){
  if(!mounted||!record.value||props.management)return;
  stop();opening.value=true;void nextTick(()=>introDialog.value?.showModal());introTimer=setTimeout(endIntro,3000);
  timer=setInterval(()=>{if(!editing.value&&!paused.value&&!autoplayPaused.value&&!document.hidden&&!matchMedia('(prefers-reduced-motion: reduce)').matches&&slides.value.length>1)next(1);},5500);
}
function edit(){if(!editable.value||!record.value)return;stop();saveError.value='';success.value='';draft.value=JSON.parse(JSON.stringify(record.value.profile));tab.value='about';editing.value=true;for(const key of Object.keys(busy))delete busy[key];}
function add(){if(!COMPANY_GALLERIES.includes(tab.value)||draft.value[tab.value].length>=30)return;draft.value[tab.value].push({title:'',caption:'',description:'',image:null,link:'',visible:true});}
function move(i,amount){moveCompanyItem(draft.value[tab.value],i,amount);}
function cancelEdit(){editing.value=false;draft.value=null;saveError.value='';success.value='';}
async function load(){
  reader?.abort();reader=new AbortController();const expected=++loadRevision,signal=reader.signal;loading.value=true;saveError.value='';
  try{
    const response=await(editable.value?api.profile(signal):api.publicProfile(signal));
    if(!alive||expected!==loadRevision)return;
    if(!response?.data?.profile||!Array.isArray(response.data.profile.slides))throw new Error('تعذر قراءة موقع الشركة.');
    record.value=response.data;index.value=0;
    if(props.management&&editable.value)edit();else activate();
  }catch(cause){if(alive&&expected===loadRevision&&cause.name!=='AbortError'){record.value=null;saveError.value=cause.status===404?'لم يُنشر موقع الشركة بعد.':cause.message;}}
  finally{if(alive&&expected===loadRevision)loading.value=false;}
}
async function save(){
  if(pending.value||!editable.value)return;saving.value=true;saveError.value='';success.value='';
  const actor=session.state.identity;
  try{const response=await api.save({version:record.value.version,profile:profilePayload(draft.value)},writer.signal);if(!alive||session.state.identity!==actor)return;record.value=response.data;index.value=0;editing.value=false;draft.value=null;success.value='تم حفظ موقع الشركة.';}
  catch(cause){if(alive&&cause.name!=='AbortError')saveError.value=cause.message;}
  finally{if(alive)saving.value=false;}
}
async function send(){
  if(sending.value)return;sending.value=true;saveError.value='';sent.value=false;
  const payload={name:inquiry.name.trim(),contact:inquiry.contact.trim(),message:inquiry.message.trim(),website_honeypot:inquiry.website_honeypot},fingerprint=JSON.stringify(payload);
  if(inquiryAttempt?.fingerprint!==fingerprint)inquiryAttempt={fingerprint,key:crypto.randomUUID()};
  try{const response=await api.inquiry({...payload,idempotency_key:inquiryAttempt.key},writer.signal);if(!alive)return;if(response?.data?.registered!==true)throw new Error('تعذر تأكيد تسجيل الرسالة.');Object.assign(inquiry,{name:'',contact:'',message:'',website_honeypot:''});trackingLink.value=inquiryTrackingUrl(response.data.tracking_token);if(props.publicPage)inquiryNotifications?.remember(response.data.tracking_token);inquiryAttempt=null;sent.value=true;}
  catch(cause){if(alive&&cause.name!=='AbortError')saveError.value=cause.message;}
  finally{if(alive)sending.value=false;}
}
async function loadInbox(page=1){
  if(!editable.value)return;inboxReader?.abort();inboxReader=new AbortController();const expected=++inboxRevision;inboxLoading.value=true;saveError.value='';
  try{const response=await api.inquiries({page,per_page:20,...inboxFilter},inboxReader.signal);if(alive&&expected===inboxRevision){inbox.value=response.data;Object.assign(inboxMeta,response.meta);}}
  catch(cause){if(alive&&expected===inboxRevision&&cause.name!=='AbortError')saveError.value=cause.message;}
  finally{if(alive&&expected===inboxRevision)inboxLoading.value=false;}
}
async function resolve(id){
  if(resolving.value!==null)return;const entry=inbox.value.find(item=>item.id===id);if(!entry)return;resolving.value=id;saveError.value='';
  try{await api.review(id,{version:entry.version,status:entry.status==='new'?'followed':'new'},writer.signal);if(alive)await loadInbox(inboxMeta.current_page);}
  catch(cause){if(alive&&cause.name!=='AbortError')saveError.value=cause.message;}
  finally{if(alive)resolving.value=null;}
}
function dismiss(){if(props.publicPage)return;if(router.hasRoute('dashboard'))void router.push({name:'dashboard'});}
watch(tab,value=>{if(value==='inbox')void loadInbox();});
watch(()=>session.state.identity,(identity,previous)=>{if(props.management&&identity!==previous){record.value=null;draft.value=null;editing.value=false;details.value=null;inbox.value=[];inboxReader?.abort();inboxRevision++;reader?.abort();loadRevision++;if(editable.value)void load();}});
onServerPrefetch(load);
onMounted(()=>{mounted=true;void load();});
onBeforeUnmount(()=>{alive=false;mounted=false;loadRevision++;inboxRevision++;reader?.abort();inboxReader?.abort();writer.abort();stop();});
</script>

<template>
  <section
    ref="profileRoot"
    class="company-profile-page"
    :class="{'company-management':management}"
    :data-site-theme="siteTheme"
  >
    <dialog
      v-if="opening"
      ref="introDialog"
      class="company-intro-dialog"
      @cancel.prevent="endIntro"
      @click.self="endIntro"
      :aria-label="tr('مرحبًا بك في موقع الشركة')"
    >
      <div class="company-opening">
        <button
          type="button"
          class="company-intro-close"
          @click="endIntro"
          :aria-label="tr('تخطي المقدمة')"
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
            ><span>{{ tr(icon) }}</span>
          </div>
          <div class="company-emblem">
            <img v-if="p.logo" :src="p.logo" alt="" /><span v-else="">{{
              tr(p.name)
            }}</span>
          </div>
        </div>
        <h2>{{ tr(p.name) }}</h2>
        <p>{{ tr(p.tagline) }}</p>
        <div class="company-opening-progress"><i></i></div>
      </div>
    </dialog>
    <p v-if="loading" class="read-loading notice" role="status">جارٍ تحميل موقع الشركة…</p><p v-if="saveError &amp;&amp; !record" class="notice warn" role="alert">{{ saveError }}</p><p v-if="success" class="notice" role="status">{{ success }}</p><section v-if="record" class="masal-site company-profile-content">
      <div class="site-tools">
        <button
          v-if="editable&amp;&amp;!editing"
          class="btn primary"
          @click="edit"
        >
          {{ tr("إدارة الموقع") }}
        </button>
      </div>
      <form v-if="editing" class="card site-editor" @submit.prevent="save">
        <div class="site-editor-tabs">
          <button
            type="button"
            v-for="t in [...sections, ['inbox', 'طلبات العملاء']]"
            :key="t[0]"
            :class="['btn', { primary: tab === t[0] }]"
            @click="tab = t[0]" :disabled="pending"
          >
            {{ tr(t[1]) }}
            <span
              v-if="t[0] === 'inbox' &amp;&amp; !inboxLoading"
              >{{ tr(inboxMeta.new_count) }}</span
            >
          </button>
        </div>
        <label v-if="tab !== 'inbox'" class="site-switch"
          ><input type="checkbox" v-model="draft.visibility[tab]" />{{
            tr("إظهار القسم")
          }}</label
        >
        <div v-if="tab === 'about'" class="formgrid">
          <label
            >{{ tr("اسم الشركة")
            }}<input v-model="draft.name" required="" /></label
          ><label
            >{{ tr("عبارة الواجهة")
            }}<input v-model="draft.tagline" /></label
          ><label
            >{{ tr("شعار الشركة")
            }}<image-attachment
              v-model="draft.logo"
              @busy="busy.logo = $event"
            ></image-attachment></label
          ><label class="full"
            >{{ tr("نبذة عن الشركة")
            }}<textarea v-model="draft.about" rows="5"></textarea></label
          ><label
            >{{ tr("العنوان") }}<input v-model="draft.address" /></label
          ><label
            >{{ tr("الموقع الإلكتروني")
            }}<input type="url" v-model="draft.website" dir="ltr"
          /></label>
        </div>
        <template
          v-else-if="
            ['slides', 'activities', 'offers', 'projects'].includes(tab)
          "
          ><p v-if="tab === 'slides'" class="caption">
            {{
              tr(
                "الصورة اختيارية؛ بدون صورة يظهر العنوان والتفاصيل بخلفية التصميم.",
              )
            }}
          </p>
          <p v-if="!draft.visibility[tab]" class="notice warn">
            {{
              tr(
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
                >{{ tr("العنوان")
                }}<input
                  v-model="item.title"
                  :placeholder="tr(item.caption)" /></label
              ><label
                >{{ tr("الصورة")
                }}<image-attachment
                  v-model="item.image"
                  @busy="busy[tab + i] = $event"
                ></image-attachment></label
              ><label class="full"
                >{{ tr("التفاصيل")
                }}<textarea
                  v-model="item.description"
                  rows="3"
                ></textarea></label
              ><label
                >{{ tr("الرابط")
                }}<input type="url" v-model="item.link" dir="ltr" /></label
              ><label class="site-switch"
                ><input type="checkbox" v-model="item.visible" />{{
                  tr("إظهار العنصر")
                }}</label
              >
            </div>
            <div class="actions">
              <button
                type="button"
                class="btn small"
                @click="move(i, -1)"
                :disabled="pending || i === 0"
              >
                ↑</button
              ><button
                type="button"
                class="btn small"
                @click="move(i, 1)"
                :disabled="pending || i === draft[tab].length - 1"
              >
                ↓</button
              ><button
                type="button"
                class="btn small"
                @click="draft[tab].splice(i, 1)" :disabled="pending"
              >
                {{ tr("حذف") }}
              </button>
            </div>
          </article>
          <button type="button" class="btn" @click="add" :disabled="pending || draft[tab].length &gt;= 30">
            {{ tr("إضافة ")
            }}{{ tr(sections.find(x=&gt;x[0]===tab)[1]==='السلايدر'?'صورة':'عنصر') }}
          </button></template
        >
        <template v-else-if="tab === 'social'"
          ><div
            v-for="(item, i) in draft.social"
            class="site-editor-item formgrid"
          >
            <label
              >{{ tr("المنصة")
              }}<input
                v-model="item.name"
                :placeholder="tr('اسم منصة التواصل')" /></label
            ><label
              >{{ tr("الرابط")
              }}<input type="url" v-model="item.url" dir="ltr" /></label
            ><button
              type="button"
              class="btn small"
              @click="draft.social.splice(i, 1)"
            >
              {{ tr("حذف") }}
            </button>
          </div>
          <button
            type="button"
            class="btn"
            @click="draft.social.push({ name: '', url: '' })" :disabled="pending || draft.social.length &gt;= 30"
          >
            {{ tr("إضافة موقع تواصل") }}
          </button></template
        >
        <div v-else-if="tab === 'care'" class="formgrid">
          <label
            >{{ tr("الهاتف")
            }}<input v-model="draft.phone" type="tel" dir="ltr" /></label
          ><label
            >{{ tr("البريد الإلكتروني")
            }}<input v-model="draft.email" type="email" dir="ltr" /></label
          ><label
            >{{ tr("واتساب مع رمز الدولة")
            }}<input v-model="draft.whatsapp" type="tel" dir="ltr" /></label
          ><label
            >{{ tr("أوقات خدمة العملاء") }}<input v-model="draft.hours"
          /></label>
        </div>
        <div v-else-if="tab === 'inbox'"><div class="site-inbox-filters"><input v-model="inboxFilter.q" placeholder="بحث طلبات العملاء" aria-label="بحث طلبات العملاء" maxlength="200"><select v-model="inboxFilter.status" aria-label="حالة الطلب"><option value="">كل الحالات</option><option value="new">جديدة</option><option value="followed">تمت المتابعة</option></select><button type="button" class="btn" :disabled="inboxLoading" @click="loadInbox()">بحث</button></div><p class="read-loading" v-if="inboxLoading" role="status">جارٍ تحميل الطلبات…</p><div class="site-inbox-pagination"><button type="button" class="btn small" :disabled="inboxLoading || inboxMeta.current_page &lt;= 1" @click="loadInbox(inboxMeta.current_page - 1)">السابق</button><span>{{ inboxMeta.current_page }} / {{ inboxMeta.last_page }}</span><button type="button" class="btn small" :disabled="inboxLoading || inboxMeta.current_page &gt;= inboxMeta.last_page" @click="loadInbox(inboxMeta.current_page + 1)">التالي</button></div>
          <p v-if="!inboxLoading &amp;&amp; !inbox.length" class="empty">
            {{ tr("لا توجد طلبات") }}
          </p>
          <article v-for="r in inbox" class="site-editor-item">
            <div class="rowline">
              <b>{{ tr(r.name) }}</b
              ><span class="badge">{{ tr(r.status === 'new' ? 'جديدة' : 'تمت المتابعة') }}</span>
            </div>
            <small
              >{{ tr(r.contact) }} ·
              {{ tr(formatTime(r.time)) }}</small
            >
            <p class="site-copy">{{ tr(r.message) }}</p>
            <button type="button" class="btn small" @click="resolve(r.id)" :disabled="resolving !== null">
              {{
                tr(r.status === "new" ? "تمت المتابعة" : "إعادة فتح")
              }}
            </button>
          </article>
        </div>
        <p v-if="saveError" class="notice warn" role="alert">
          {{ tr(saveError) }}
        </p>
        <div class="formfoot">
          <button type="button" class="btn" @click="cancelEdit">
            {{ tr("إلغاء") }}</button
          ><button class="btn primary" :disabled="pending || loading">
            {{ tr("حفظ") }}
          </button>
        </div>
      </form>
      <div v-else="" class="site-surface site-premium">
        <div class="site-ambient" aria-hidden="true"><i></i><i></i><i></i></div>
        <div v-if="p.phone || p.email || visible('social')" class="site-topbar">
          <div>
            <a v-if="p.phone" :href="phone(p.phone)" dir="ltr">{{
              tr(p.phone)
            }}</a
            ><a v-if="p.email" :href="'mailto:' + p.email">{{ p.email }}</a>
          </div>
          <div v-if="visible('social')">
            <a
              v-for="s in p.social.filter(s=&gt;safe(s.url))"
              :href="safe(s.url)"
              target="_blank"
              rel="noopener noreferrer"
              >{{ tr(s.name) }}</a
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
              tr(
                siteTheme === 'dark'
                  ? 'تفعيل الوضع النهاري'
                  : 'تفعيل الوضع الليلي',
              )
            "
          >
            {{ tr(siteTheme === "dark" ? "☀ نهاري" : "☾ ليلي") }}</button
          ><a
            class="site-wordmark"
            href="#company"
            @click.prevent="
              jumpHero()
            "
            ><img v-if="p.logo" :src="p.logo" :alt="tr(p.name)" /><span>{{
              tr(p.name)
            }}</span
            ><i></i></a
          ><button
            class="site-menu-toggle"
            @click="profileNavOpen = !profileNavOpen"
            :aria-expanded="profileNavOpen"
            aria-controls="profile-navigation"
          >
            {{ tr(profileNavOpen ? "إغلاق ×" : "القائمة ☰") }}
          </button>
          <nav
            id="profile-navigation"
            :class="{ 'is-open': profileNavOpen }"
            :aria-label="tr('أقسام موقع الشركة')"
          >
            <button v-for="[key, label] in links" @click="jump(key)">
              {{ tr(label) }}
            </button>
          </nav>
          <button
            v-if="visible('care')"
            class="site-header-contact"
            @click="jump('care')"
          >
            {{ tr("لنتواصل ") }}<span>↗</span>
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
              :alt="tr(current.title || current.caption)"
              class="site-hero-image"
            /><span class="site-visual-mark" aria-hidden="true"
              >{{
                tr(String((index % slides.length) + 1).padStart(2, "0"))
              }}
              / {{ tr(String(slides.length).padStart(2, "0")) }}</span
            >
          </div>
          <div v-if="!current?.image" class="site-quick-panel">
            <h2>{{ tr("اكتشف ") }}{{ tr(p.name) }}</h2>
            <button
              v-for="[key, label] in links.slice(0, 5)"
              @click="jump(key)"
            >
              <span class="site-quick-icon">{{
                tr(
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
              ><span>{{ tr(label) }}</span
              ><span>‹</span>
            </button>
            <p v-if="!links.length">{{ tr(p.tagline) }}</p>
          </div>
          <div class="site-hero-content">
            <span class="site-eyebrow"
              ><i aria-hidden="true"></i>{{ tr(p.name) }}</span
            >
            <h1>
              {{
                tr(
                  current?.title || current?.caption || p.tagline || p.name,
                )
              }}
            </h1>
            <p v-if="current?.description || p.about">
              {{ tr(current?.description || p.about) }}
            </p>
            <div class="site-hero-actions">
              <a
                v-if="safe(current?.link)"
                :href="safe(current.link)"
                target="_blank"
                rel="noopener noreferrer"
                class="site-button"
                >{{ tr("اكتشف المزيد ↗") }}</a
              ><button
                v-if="visible('care')"
                class="site-button site-contact-action"
                @click="jump('care')"
              >
                {{ tr("تواصل معنا ←") }}</button
              ><button
                v-if="visible('about')"
                class="site-text-link"
                @click="jump('about')"
              >
                {{ tr("تعرّف على ") }}{{ tr(p.name) }}
              </button>
            </div>
          </div>
          <div v-if="slides.length&gt;1" class="site-slider-controls">
            <button @click="next(-1)" :aria-label="tr('الصورة السابقة')">
              →</button
            ><button
              v-for="(_, i) in slides"
              :class="{ selected: index % slides.length === i }"
              @click="index = i"
              :aria-label="tr('الصورة ' + (i + 1))"
              :aria-current="index % slides.length === i ? 'true' : undefined"
            >
              {{ tr(String(i + 1).padStart(2, "0")) }}</button
            ><button @click="next(1)" :aria-label="tr('الصورة التالية')">
              ←</button
            ><button
              @click="autoplayPaused = !autoplayPaused"
              :aria-label="
                tr(autoplayPaused ? 'تشغيل السلايدر' : 'إيقاف السلايدر')
              "
            >
              {{ tr(autoplayPaused ? "▶" : "Ⅱ") }}
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
              <span class="site-eyebrow">{{ tr(p.name) }}</span>
              <h2>{{ tr(sections.find(x=&gt;x[0]===key)[1]) }}</h2>
            </div>
            <span>{{
              tr(String(items(key).length).padStart(2, "0"))
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
                  :alt="tr(item.title)"
                  loading="lazy"
                /><span v-else="">{{
                  tr(String(i + 1).padStart(2, "0"))
                }}</span>
              </div>
              <div class="site-card-body">
                <h3>{{ tr(item.title || item.caption) }}</h3>
                <p v-if="item.description">{{ tr(item.description) }}</p>
                <a
                  v-if="safe(item.link)"
                  :href="safe(item.link)"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="site-text-link"
                  >{{ tr("عرض التفاصيل ↗") }}</a
                ><button
                  v-else-if="item.description"
                  class="site-text-link"
                  @click="details = item"
                >
                  {{ tr("عرض التفاصيل ↗") }}
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
            <span class="site-eyebrow">{{ tr("عن الشركة") }}</span>
            <h2>{{ tr(p.name) }}</h2>
          </div>
          <div>
            <p class="site-copy">{{ tr(p.about) }}</p>
            <a
              v-if="safe(p.website)"
              :href="safe(p.website)"
              target="_blank"
              rel="noopener noreferrer"
              class="site-text-link"
              >{{ tr("الموقع الإلكتروني ↗") }}</a
            >
          </div>
        </section>
        <section
          v-if="visible('social')"
          id="company-social"
          class="site-section site-social"
        >
          <h2>{{ tr("تابعنا") }}</h2>
          <div>
            <a
              v-for="s in p.social.filter(s=&gt;safe(s.url))"
              :href="safe(s.url)"
              target="_blank"
              rel="noopener noreferrer"
              >{{ tr(s.name) }} ↗</a
            >
          </div>
        </section>
        <section
          v-if="visible('care')"
          id="company-care"
          class="site-section site-care"
        >
          <div>
            <span class="site-eyebrow">{{ tr("خدمة العملاء") }}</span>
            <h2>{{ tr("يسعدنا تواصلك") }}</h2>
            <p v-if="p.hours">{{ tr(p.hours) }}</p>
            <div class="site-contact-links">
              <a v-if="p.phone" :href="phone(p.phone)"
                ><small>{{ tr("اتصل بنا") }}</small
                ><b dir="ltr">{{ tr(p.phone) }}</b></a
              ><a v-if="p.email" :href="'mailto:' + p.email"
                ><small>{{ tr("البريد الإلكتروني") }}</small
                ><b>{{ p.email }}</b></a
              ><a
                v-if="p.whatsapp"
                :href="wa()"
                target="_blank"
                rel="noopener noreferrer"
                >{{ tr("واتساب ↗") }}</a
              ><span v-if="p.address">{{ tr(p.address) }}</span>
            </div>
          </div>
          <form @submit.prevent="send" class="site-contact-form"><label class="company-honeypot" aria-hidden="true">الموقع الإلكتروني<input v-model="inquiry.website_honeypot" name="website_honeypot" autocomplete="off" tabindex="-1"></label>
            <label
              >{{ tr("الاسم")
              }}<input
                v-model="inquiry.name"
                maxlength="120"
                required=""
                autocomplete="name" /></label
            ><label
              >{{ tr("الهاتف أو البريد الإلكتروني")
              }}<input
                v-model="inquiry.contact"
                maxlength="150"
                required="" /></label
            ><label
              >{{ tr("الرسالة")
              }}<textarea
                v-model="inquiry.message"
                maxlength="3000"
                rows="4"
                required=""
              ></textarea></label
            ><button class="site-button" :disabled="sending">
              {{ tr(sending ? "جارٍ الإرسال…" : "إرسال") }}
            </button>
            <p v-if="saveError" role="alert">{{ tr(saveError) }}</p>
            <p v-if="sent" role="status">
              {{ tr("تم تسجيل رسالتك لدى إدارة الشركة.") }}
            </p>
            <div v-if="sent && trackingLink" class="site-inquiry-link"><p>احتفظ بهذا الرابط؛ يتيح لك متابعة رسالتك والردود عليها.</p><input :value="trackingLink" readonly dir="ltr" aria-label="رابط متابعة الرسالة"><div class="actions"><button type="button" class="site-button" @click="copyTracking">نسخ رابط المتابعة</button><a :href="trackingLink" class="site-button">متابعة الرسالة والردود</a></div><small v-if="copyStatus" role="status">{{copyStatus}}</small></div>
          </form>
        </section>
        <footer class="site-footer">
          <b>{{ tr(p.name) }}</b
          ><span
            >© {{ tr(new Date().getFullYear())
            }}{{ tr(" · جميع الحقوق محفوظة") }}</span
          ><button v-if="!publicPage" @click="dismiss">
            {{ tr("رجوع إلى الصفحة السابقة ←") }}
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
          :aria-label="tr(details.title)"
        >
          <div class="cardhead">
            <h2>{{ tr(details.title) }}</h2>
            <button
              class="iconbtn"
              @click="details = null"
              :aria-label="tr('إغلاق')"
            >
              ×
            </button>
          </div>
          <img
            v-if="details.image"
            :src="details.image"
            :alt="tr(details.title)"
            class="site-detail-image"
          />
          <p class="site-copy">{{ tr(details.description) }}</p>
          <button class="btn" @click="details = null">
            {{ tr("إغلاق") }}
          </button>
        </section>
      </div>
    </section>
  </section>
</template>

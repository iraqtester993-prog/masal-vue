<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["user-map"]);
export default options;
</script>

<template>
  <section v-if="permitted" class="card user-map-page">
    <div class="support-section-heading">
      <h2>{{ $root.tr("خريطة الحسابات والمستخدمين") }}</h2>
    </div>
    <div class="user-map-filters">
      <label
        >{{ $root.tr("بحث")
        }}<input
          v-model="query"
          :placeholder="$root.tr('اسم المستخدم أو الحساب')"
          :aria-label="$root.tr('بحث مستخدمي الخريطة')"
      /></label>
      <label
        >{{ $root.tr("نوع الحساب")
        }}<select v-model="type">
          <option value="">{{ $root.tr("الكل") }}</option>
          <option value="main">{{ $root.tr("وكيل رئيسي") }}</option>
          <option value="sub">{{ $root.tr("فرع") }}</option>
          <option value="pos">{{ $root.tr("نقطة بيع") }}</option>
          <option value="employee">{{ $root.tr("الموظفون") }}</option>
        </select></label
      >
      <label
        >{{ $root.tr("الحساب وتابعوه")
        }}<select v-model="branch">
          <option value="">{{ $root.tr("كل النطاق المسموح") }}</option>
          <option v-for="a in branches" :key="a.id" :value="a.id">
            {{ $root.tr(a.name) }}
          </option>
        </select></label
      >
      <div class="map-connection-filter">
        <span>{{ $root.tr("حالة الاتصال") }}</span>
        <div role="group" :aria-label="$root.tr('فلترة حالة الاتصال')">
          <button
            class="btn"
            :aria-pressed="status === ''"
            @click="status = ''"
          >
            {{ $root.tr("الكل") }}</button
          ><button
            class="btn"
            :aria-pressed="status === 'online'"
            @click="status = 'online'"
          >
            <i class="map-status-dot online" aria-hidden="true"></i
            >{{ $root.tr("متصل") }}</button
          ><button
            class="btn"
            :aria-pressed="status === 'offline'"
            @click="status = 'offline'"
          >
            <i class="map-status-dot" aria-hidden="true"></i
            >{{ $root.tr("غير متصل") }}
          </button>
        </div>
      </div>
      <button class="btn" :aria-pressed="custom" @click="custom = !custom">
        {{ $root.tr("اختيار مخصص (") }}{{ $root.tr(selected.length) }})</button
      ><button class="btn" @click="reset">{{ $root.tr("عرض الكل") }}</button
      ><button class="btn primary map-fit-results" @click="fit">
        {{ $root.tr("عرض نتائج البحث (") }}{{ $root.tr(rows.length) }})
      </button>
    </div>
    <div v-if="custom" class="map-custom">
      <div>
        <label v-for="u in options" :key="u.id"
          ><input type="checkbox" v-model="selected" :value="u.id" />{{
            $root.tr(u.name)
          }}
          · {{ $root.tr(u.kind) }}</label
        >
      </div>
      <p v-if="!selected.length" class="caption">
        {{ $root.tr("اختر مستخدمًا") }}
      </p>
    </div>
    <div class="map-network-legend">
      <span v-for="n in networks" :key="n.id"
        ><i :style="{ backgroundColor: n.color }"></i
        >{{ $root.tr(n.name) }}</span
      >
    </div>
    <div class="map-legend">
      <span>{{ $root.tr("🟢 متصل") }}</span
      ><span>{{ $root.tr("⚪ غير متصل") }}</span
      ><span>{{ $root.tr("◆ وكيل · ■ فرع · ▲ نقطة · ● موظف") }}</span>
    </div>
    <div v-if="tileError" class="notice">
      {{ $root.tr("تعذر تحميل بعض أجزاء الخريطة ")
      }}<button class="btn small" @click="retryTiles">
        {{ $root.tr("إعادة المحاولة") }}
      </button>
    </div>
    <div class="user-map-layout">
      <div class="map-display">
        <div
          ref="canvas"
          class="user-map-canvas"
          :aria-label="$root.tr('خريطة مواقع المستخدمين')"
        ></div>
      </div>
      <aside class="map-people">
        <div v-if="clusterIds.length">
          <b>{{ $root.tr("مستخدمون في المواقع المتقاربة") }}</b
          ><button
            v-for="u in rows.filter(u=&gt;clusterIds.includes(u.id))"
            :key="u.id"
            class="map-person"
            @click="choose(u)"
          >
            {{ $root.tr(u.name) }} · {{ $root.tr(u.kind) }}
          </button>
        </div>
        <h3>{{ $root.tr("المستخدمون · ") }}{{ $root.tr(rows.length) }}</h3>
        <div
          v-for="u in listed"
          :key="u.id"
          class="map-person-row"
          :class="{'details-visible':selectedId===u.id&amp;&amp;detailsOpen}"
          :style="{ '--network-color': u.networkColor }"
        >
          <button
            class="map-person"
            :class="{ selected: selectedId === u.id }"
            @click="choose(u)"
          >
            <span
              >{{ $root.tr(u.online ? "🟢" : "⚪") }}
              {{ $root.tr(u.name) }}</span
            ><small>{{ $root.tr(u.kind) }} · {{ $root.tr(u.account) }}</small
            ><small>{{ $root.tr(u.source) }}</small></button
          ><button
            class="btn small map-details-button"
            @click="showDetails(u)"
            :aria-label="$root.tr('تفاصيل ' + u.name)"
          >
            {{ $root.tr("التفاصيل") }}
          </button>
          <section
            v-if="selectedId===u.id&amp;&amp;detailsOpen"
            ref="userPopup"
            class="map-person-detail map-user-details"
            role="region"
            :aria-label="$root.tr('تفاصيل المستخدم: ' + u.name)"
            tabindex="-1"
            @keydown.esc.stop="closeUserPopup"
          >
            <button
              class="iconbtn map-popup-close"
              @click="closeUserPopup"
              :aria-label="$root.tr('إغلاق تفاصيل المستخدم')"
            >
              ×
            </button>
            <h3>{{ $root.tr(u.name) }}</h3>
            <p>{{ $root.tr(u.kind) }} · {{ $root.tr(u.account) }}</p>
            <p>
              {{ $root.tr(u.online ? "🟢 متصل" : "⚪ غير متصل")
              }}{{ $root.tr(u.active ? "" : " · الحساب موقوف") }}
            </p>
            <dl>
              <template v-if="u.parentName"
                ><dt>{{ $root.tr("الحساب التابع له") }}</dt>
                <dd>{{ $root.tr(u.parentName) }}</dd></template
              ><template v-if="u.phone"
                ><dt>{{ $root.tr("رقم الهاتف") }}</dt>
                <dd>{{ $root.tr(u.phone) }}</dd></template
              ><template v-if="u.city || u.address"
                ><dt>{{ $root.tr("العنوان") }}</dt>
                <dd>
                  {{
                    $root.tr([u.city, u.address].filter(Boolean).join(" · "))
                  }}
                </dd></template
              >
              <dt>{{ $root.tr("آخر ظهور — بغداد") }}</dt>
              <dd>{{ $root.tr(time(u.lastSeen)) }}</dd>
              <dt>{{ $root.tr("الموقع") }}</dt>
              <dd>{{ $root.tr(u.source) }}</dd>
              <dt>{{ $root.tr("وقت آخر موقع — بغداد") }}</dt>
              <dd>{{ $root.tr(time(u.locationTime)) }}</dd>
              <template v-if="u.accuracy != null"
                ><dt>{{ $root.tr("دقة الموقع") }}</dt>
                <dd>
                  {{ $root.tr("حوالي ") }}{{ $root.tr(Math.round(u.accuracy))
                  }}{{ $root.tr(" متر") }}
                </dd></template
              >
            </dl>
            <p v-if="u.location" dir="ltr">
              {{ $root.tr(u.location.lat.toFixed(5)) }},
              {{ $root.tr(u.location.lng.toFixed(5)) }}
            </p>
          </section>
        </div>
        <p v-if="!rows.length" class="empty">
          {{ $root.tr("لا توجد نتائج مطابقة") }}
        </p>
        <div class="map-paging">
          <button
            class="btn small"
            :disabled="listPage&lt;=1"
            @click="listPage--"
          >
            {{ $root.tr("السابق") }}</button
          ><span
            >{{ $root.tr(Math.min(listPage, pages)) }} /
            {{ $root.tr(pages) }}</span
          ><button
            class="btn small"
            :disabled="listPage&gt;=pages"
            @click="listPage++"
          >
            {{ $root.tr("التالي") }}
          </button>
        </div>
      </aside>
    </div>
  </section>
  <div v-else="" class="card empty">
    {{ $root.tr("ليس لديك صلاحية عرض المواقع") }}
  </div>
</template>

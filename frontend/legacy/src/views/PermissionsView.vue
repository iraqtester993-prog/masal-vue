<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div v-if="!permissionDetailsOpen" class="card permission-directory">
    <div class="permission-directory-head">
      <b>{{ tr("اسم الصلاحية") }}</b
      ><button
        class="btn primary"
        v-permit="can('permissions.create')"
        @click="newPermissionProfile"
      >
        ＋ {{ tr("إضافة صلاحية") }}
      </button>
    </div>
    <article
      v-for="p in permissionProfiles"
      :key="p.id"
      class="permission-role-row"
    >
      <div class="permission-role-identity">
        <span class="permission-role-icon" aria-hidden="true"
          ><svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="1.6"
          >
            <path d="M12 3 4 6v6c0 5 8 9 8 9s8-4 8-9V6z"></path>
            <path d="m8 12 3 3 5-6"></path></svg
        ></span>
        <div>
          <button
            class="permission-role-name"
            @click="openPermissionProfile(p, true)"
          >
            {{ $root.tr(p.name) }}</button
          ><span class="badge" :class="{ neutral: !p.active }">{{
            tr(p.active ? "مفعلة" : "معطلة")
          }}</span>
        </div>
      </div>
      <div class="actions permission-role-actions">
        <button class="btn small" @click="openPermissionProfile(p, true)">
          {{ tr("معاينة") }}</button
        ><button
          class="btn small"
          :disabled="!canChangeProfile(p, 'edit')"
          @click="openPermissionProfile(p, false)"
        >
          {{ tr("تعديل") }}</button
        ><button
          class="btn small"
          :disabled="!canChangeProfile(p, 'toggle')"
          @click="toggleProfileRow(p)"
        >
          {{ tr(p.active ? "تعطيل" : "تفعيل") }}</button
        ><button
          class="btn small danger"
          :disabled="!canChangeProfile(p, 'delete')"
          @click="askDeleteProfile(p)"
        >
          {{ tr("حذف") }}
        </button>
      </div>
    </article>
    <div v-if="!permissionProfiles.length" class="empty">
      {{ tr("لا توجد صلاحيات مضافة") }}
    </div>
  </div>
  <template v-else=""
    ><div class="card permission-editor-toolbar">
      <button class="btn" @click="closePermissionDetails">
        {{ tr("رجوع") }}</button
      ><label
        >{{ tr("اسم الصلاحية")
        }}<input
          v-model="profileName"
          :disabled="!permissionEditable"
          :placeholder="tr('مثال: محاسب أو موظف دعم')"
          maxlength="80" /></label
      ><label
        >{{ tr("البحث في الصلاحيات")
        }}<input
          v-model="permissionSearch"
          type="search"
          :placeholder="tr('ابحث عن قسم أو إجراء…')" /></label
      ><span class="badge"
        >{{ $root.tr(permissionStats.allowed) }} {{ tr("صلاحية مفعلة") }}</span
      ><button
        v-if="!permissionPreview"
        class="btn primary"
        :disabled="
          !permissionEditable ||
          !permissionStats.changes ||
          !profileName.trim() ||
          !permissionStats.allowed
        "
        @click="savePermissionDetails"
      >
        {{ tr("حفظ") }}
      </button>
    </div>
    <template v-if="permissionTarget"
      ><div
        v-if="!permissionEditable&amp;&amp;!permissionPreview"
        class="notice"
      >
        {{
          tr(
            "عرض فقط: لا يمكن تعديل نوع صلاحية حسابك الحالي أو موظفين خارج نطاقك.",
          )
        }}
      </div>
      <div class="card permission-selection-bar">
        <label class="permission-select-all"
          ><input
            type="checkbox"
            :checked="permissionSelection().all"
            :indeterminate.prop="permissionSelection().mixed"
            :disabled="!permissionEditable || !permissionSelection().total"
            @change="togglePermissionSelection(null, $event.target.checked)"
          /><span
            ><b>{{ tr("تحديد كل الصلاحيات") }}</b
            ><small>{{
              tr("يشمل جميع المجموعات حتى مع وجود بحث أو فلتر")
            }}</small></span
          ></label
        >
        <div class="actions">
          <span class="badge"
            >{{ $root.tr(permissionSelection().selected) }} /
            {{ $root.tr(permissionSelection().total) }}</span
          ><button
            class="btn small"
            :disabled="!permissionEditable"
            @click="togglePermissionSelection(null, false)"
          >
            {{ tr("إلغاء تحديد الكل") }}</button
          ><button
            class="btn ghost small"
            :disabled="!permissionEditable"
            @click="resetPermissionSelection"
          >
            {{ tr("مسح الاختيارات") }}
          </button>
        </div>
      </div>

      <nav
        class="permission-section-cards"
        :aria-label="$root.tr('أقسام الصلاحيات')"
      >
        <button
          v-for="g in permissionGroups"
          :key="g.module"
          type="button"
          class="permission-section-card"
          @click="openPermissionModule(g.module)"
          aria-haspopup="dialog"
        >
          <span class="permission-section-symbol" aria-hidden="true"
            ><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.6"
            >
              <path :d="navIconPath(g.module)"></path></svg></span
          ><strong>{{ tr(g.title) }}</strong
          ><span class="badge"
            >{{ $root.tr(permissionSelection(g.module).selected) }} /
            {{ $root.tr(permissionSelection(g.module).total) }}</span
          ><span aria-hidden="true">‹</span>
        </button>
      </nav>
      <teleport to="body"
        ><dialog
          v-if="permissionModuleDialog"
          ref="permissionModuleDialog"
          class="permission-module-dialog"
          @cancel.prevent="permissionModuleDialog = false"
          :aria-label="$root.tr('صلاحيات القسم')"
        >
          <div class="permission-popup-heading">
            <h2>{{ tr(shownPermissionGroups[0]?.title || "الصلاحيات") }}</h2>
            <button
              type="button"
              class="iconbtn"
              @click="permissionModuleDialog = false"
              :aria-label="$root.tr('إغلاق صلاحيات القسم')"
            >
              ×
            </button>
          </div>
          <div class="permission-grid permission-checkbox-grid">
            <section
              v-for="group in shownPermissionGroups"
              :key="group.module"
              class="card permission-check-module"
              :data-permission-module="group.module"
            >
              <div class="permission-check-header">
                <label class="permission-select-all"
                  ><input
                    type="checkbox"
                    :checked="permissionSelection(group.module).all"
                    :indeterminate.prop="
                      permissionSelection(group.module).mixed
                    "
                    :disabled="
                      !permissionEditable ||
                      !permissionSelection(group.module).total
                    "
                    :aria-label="tr('تحديد كل صلاحيات') + ' ' + tr(group.title)"
                    @change="
                      togglePermissionSelection(
                        group.module,
                        $event.target.checked,
                      )
                    "
                  /><span
                    ><b>{{ tr(group.title) }}</b
                    ><small>{{ tr("تحديد كل صلاحيات المجموعة") }}</small></span
                  ></label
                >
                <div class="actions">
                  <span class="badge"
                    >{{
                      $root.tr(permissionSelection(group.module).selected)
                    }}
                    /
                    {{
                      $root.tr(permissionSelection(group.module).total)
                    }}</span
                  ><button
                    class="btn small"
                    :disabled="!permissionEditable"
                    @click="togglePermissionSelection(group.module, true)"
                  >
                    {{ $root.tr("تفعيل الكل") }}</button
                  ><button
                    class="btn small"
                    :disabled="!permissionEditable"
                    @click="togglePermissionSelection(group.module, false)"
                  >
                    {{ $root.tr("إلغاء الكل") }}
                  </button>
                </div>
              </div>
              <div
                class="permission-category"
                v-for="bucket in permissionBuckets(group.items)"
                :key="bucket.title"
              >
                <h3>{{ tr(bucket.title) }}</h3>
                <div class="permission-check-items">
                  <label
                    v-for="p in bucket.items"
                    :key="p.key"
                    class="permission-check-item permission-action-row"
                    :title="tr(permissionHint(p))"
                    :class="{
                      'is-selected': permissionEffective(p.key),
                      'is-locked': permissionCheckDisabled(p),
                      'is-sensitive': p.sensitive,
                    }"
                    ><span class="permission-action-copy"
                      ><b>{{ tr(p.label) }}</b
                      ><small>{{ tr(permissionHint(p)) }}</small></span
                    ><span
                      v-if="p.sensitive"
                      class="permission-sensitive-label"
                      >{{ tr("حساسة") }}</span
                    ><input
                      type="checkbox"
                      :data-permission="p.key"
                      :checked="permissionEffective(p.key)"
                      :disabled="permissionCheckDisabled(p)"
                      :aria-label="tr(group.title + ' • ' + p.label)"
                      @change="togglePermissionCheck(p, $event.target.checked)"
                  /></label>
                </div>
              </div>
            </section>
          </div>
          <div class="permission-popup-footer">
            <button class="btn primary" @click="permissionModuleDialog = false">
              {{ $root.tr("رجوع للأقسام") }}
            </button>
          </div>
        </dialog></teleport
      >
      <div v-if="!permissionGroups.length" class="empty">
        {{ tr("لا توجد نتائج مطابقة") }}
      </div></template
    ></template
  >
</template>

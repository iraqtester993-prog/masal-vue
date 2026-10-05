<script>
import viewContext from "../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>
<template>
  <div
    class="card workspace-card"
    :class="{ 'users-workspace': page === 'users' }"
  >
    <page-actions
      v-if="
        ![
          'agents',
          'products',
          'providers',
          'users',
          'pos',
          'representatives',
          'posTypes',
        ].includes(page)
      "
    ></page-actions
    ><template v-if="page === 'users'"
      ><div
        v-if="actor.role === 'owner'"
        class="owner-summary"
        :aria-label="$root.tr('ملخص جميع الحسابات')"
      >
        <article v-for="item in accountSummary" class="card">
          <span class="summary-icon" aria-hidden="true"
            ><svg
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="1.7"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <path :d="navIconPath(item.page)"></path></svg></span
          ><span>{{ tr(item.label) }}</span
          ><strong>{{ $root.tr(item.value) }}</strong>
        </article>
      </div></template
    >
    <div v-if="page === 'agents'" class="agents-unified-toolbar">
      <div class="tabs">
        <button :class="{ active: !treeView }" @click="treeView = false">
          {{ tr("قائمة الوكلاء") }}</button
        ><button
          :class="{ active: treeView === true }"
          @click="treeView = true"
        >
          {{ tr("التوزيع التنظيمي") }}</button
        ><button
          :class="{ active: treeView === 'compact' }"
          @click="treeView = 'compact'"
        >
          {{ tr("عرض مختصر") }}
        </button>
      </div>
      <input
        v-model="agentToolbarSearch"
        :placeholder="tr('بحث بالاسم أو المحافظة…')"
        :aria-label="$root.tr('بحث الوكلاء والفروع')"
      /><select
        v-model="networkKind"
        :aria-label="$root.tr('عرض الوكلاء والنقاط')"
      >
        <option value="all">{{ $root.tr("الكل") }}</option>
        <option v-for="k in scopeFilterKinds" :value="k.id">
          {{ $root.tr(k.label) }}
        </option></select
      ><page-actions></page-actions>
    </div>
    <section
      v-if="page==='agents'&amp;&amp;treeView==='compact'"
      class="network-shell network-overview"
    >
      <div class="network-header">
        <div></div>
        <div class="outline-legend">
          <span class="legend-main">{{ tr("وكيل رئيسي") }}</span
          ><span class="legend-sub">{{ tr("وكيل فرعي") }}</span
          ><span class="legend-pos">{{ tr("نقطة بيع") }}</span>
        </div>
      </div>
      <div class="branch-list-panel">
        <ul class="branch-list-forest">
          <network-outline
            v-for="node in networkTree"
            :key="node.record.id"
            :node="node"
            :tr="tr"
            :expanded="outlineExpanded"
            @toggle="toggleOutline"
          ></network-outline>
        </ul>
      </div>
      <div v-if="!networkTree.length" class="empty">
        {{ tr("لا توجد بيانات في نطاق حسابك") }}
      </div>
    </section>
    <section
      v-else-if="page==='agents'&amp;&amp;treeView===true"
      class="network-shell"
    >
      <div class="network-header">
        <div></div>
        <div class="network-summary">
          <span
            ><b
              >{{ $root.tr(visibleAgents.filter(a=&gt;a.type==='رئيسي').length) }}</b
            >{{ tr("وكيل رئيسي") }}</span
          ><span
            ><b
              >{{ $root.tr(visibleAgents.filter(a=&gt;a.type!=='رئيسي'&amp;&amp;!s.agents.some(parent=&gt;parent.id===a.parent&amp;&amp;parent.type!=='رئيسي')).length) }}</b
            >{{ tr("وكيل فرعي") }}</span
          ><span
            ><b
              >{{ $root.tr(visibleAgents.filter(a=&gt;a.type!=='رئيسي'&amp;&amp;s.agents.some(parent=&gt;parent.id===a.parent&amp;&amp;parent.type!=='رئيسي')).length) }}</b
            >{{ tr("فرع فرعي") }}</span
          ><span
            ><b>{{ $root.tr(visiblePOS.length) }}</b
            >{{ tr("نقطة بيع") }}</span
          >
        </div>
      </div>
      <network-drill></network-drill>
    </section>
    <network-points-table
      v-else-if="page==='agents' &amp;&amp; networkKind==='pos'"
    ></network-points-table
    ><network-points-table v-else-if="page === 'pos'"></network-points-table
    ><provider-table v-else-if="page === 'providers'"></provider-table
    ><category-table v-else-if="page === 'products'"></category-table>
    <div v-else="" class="card">
      <div
        v-if="page !== 'agents'"
        class="toolbar"
        :class="{ 'users-toolbar': page === 'users' }"
      >
        <page-actions
          v-if="['users', 'representatives', 'posTypes'].includes(page)"
        ></page-actions
        ><input
          style="max-width: 300px"
          v-model="search"
          :placeholder="
            tr(
              page === 'users'
                ? 'بحث باسم الموظف أو البريد الإلكتروني…'
                : 'بحث بالاسم أو المحافظة…',
            )
          "
        /><span class="caption"
          >{{ tr(filteredRows.length) }}{{ tr(" سجل") }}</span
        >
      </div>
      <div class="tablewrap">
        <table>
          <thead>
            <tr>
              <th v-if="!['users', 'agents', 'pos'].includes(page)">
                {{ tr("المعرف") }}
              </th>
              <th v-for="col in schema.columns">{{ tr(col.label) }}</th>
              <th>{{ tr("الإجراءات") }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in filteredRows"
              :key="row.id"
              :draggable="page==='products' &amp;&amp; can('products.order') &amp;&amp; !search"
              @dragstart="
                dragProduct = row.id;
                $event.dataTransfer.setData('text/plain', row.id);
                $event.dataTransfer.effectAllowed = 'move';
              "
              @dragover.prevent=""
              @drop.prevent="dropProduct(row.id)"
              @dragend="dragProduct = ''"
            >
              <td
                v-if="!['users', 'agents', 'pos'].includes(page)"
                class="mono"
              >
                <span
                  v-if="page==='products' &amp;&amp; can('products.order') &amp;&amp; !search"
                  :title="$root.tr('اسحب لترتيب الفئة')"
                  >⠿ </span
                >{{ tr(row.id) }}
              </td>
              <td v-for="col in schema.columns">
                <span
                  v-if="col.key === 'active'"
                  class="badge"
                  :class="{ neutral: !row.active }"
                  >{{ tr(row.active ? "مفعل" : "موقوف") }}</span
                ><span v-else="">{{ tr(displayField(row, col)) }}</span>
              </td>
              <td>
                <div class="actions">
                  <button
                    v-if="page==='products'&amp;&amp;can('products.order')&amp;&amp;!search"
                    class="btn small"
                    @click="moveProduct(row.id, -1)"
                    :disabled="filteredRows[0]?.id === row.id"
                    :title="$root.tr('تحريك للأعلى')"
                    :aria-label="$root.tr('تحريك للأعلى')"
                  >
                    ↑</button
                  ><button
                    v-if="page==='products'&amp;&amp;can('products.order')&amp;&amp;!search"
                    class="btn small"
                    @click="moveProduct(row.id, 1)"
                    :disabled="filteredRows.at(-1)?.id === row.id"
                    :title="$root.tr('تحريك للأسفل')"
                    :aria-label="$root.tr('تحريك للأسفل')"
                  >
                    ↓</button
                  ><button
                    v-if="page==='agents'&amp;&amp;can('agents.view')"
                    class="btn small"
                    @click="showNetworkDetails('agents', row.id)"
                  >
                    {{ $root.tr("التفاصيل") }}</button
                  ><button
                    class="btn small"
                    v-permit="can(page + '.edit')"
                    @click="openEdit(row)"
                  >
                    {{tr(page==='users'&amp;&amp;row.role==='owner'?'تعديل الاسم':'تعديل')}}</button
                  ><button
                    v-if="'active' in row &amp;&amp; !(page==='users'&amp;&amp;row.role==='owner')"
                    class="btn small"
                    v-permit="can(page + '.toggle')"
                    @click="toggleEntity(row)"
                  >
                    {{ tr(row.active ? "إيقاف" : "تفعيل") }}</button
                  ><button
                    v-if="['agents','pos'].includes(page)&amp;&amp;canArchiveNetwork(page,row.id)"
                    class="btn small danger"
                    @click="askArchiveNetwork(page, row.id)"
                  >
                    {{ $root.tr("حذف") }}</button
                  ><button
                    v-if="['agents','pos'].includes(page)&amp;&amp;canManageCategories(page,row.id)"
                    class="btn small"
                    @click="openNetworkCategories(page, row.id)"
                  >
                    {{ tr("الفئات") }}</button
                  ><button
                    v-if="['agents','pos'].includes(page)&amp;&amp;canManageNetwork(page,row.id)"
                    class="btn small"
                    @click="openNetworkPermissions(page, row.id)"
                  >
                    {{ tr("صلاحيات التابع") }}</button
                  ><button
                    v-if="page==='users'&amp;&amp;actor.role==='owner'"
                    class="btn small"
                    @click="showAccountDetails(row)"
                  >
                    {{ tr("تفاصيل الحساب") }}</button
                  ><button
                    v-if="page==='users'&amp;&amp;row.role!=='owner'"
                    class="btn small"
                    v-permit="can('permissions.view')"
                    @click="editPermissions(row)"
                  >
                    {{ tr("الصلاحيات") }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="!filteredRows.length" class="empty">
        {{ tr("لا توجد نتائج مطابقة") }}
      </div>
    </div>
  </div>
</template>

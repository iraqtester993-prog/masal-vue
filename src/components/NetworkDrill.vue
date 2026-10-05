<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["network-drill"]);
export default options;
</script>

<template>
  <div class="network-drill">
    <p class="caption">
      {{
        $root.tr(
          "الفروع المباشرة ونقاط البيع بكامل شبكة الحساب · يظهر الوكيل المباشر بجانب كل نقطة",
        )
      }}
    </p>
    <section
      v-for="a in shownRoots"
      :key="a.id"
      class="drill-root"
      :style="{ '--agent-color': a.color || '#168baf' }"
    >
      <div class="drill-account">
        <div class="drill-name">
          <span class="badge">{{ $root.tr(vm.walletAccountType(a)) }}</span>
          <h3>{{ $root.tr(a.name) }}</h3>
          <small>{{ $root.tr(a.city || "—") }}</small>
        </div>
        <div class="drill-balance">
          <small>{{ $root.tr("رصيد البطاقات") }}</small
          ><strong
            >{{
              $root.tr(vm.money(vm.engine.serviceBalance(a.id, "voucher")))
            }}
            <small>{{ $root.tr("د.ع") }}</small></strong
          >
        </div>
        <div class="actions">
          <button
            class="btn"
            :class="{primary:rootId===a.id&amp;&amp;mode==='branches'}"
            @click="open(a.id, a.id, 'branches')"
          >
            {{ $root.tr("الفروع (") }}{{ $root.tr(branches(a.id)) }})</button
          ><button
            class="btn"
            :class="{primary:rootId===a.id&amp;&amp;mode==='points'}"
            @click="open(a.id, a.id, 'points')"
          >
            {{ $root.tr("نقاط الشبكة (") }}{{ $root.tr(points(a.id)) }})</button
          ><button class="btn small" @click="details(a)">
            {{ $root.tr("التفاصيل") }}</button
          ><button
            v-if="vm.canArchiveNetwork('agents', a.id)"
            class="btn small danger"
            @click="vm.askArchiveNetwork('agents', a.id)"
          >
            {{ $root.tr("حذف") }}</button
          ><button
            class="btn small"
            @click="
              vm.canManageCategories('agents', a.id)
                ? vm.openNetworkCategories('agents', a.id)
                : vm.previewAgentProducts(a)
            "
          >
            {{ $root.tr("الفئات") }}</button
          ><button
            v-if="vm.can('agents.edit')"
            class="btn small"
            @click="vm.openEdit(a)"
          >
            {{ $root.tr("تعديل") }}</button
          ><button
            v-if="vm.canManageNetwork('agents', a.id)"
            class="btn small"
            @click="vm.openNetworkPermissions('agents', a.id)"
          >
            {{ $root.tr("صلاحيات التابع") }}
          </button>
        </div>
      </div>
      <div v-if="rootId === a.id" class="drill-panel" data-no-pagination="">
        <div class="drill-path">
          <div>
            <template v-for="(item, i) in path"
              ><span v-if="i">‹</span
              ><button
                class="btn small"
                @click="open(rootId, item.id, 'branches')"
              >
                {{ $root.tr(item.name) }}
              </button></template
            >
          </div>
          <div class="actions">
            <button class="btn small" @click="back">
              {{ $root.tr("رجوع") }}</button
            ><button class="btn small" @click="rootId = ''">
              {{ $root.tr("إغلاق") }}
            </button>
          </div>
        </div>
        <div class="drill-tools">
          <div class="tabs">
            <button
              :class="{ active: mode === 'branches' }"
              @click="open(rootId, parentId, 'branches')"
            >
              {{ $root.tr("الفروع (")
              }}{{ $root.tr(branches(parentId)) }})</button
            ><button
              :class="{ active: mode === 'points' }"
              @click="open(rootId, parentId, 'points')"
            >
              {{ $root.tr("نقاط الشبكة (") }}{{ $root.tr(points(parentId)) }})
            </button>
          </div>
          <input
            type="search"
            v-model="query"
            :placeholder="$root.tr('بحث بالاسم أو المحافظة أو الهاتف')"
            :aria-label="$root.tr('بحث التابعين')"
          /><label
            >{{ $root.tr("عرض")
            }}<select
              v-model.number="size"
              :aria-label="$root.tr('عدد التابعين')"
            >
              <option v-for="n in [10, 25, 50, 100]" :value="n">
                {{ $root.tr(n) }}
              </option>
            </select></label
          >
        </div>
        <div class="tablewrap">
          <table>
            <thead>
              <tr>
                <th>{{ $root.tr("الاسم") }}</th>
                <th>{{ $root.tr("النوع") }}</th>
                <th>{{ $root.tr("رصيد البطاقات") }}</th>
                <th v-if="mode === 'branches'">
                  {{ $root.tr("الفروع / النقاط") }}
                </th>
                <th v-else="">{{ $root.tr("الوكيل المباشر") }}</th>
                <th>{{ $root.tr("الإجراء") }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="r in shown"
                :key="r.id"
                :style="
                  mode === 'branches'
                    ? { '--agent-row-color': r.color || '#168baf' }
                    : {}
                "
                :class="{ 'agent-colored-row': mode === 'branches' }"
              >
                <td>{{ $root.tr(r.name) }}</td>
                <td>{{ $root.tr(vm.walletAccountType(r)) }}</td>
                <td>
                  {{
                    $root.tr(
                      vm.money(vm.engine.serviceBalance(r.id, "voucher")),
                    )
                  }}{{ $root.tr(" د.ع") }}
                </td>
                <td v-if="mode === 'branches'">
                  {{ $root.tr(branches(r.id)) }} / {{ $root.tr(points(r.id)) }}
                </td>
                <td v-else="">
                  {{ $root.tr(vm.visibleAgents.find(a=&gt;a.id===r.agent)?.name||'—') }}
                </td>
                <td>
                  <div class="actions">
                    <button
                      v-if="mode === 'branches'"
                      class="btn small"
                      @click="open(rootId, r.id, 'branches')"
                    >
                      {{ $root.tr("فتح") }}</button
                    ><button class="btn small" @click="details(r)">
                      {{ $root.tr("التفاصيل") }}</button
                    ><button
                      v-if="
                        vm.canArchiveNetwork(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                      class="btn small danger"
                      @click="
                        vm.askArchiveNetwork(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                    >
                      {{ $root.tr("حذف") }}</button
                    ><button
                      v-if="mode==='branches'&amp;&amp;vm.can('agents.edit')"
                      class="btn small"
                      @click="vm.openEdit(r)"
                    >
                      {{ $root.tr("تعديل") }}</button
                    ><button
                      v-if="
                        vm.canManageCategories(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                      class="btn small"
                      @click="
                        vm.openNetworkCategories(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                    >
                      {{ $root.tr("الفئات") }}</button
                    ><button
                      v-if="
                        vm.canManageNetwork(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                      class="btn small"
                      @click="
                        vm.openNetworkPermissions(
                          mode === 'points' ? 'pos' : 'agents',
                          r.id,
                        )
                      "
                    >
                      {{ $root.tr("صلاحيات التابع") }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="!rows.length">
                <td colspan="5" class="empty">
                  {{ $root.tr("لا توجد نتائج مطابقة") }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="table-pagination-footer">
          <button class="btn small" :disabled="page&lt;=1" @click="page--">
            {{ $root.tr("السابق") }}</button
          ><span
            >{{ $root.tr("الصفحة ") }}{{ $root.tr(page) }}{{ $root.tr(" من ")
            }}{{ $root.tr(pages) }} · {{ $root.tr(rows.length)
            }}{{ $root.tr(" حساب") }}</span
          ><button class="btn small" :disabled="page&gt;=pages" @click="page++">
            {{ $root.tr("التالي") }}
          </button>
        </div>
      </div>
    </section>
    <p v-if="!roots.length" class="empty">
      {{ $root.tr("لا توجد حسابات مطابقة") }}
    </p>
    <div v-if="roots.length&gt;10" class="table-pagination-footer">
      <button
        class="btn small"
        :disabled="rootPage&lt;=1"
        @click="
          rootPage--;
          rootId = '';
        "
      >
        {{ $root.tr("السابق") }}</button
      ><span
        >{{ $root.tr("الوكلاء · الصفحة ") }}{{ $root.tr(rootPage)
        }}{{ $root.tr(" من ") }}{{ $root.tr(rootPages) }}</span
      ><button
        class="btn small"
        :disabled="rootPage&gt;=rootPages"
        @click="
          rootPage++;
          rootId = '';
        "
      >
        {{ $root.tr("التالي") }}
      </button>
    </div>
  </div>
</template>

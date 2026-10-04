<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["NetworkBranch"]);
export default options;
</script>

<template>
  <li
    class="network-node"
    :class="{ 'network-root': node.root, 'network-context': node.context }"
    :style="{ '--branch-color': node.color }"
  >
    <article
      class="network-agent"
      :style="{ '--entity-color': node.record.color || node.color }"
      :class="
        node.record.type === 'رئيسي'
          ? 'agent-main'
          : node.nestedSub
            ? 'agent-nested'
            : 'agent-sub'
      "
      :data-agent="node.record.id"
    >
      <button
        class="network-toggle"
        @click="$emit('toggle', node.record.id)"
        :aria-expanded="searching || expanded[node.record.id] !== false"
        :aria-label="tr('فتح أو إغلاق الفرع')"
        :disabled="searching||!node.children.length&amp;&amp;!node.points.length"
      >
        {{
          $root.tr(searching || expanded[node.record.id] !== false ? "−" : "+")
        }}
      </button>
      <div class="network-icon" aria-hidden="true">
        <svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.7"
        >
          <path
            d="M9 3h6v5H9ZM3 16h6v5H3Zm12 0h6v5h-6ZM12 8v4M6 16v-4h12v4"
          ></path>
        </svg>
      </div>
      <div class="network-identity">
        <small
          class="network-type"
          :class="
            node.record.type === 'رئيسي'
              ? 'type-main'
              : node.nestedSub
                ? 'type-nested'
                : 'type-sub'
          "
          >{{
            tr(
              node.record.type === "رئيسي"
                ? "وكيل رئيسي"
                : node.nestedSub
                  ? "فرعي تابع لفرعي"
                  : "وكيل فرعي",
            )
          }}</small
        >
        <h3>{{ $root.tr(node.record.name) }}</h3>
        <small v-if="node.record.parent" class="network-parent"
          >{{ tr("يتبع") }}:
          {{ $root.tr($root.nameOf("agents", node.record.parent)) }}</small
        >
        <p>
          {{ $root.tr(node.record.city || "—") }}
          <span v-if="node.record.phone"
            >· <bdi>{{ $root.tr(node.record.phone) }}</bdi></span
          >
        </p>
      </div>
      <span
        v-if="!node.context"
        class="network-status"
        :class="{ stopped: !node.record.active }"
        >{{ tr(node.record.active ? "مفعل" : "موقوف") }}</span
      >
      <div class="network-wallet">
        <small>{{ tr("رصيد البطاقات") }}</small
        ><strong
          >{{ $root.tr(money(node.balance)) }}
          <small>{{ tr("د.ع") }}</small></strong
        >
      </div>
      <button
        v-if="!node.context &amp;&amp; node.record.type==='رئيسي'"
        class="btn small"
        @click="$root.previewAgentProducts(node.record)"
      >
        {{ $root.tr("الفئات") }}</button
      ><button v-if="canReset()" class="btn small" @click="requestReset()">
        {{ tr("إعادة إرسال الرمز") }}</button
      ><button
        v-if="!node.context&amp;&amp;$root.canArchiveNetwork('agents',node.record.id)"
        class="btn small danger"
        @click="$root.askArchiveNetwork('agents', node.record.id)"
      >
        {{ $root.tr("حذف") }}</button
      ><button
        v-if="canEdit&amp;&amp;!node.context"
        class="btn small"
        @click="$emit('edit', node.record)"
      >
        {{ tr("تعديل") }}
      </button>
      <button
        v-if="!node.context&amp;&amp;$root.canManageNetwork('agents',node.record.id)"
        class="btn small"
        @click="$root.openNetworkPermissions('agents', node.record.id)"
      >
        {{ tr("صلاحيات التابع") }}
      </button>
      <div v-if="!node.context" class="network-counts">
        <span
          >{{ tr("الفروع المباشرة") }}
          <b>{{ $root.tr(node.directBranches) }}</b></span
        ><span
          >{{ tr("نقاط البيع المباشرة") }}
          <b>{{ $root.tr(node.directPoints) }}</b></span
        ><span
          >{{ tr("نقاط البيع بكامل الشبكة") }}
          <b>{{ $root.tr(node.totalPoints) }}</b></span
        >
      </div>
    </article>
    <div
      v-if="searching || expanded[node.record.id] !== false"
      class="network-descendants"
      v-network-stem=""
    >
      <div v-if="node.children.length" class="network-child-section">
        <h4>{{ tr("الوكلاء الفرعيون") }}</h4>
        <ul class="network-branches">
          <network-branch
            v-for="child in node.children"
            :key="child.record.id"
            :node="child"
            :tr="tr"
            :money="money"
            :expanded="expanded"
            :searching="searching"
            :can-edit="canEdit"
            @toggle="$emit('toggle', $event)"
            @edit="$emit('edit', $event)"
          ></network-branch>
        </ul>
      </div>
      <div v-if="node.points.length" class="network-point-section">
        <h4>{{ tr("نقاط البيع التابعة مباشرة لهذا الوكيل") }}</h4>
        <div class="network-points">
          <article
            v-for="p in node.points"
            :key="p.id"
            class="network-point"
            :data-pos="p.id"
          >
            <div class="network-point-heading">
              <span class="network-pos-icon" aria-hidden="true"
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.7"
                >
                  <path
                    d="M4 10v11h16V10M3 10l2-7h14l2 7M3 10c0 3 4 3 4 0 0 3 5 3 5 0 0 3 5 3 5 0 0 3 4 3 4 0M9 21v-6h6v6"
                  ></path></svg
              ></span>
              <div>
                <h4>{{ $root.tr(p.name) }}</h4>
                <small class="network-type type-pos">{{
                  tr("نقطة بيع")
                }}</small>
              </div>
              <span
                class="network-status"
                :class="{stopped:!p.active,offline:p.active&amp;&amp;!p.online}"
                >{{
                  tr(!p.active ? "موقوف" : p.online ? "متصل" : "غير متصل")
                }}</span
              >
            </div>
            <div class="network-point-balance">
              <span>{{ tr("رصيد البطاقات") }}</span
              ><b>{{ $root.tr(money(p.balance)) }} {{ tr("د.ع") }}</b>
            </div>
            <details>
              <summary>{{ tr("بيانات النقطة") }}</summary>
              <dl>
                <div>
                  <dt>{{ tr("الوكيل") }}</dt>
                  <dd>{{ $root.tr(node.record.name) }}</dd>
                </div>
                <div>
                  <dt>{{ tr("المحافظة") }}</dt>
                  <dd>{{ $root.tr(p.city || "—") }}</dd>
                </div>
                <div>
                  <dt>{{ tr("بريد تسجيل الدخول") }}</dt>
                  <dd>
                    <bdi>{{
                      $root.tr($root.networkLoginEmail("pos", p.id))
                    }}</bdi>
                  </dd>
                </div>
                <div>
                  <dt>{{ tr("صاحب النقطة") }}</dt>
                  <dd>{{ $root.tr(p.owner || "—") }}</dd>
                </div>
                <div>
                  <dt>{{ tr("الجهاز") }}</dt>
                  <dd>{{ $root.tr(p.model || "—") }}</dd>
                </div>
                <div>
                  <dt>{{ tr("رقم الجهاز") }}</dt>
                  <dd>
                    <bdi>{{ p.serial || "—" }}</bdi>
                  </dd>
                </div>
              </dl>
              <p>{{ $root.tr(p.address || "—") }}</p>
              <p>
                <bdi>{{ $root.tr(p.phone || "—") }}</bdi>
              </p>
            </details>
            <button
              v-if="$root.canArchiveNetwork('pos', p.id)"
              class="btn small danger"
              @click="$root.askArchiveNetwork('pos', p.id)"
            >
              {{ $root.tr("حذف") }}
            </button>
          </article>
        </div>
      </div>
      <p
        v-if="!node.children.length&amp;&amp;!node.points.length&amp;&amp;$root.networkKind==='all'"
        class="network-empty"
      >
        {{ tr("لا توجد فروع أو نقاط بيع تابعة لهذا الوكيل") }}
      </p>
    </div>
  </li>
</template>

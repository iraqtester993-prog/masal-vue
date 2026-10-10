<script setup>
import { money } from '../finance/finance-model.js';
defineProps({node:Object, expanded:Object, parentName:String});
defineEmits(['toggle']);
const tr = value => value;
</script>
<template>
  <li
    class="branch-list-item"
    :class="{ 'branch-list-root': node.root, 'network-context': node.context }"
    :style="{ '--branch-color': node.color }"
  >
    <button
      type="button"
      class="branch-list-row"
      :style="{ '--entity-color': node.record.color || node.color }"
      :class="
        node.record.type === 'main_agent'
          ? 'outline-main'
          : node.nestedSub
            ? 'outline-nested'
            : 'outline-sub'
      "
      :data-outline-agent="node.record.id"
      :aria-expanded="expanded[node.record.id] !== false"
      :aria-controls="'branch-list-' + node.record.id"
      @click="$emit('toggle', node.record.id)"
    >
      <span class="branch-list-icon" aria-hidden="true"
        ><svg
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          stroke-width="1.6"
        >
          <path
            d="M4 21V7l8-4 8 4v14M2 21h20M9 21v-5h6v5M8 8h2m4 0h2M8 12h2m4 0h2"
          ></path></svg
      ></span>
      <span class="branch-list-name"
        ><strong>{{ tr(node.record.name) }}</strong
        ><small v-if="parentName"
          >{{ tr("يتبع") }}: {{ tr(parentName) }}</small
        ></span
      ><span class="branch-list-type">{{
        tr(
          node.record.type === "main_agent"
            ? "وكيل رئيسي"
            : node.nestedSub
              ? "فرعي تابع لفرعي"
              : "وكيل فرعي",
        )
      }}</span>
      <span class="branch-list-balance"
        ><small>{{ tr("رصيد البطاقات") }}</small
        ><b>{{ tr(money(node.balance)) }} {{ tr("د.ع") }}</b></span
      ><svg
        class="branch-list-chevron"
        :class="{ closed: expanded[node.record.id] === false }"
        viewBox="0 0 20 20"
        fill="none"
        stroke="currentColor"
        stroke-width="1.7"
        aria-hidden="true"
      >
        <path d="m5 7 5 5 5-5"></path>
      </svg>
    </button>
    <div
      :id="'branch-list-' + node.record.id"
      v-if="expanded[node.record.id] !== false"
      class="branch-list-content"
    >
      <section v-if="node.children.length" class="outline-group agent-branches">
        <div class="outline-group-title">
          <span class="outline-group-dot agents-dot"></span>
          <h4>{{ tr("الوكلاء الفرعيون") }}</h4>
          <span class="outline-group-count">{{
            tr(node.children.length)
          }}</span>
        </div>
        <ul class="outline-agents-list">
          <NetworkOutline
            v-for="child in node.children"
            :key="child.record.id"
            :node="child"
            :tr="tr"
            :expanded="expanded"
            :parent-name="node.record.name"
            @toggle="$emit('toggle', $event)"
          ></NetworkOutline>
        </ul>
      </section>
      <section v-if="node.points.length" class="outline-group direct-points">
        <div class="outline-group-title">
          <span class="outline-group-dot points-dot"></span>
          <h4>{{ tr("نقاط البيع المباشرة") }}</h4>
          <span class="outline-group-count">{{
            tr(node.points.length)
          }}</span>
        </div>
        <ul class="outline-points-list">
          <li v-for="p in node.points" :key="p.id">
            <div class="branch-list-row outline-pos" :data-outline-pos="p.id">
              <span class="branch-list-icon" aria-hidden="true"
                ><svg
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="1.6"
                >
                  <path
                    d="M4 10v11h16V10M3 10l2-7h14l2 7M3 10c0 3 4 3 4 0 0 3 5 3 5 0 0 3 5 3 5 0 0 3 4 3 4 0M9 21v-6h6v6"
                  ></path></svg></span
              ><span class="branch-list-name"
                ><strong>{{ tr(p.name) }}</strong
                ><small
                  >{{ tr("تتبع مباشرة") }}:
                  {{ tr(node.record.name) }}</small
                ></span
              ><span class="branch-list-type">{{ tr("نقطة بيع") }}</span
              ><span class="branch-list-balance"
                ><small>{{ tr("رصيد البطاقات") }}</small
                ><b
                  >{{ tr(money(p.balance)) }} {{ tr("د.ع") }}</b
                ></span
              >
            </div>
          </li>
        </ul>
      </section>
      <p
        v-if="!node.children.length&&!node.points.length&&true"
        class="branch-list-empty"
      >
        {{ tr("لا توجد فروع أو نقاط بيع") }}
      </p>
    </div>
  </li>
</template>

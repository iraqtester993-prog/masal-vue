<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["card-designer"]);
export default options;
</script>

<template>
  <section v-if="vm.page === 'branding'" class="simple-card-workspace">
    <div class="card">
      <div class="simple-card-heading">
        <h3>{{ $root.tr("تصميم البطاقة") }}</h3>
        <div class="simple-card-selectors">
          <label
            >{{ $root.tr("الشركة")
            }}<select v-model="company" :aria-label="$root.tr('شركة البطاقة')">
              <option v-for="c in companies" :key="c.id" :value="c.id">
                {{ $root.tr(c.name) }}
              </option>
            </select></label
          ><label
            >{{ $root.tr("الفئة")
            }}<select v-model="product" :aria-label="$root.tr('فئة البطاقة')">
              <option v-for="p in products" :key="p.id" :value="p.id">
                {{ $root.tr(p.name) }}
              </option>
            </select></label
          >
        </div>
      </div>
    </div>
    <div v-if="product" class="two simple-card-designer">
      <form class="card simple-card-controls" @submit.prevent="save">
        <fieldset :disabled="!editable">
          <template v-if="isAdmin"
            ><label
              >{{ $root.tr("النص العلوي")
              }}<textarea
                v-model="draft.header"
                maxlength="1000"
                rows="2"
                aria-label="النص العلوي"
              ></textarea></label
            ><label
              >{{ $root.tr("النص السفلي")
              }}<textarea
                v-model="draft.footer"
                maxlength="1000"
                rows="2"
                aria-label="النص السفلي"
              ></textarea></label
            ><label class="personal-card-color"
              >{{ $root.tr("لون النص")
              }}<input
                type="color"
                v-model="draft.color"
                :aria-label="$root.tr('لون النص')"
            /></label>
            <h3>{{ $root.tr("ترتيب البطاقة") }}</h3>
            <div class="card-order-list">
              <div
                v-for="(key, i) in draft.order"
                :key="key"
                class="card-order-row"
              >
                <span>{{ $root.tr(label(key)) }}</span>
                <div class="actions">
                  <button
                    type="button"
                    class="btn small"
                    :disabled="i === 0"
                    :aria-label="$root.tr('رفع ' + label(key))"
                    @click="move(i, -1)"
                  >
                    ↑</button
                  ><button
                    type="button"
                    class="btn small"
                    :disabled="i === draft.order.length - 1"
                    :aria-label="$root.tr('تنزيل ' + label(key))"
                    @click="move(i, 1)"
                  >
                    ↓
                  </button>
                </div>
              </div>
            </div></template
          ><template v-else=""
            ><h3>{{ $root.tr("صورتي على البطاقة") }}</h3>
            <image-attachment
              :key="vm.currentUser + ':' + product"
              v-model="draft.image"
              @busy="busy = $event"
            ></image-attachment
            ><label
              >{{ $root.tr("نصي على البطاقة")
              }}<textarea
                v-model="draft.text"
                maxlength="1000"
                rows="3"
                aria-label="نصي على البطاقة"
              ></textarea></label
            ><label class="personal-card-color"
              >{{ $root.tr("لون الخط")
              }}<input
                type="color"
                v-model="draft.color"
                :aria-label="$root.tr('لون الخط')" /></label
          ></template>
        </fieldset>
        <div class="formfoot">
          <button
            type="button"
            class="btn"
            :disabled="busy || !product"
            @click="preview"
          >
            {{ $root.tr("معاينة البطاقة") }}</button
          ><button class="btn primary" :disabled="!editable || busy || !dirty">
            {{ $root.tr("حفظ التعديلات") }}
          </button>
        </div>
      </form>
      <div class="card simple-card-preview">
        <h3>{{ $root.tr("معاينة البطاقة") }}</h3>
        <meeting-receipt
          :product-id="product"
          :design="previewDesign"
          :agent-override="previewAgent"
        ></meeting-receipt>
      </div>
    </div>
    <div v-else="" class="card empty">{{ $root.tr("لا توجد فئات متاحة") }}</div>
  </section>
</template>

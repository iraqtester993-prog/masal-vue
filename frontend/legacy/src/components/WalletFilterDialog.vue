<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["wallet-filter-dialog"]);
export default options;
</script>

<template>
  <form @submit.prevent="vm.applyWalletFilters">
    <div class="formgrid">
      <label
        >{{ $root.tr("المحافظة")
        }}<select
          v-model="draft.city"
          @change="changed('city')"
          :aria-label="$root.tr('المحافظة')"
        >
          <option value="">
            {{
              $root.tr(
                vm.actor.role === "owner"
                  ? "الكل"
                  : vm.actor.role === "pos"
                    ? "نقطتي فقط"
                    : "الكل ضمن نطاقي",
              )
            }}
          </option>
          <option v-for="c in cities" :value="c">{{ $root.tr(c) }}</option>
        </select></label
      ><label
        >{{ $root.tr("نوع الحساب")
        }}<select
          v-model="draft.kind"
          @change="changed('kind')"
          :aria-label="$root.tr('نوع الحساب')"
        >
          <option value="">
            {{
              $root.tr(vm.actor.role === "pos" ? "نقطتي فقط" : "الكل ضمن نطاقي")
            }}
          </option>
          <option v-for="k in kinds" :value="k.id">
            {{ $root.tr(k.label) }}
          </option>
        </select></label
      ><label v-if="networks.length"
        >{{ $root.tr("شبكة الوكيل")
        }}<select
          v-model="draft.network"
          @change="changed"
          :aria-label="$root.tr('شبكة الوكيل')"
        >
          <option value="">
            {{
              $root.tr(vm.actor.role === "pos" ? "نقطتي فقط" : "الكل ضمن نطاقي")
            }}
          </option>
          <option v-for="a in networks" :value="a.id">
            {{ $root.tr(a.name) }}
          </option>
        </select></label
      ><label
        >{{ $root.tr("حالة الحساب")
        }}<select
          v-model="draft.active"
          @change="changed"
          :aria-label="$root.tr('حالة الحساب')"
        >
          <option value="">
            {{
              $root.tr(vm.actor.role === "pos" ? "نقطتي فقط" : "الكل ضمن نطاقي")
            }}
          </option>
          <option value="active">{{ $root.tr("مفعّل") }}</option>
          <option value="inactive">{{ $root.tr("معطّل") }}</option>
        </select></label
      ><label
        >{{ $root.tr("بحث بالاسم أو الهاتف")
        }}<input v-model="draft.query" @input="changed" type="search" /></label
      ><label
        >{{ $root.tr("الحساب")
        }}<select v-model="draft.account" :aria-label="$root.tr('الحساب')">
          <option value="">
            {{
              $root.tr(
                vm.actor.role === "pos"
                  ? "نقطتي فقط"
                  : "الحسابات المطابقة ضمن نطاقي",
              )
            }}
          </option>
          <option v-for="a in choices" :value="a.id">
            {{ $root.tr(a.name) }} · {{ $root.tr(vm.walletAccountType(a)) }}
          </option>
        </select></label
      ><label class="full"
        >{{ $root.tr("رقم الحركة أو المرجع")
        }}<input v-model="draft.reference" type="search" /></label
      ><label
        >{{ $root.tr("من تاريخ")
        }}<input
          type="date"
          v-model="draft.from"
          :max="draft.to || undefined" /></label
      ><label
        >{{ $root.tr("إلى تاريخ")
        }}<input type="date" v-model="draft.to" :min="draft.from || undefined"
      /></label>
    </div>
    <p class="caption">
      {{ $root.tr("التاريخ والمرجع للسجلات؛ الأرصدة المعروضة حالية.") }}
    </p>
    <p v-if="vm.modal.error" class="notice warn" role="alert">
      {{ $root.tr(vm.modal.error) }}
    </p>
    <div class="formfoot">
      <button
        type="button"
        class="btn"
        @click="
          vm.modal.draft = vm.emptyWalletFilters();
          vm.modal.error = '';
        "
      >
        {{ $root.tr("مسح الاختيارات") }}</button
      ><button type="button" class="btn" @click="vm.closeModal()">
        {{ $root.tr("إلغاء") }}</button
      ><button type="submit" class="btn primary">
        {{ $root.tr("تطبيق") }}
      </button>
    </div>
  </form>
</template>

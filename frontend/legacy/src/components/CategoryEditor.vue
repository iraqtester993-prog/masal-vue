<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["category-editor"]);
export default options;
</script>

<template>
  <div class="category-editor formgrid">
    <label
      >{{ $root.tr("معرّفات الفئة في ملفات الطلبيات (اختياري)")
      }}<input
        v-model="p.importCodes"
        placeholder="EVS-E5K, EVD-EV5"
        dir="ltr"
      /><small>{{
        $root.tr("يمكن إدخال معرّف واحد أو عدة معرّفات مفصولة بفاصلة.")
      }}</small></label
    ><label
      >{{ vm.tr("صورة الفئة")
      }}<image-attachment
        v-model="p.image"
        @busy="vm.categoryImageBusy = $event"
      ></image-attachment></label
    ><label
      >{{ vm.tr("عرض الوصل")
      }}<select v-model.number="p.receiptWidth">
        <option :value="58">58 mm</option>
        <option :value="80">80 mm</option>
      </select></label
    ><label
      >{{ vm.tr("النص أعلى البطاقة")
      }}<input v-model="p.receiptHeader" /></label
    ><label
      >{{ vm.tr("النص أسفل البطاقة")
      }}<input v-model="p.receiptFooter" /></label
    ><label
      >{{ vm.tr("المحافظات المسموحة")
      }}<select
        v-model="vm.categorySpecificCities"
        @change="p.allowedCities = []"
      >
        <option :value="false">{{ vm.tr("كل المحافظات") }}</option>
        <option :value="true">{{ vm.tr("محافظات محددة") }}</option>
      </select>
      <details v-if="vm.categorySpecificCities" class="category-picker">
        <summary>
          {{
            p.allowedCities.length
              ? p.allowedCities.join("، ")
              : vm.tr("اختر المحافظات")
          }}
        </summary>
        <input
          type="search"
          v-model="citySearch"
          :placeholder="vm.tr('بحث في المحافظات')"
          :aria-label="vm.tr('بحث في المحافظات')"
        />
        <div class="ops-checks">
          <label
            v-for="city in vm.governorates.filter(c=&gt;c.includes(citySearch.trim()))"
            :key="city"
            ><input type="checkbox" v-model="p.allowedCities" :value="city" />{{
              $root.tr(city)
            }}</label
          >
        </div>
      </details></label
    >
  </div>
</template>

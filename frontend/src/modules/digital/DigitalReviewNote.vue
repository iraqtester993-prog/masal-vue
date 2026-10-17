<script setup>
import {reactive} from 'vue';
import CatalogModal from '../catalog/CatalogModal.vue';
const props=defineProps({vm:{type:Object,required:true},order:{type:Object,required:true}}),emit=defineEmits(['close','saved']);
const form=reactive({reference:props.order.manual_review?.reference||'',note:props.order.manual_review?.note||'',current_password:''}),key=props.vm.key();
async function save(){
 const result=await props.vm.execute({...form,version:props.order.version},key,(body,signal)=>props.vm.api.action(props.order.id,'review-note',body,signal),'تم تسجيل المتابعة دون تغيير نتيجة العملية أو رصيدها.');
 form.current_password='';
 if(result)emit('saved');
}
</script>
<template>
 <CatalogModal title="متابعة عملية Topup" section="مراجعة الإدارة" :busy="vm.state.busy" @close="emit('close')">
  <form class="formgrid" @submit.prevent="save">
   <p v-if="vm.state.error" class="notice bad full" role="alert">{{vm.state.error}}</p>
   <p class="notice warn full">هذه متابعة لدى الشركة فقط. تبقى العملية ورصيدها المحجوز دون تغيير إلى أن تتوفر نتيجة موثقة، ولا يعاد إرسال الشراء.</p>
   <label class="full">مرجع المتابعة مع الشركة<input v-model.trim="form.reference" required maxlength="120" autocomplete="off"></label>
   <label class="full">ملاحظة المتابعة<textarea v-model.trim="form.note" required maxlength="1000" rows="3" placeholder="مرجع البلاغ والخطوة التالية، دون رموز بطاقات أو كلمات مرور"></textarea></label>
   <label class="full">كلمة مرور المدير الحالية<input v-model="form.current_password" required type="password" autocomplete="current-password" maxlength="200"></label>
   <div class="formfoot full"><button class="btn primary" :disabled="vm.state.busy">حفظ المتابعة</button><button class="btn" type="button" :disabled="vm.state.busy" @click="emit('close')">إلغاء</button></div>
  </form>
 </CatalogModal>
</template>

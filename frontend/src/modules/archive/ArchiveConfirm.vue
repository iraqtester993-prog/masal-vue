<script setup>
import {computed,onBeforeUnmount,onMounted,reactive,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createOperationsApi} from '../security/operations-api.js';
import {createMutationKey,errorText} from '../finance/finance-model.js';
const props=defineProps({account:{type:Object,required:true}}),emit=defineEmits(['archived','cancel','busy']);
const {session}=usePortal(),api=createOperationsApi(session.api),state=reactive({reason:'',password:'',error:'',busy:false,loading:true,eligibility:null});
const allowed=computed(()=>session.can('agents.archive')&&props.account.id!==session.state.identity?.account.id&&props.account.type!=='system');
const key=createMutationKey(),writer=new AbortController();let reader,revision=0,alive=true;
watch(()=>state.busy,value=>emit('busy',value));
async function failure(cause){if(cause.name==='AbortError'||!alive)return;state.error=errorText(cause);if([401,403].includes(cause.status))await session.refresh();}
async function check(){reader?.abort();reader=new AbortController();const expected=++revision;state.loading=true;state.eligibility=null;state.password='';state.error='';try{const response=await api.eligibility(props.account.id,reader.signal);if(alive&&expected===revision)state.eligibility=response.data;}catch(cause){await failure(cause);}finally{if(alive&&expected===revision)state.loading=false;}}
async function save(){if(!allowed.value||state.busy||state.loading||!state.eligibility?.eligible)return;state.error='';state.busy=true;const body={reason:state.reason.trim(),version:state.eligibility.version};try{const result=await api.createArchive(props.account.id,{...body,password:state.password,idempotency_key:key.for({id:props.account.id,...body})},writer.signal);if(alive){key.clear();emit('archived',result.data);}}catch(cause){await failure(cause);if(cause.status===409)await check();}finally{state.password='';if(alive)state.busy=false;}}
watch(()=>props.account.id,()=>{key.clear();state.reason='';check();});onMounted(()=>{if(allowed.value)check();else state.loading=false;});onBeforeUnmount(()=>{alive=false;revision++;reader?.abort();writer.abort();state.password='';});
</script>

<template>
  <form @submit.prevent="save"><p>سيُخفى الحساب ويتوقف تسجيل دخوله، مع الاحتفاظ ببياناته وسجلاته.</p><p v-if="state.loading" role="status">جارٍ فحص الحساب…</p><ul v-if="state.eligibility?.blockers.length" class="notice warn" role="alert"><li v-for="reason in state.eligibility.blockers" :key="reason">{{reason}}</li></ul><label>سبب الحذف الأرشيفي<textarea v-model="state.reason" required :disabled="state.busy||!allowed" maxlength="500"></textarea></label><label>كلمة مرور حسابك<input type="password" v-model="state.password" required autocomplete="current-password" :disabled="state.busy||!allowed"></label><p v-if="state.error" class="notice warn" role="alert">{{state.error}}</p><div class="formfoot"><button type="button" class="btn" :disabled="state.busy" @click="emit('cancel')">إلغاء</button><button class="btn danger" :disabled="!allowed||state.busy||state.loading||!state.eligibility?.eligible||!state.password||!state.reason.trim()">{{state.busy?'جارٍ التحقق…':'تأكيد الحذف الأرشيفي'}}</button></div></form>
</template>

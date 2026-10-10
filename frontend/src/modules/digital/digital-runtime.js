import { computed,onBeforeUnmount,reactive,ref,watch } from 'vue';
import { usePortal } from '../auth/session.js';
import { createDigitalApi } from './digital-api.js';
import { createMutationKey,errorText } from './digital-model.js';
export function useDigitalRuntime() {
  const {session}=usePortal(),api=createDigitalApi(session.api),identity=computed(()=>session.state.identity),ownId=computed(()=>Number(identity.value?.account.id)),pos=computed(()=>identity.value?.account.type==='pos');
  const state=reactive({options:{accounts:[],products:[],providers:[],gateway:null},connections:[],connectionsMeta:{current_page:1,last_page:1,total:0},offers:[],loading:false,busy:false,error:'',notice:'',uncertain:false}),receipt=ref(null),writer=new AbortController();
  let reader,receiptReader,revision=0,receiptRevision=0;
  async function failure(error){if([401,403].includes(error.status))await session.refresh();return errorText(error);}
  async function load(page=state.connectionsMeta.current_page){reader?.abort();reader=new AbortController();const expected=++revision;state.loading=true;state.error='';try{const [options,connections,offers]=await Promise.all([api.options(reader.signal),api.connections({page,per_page:25},reader.signal),pos.value?api.offers(reader.signal):Promise.resolve({data:[]})]);if(expected!==revision)return;state.options=options.data;state.connections=connections.data;state.connectionsMeta=connections.meta;state.offers=offers.data;}catch(error){if(error.name!=='AbortError'&&expected===revision)state.error=await failure(error);}finally{if(expected===revision)state.loading=false;}}
  async function execute(payload,key,action,notice='تم حفظ العملية.') {
    if(state.busy)return null;state.busy=true;state.error='';state.notice='';state.uncertain=false;
    try{const result=await action({...payload,idempotency_key:key.for(payload)},writer.signal);key.clear();state.notice=notice;return result.data;}catch(error){if(error.name!=='AbortError'){state.uncertain=!error.status||error.status>=500;state.error=await failure(error);}return null;}finally{state.busy=false;}
  }
  async function openReceipt(order){receiptReader?.abort();receiptReader=new AbortController();const expected=++receiptRevision;receipt.value=null;try{const response=await api.receipt(order.id,receiptReader.signal);if(expected===receiptRevision)receipt.value=response.data;}catch(error){if(error.name!=='AbortError')state.error=await failure(error);}}
  function clearReceipt(){receiptRevision++;receiptReader?.abort();receipt.value=null;}
  watch(()=>[identity.value?.account.id,identity.value?.user.id,session.can('data.pin'),session.can('digital.receipt')],(next,old)=>{if(!old||next[0]!==old[0]||next[1]!==old[1]||!next[2]||!next[3])clearReceipt();if(!next[0]||old&&next[0]!==old[0]){reader?.abort();revision++;state.connections=[];state.connectionsMeta={current_page:1,last_page:1,total:0};state.offers=[];}});
  onBeforeUnmount(()=>{reader?.abort();writer.abort();clearReceipt();revision++;state.offers=[];});
  return {session,api,state,identity,ownId,pos,can:session.can,receipt,load,execute,failure,openReceipt,clearReceipt,key:createMutationKey,signal:writer.signal};
}

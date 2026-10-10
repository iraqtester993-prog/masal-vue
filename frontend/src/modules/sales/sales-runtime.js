import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { usePortal } from '../auth/session.js';
import { createSalesApi } from './sales-api.js';
import { createMutationKey, errorText, sellerTypes } from './sales-model.js';
export function useSalesRuntime() {
  const {session} = usePortal(),api = createSalesApi(session.api);
  const identity = computed(()=>session.state.identity),ownId = computed(()=>Number(identity.value?.account.id));
  const seller = computed(()=>sellerTypes.includes(identity.value?.account.type)),pos = computed(()=>identity.value?.account.type === 'pos');
  const state = reactive({options:{accounts:[],products:[]},products:[],loading:false,busy:false,error:'',notice:'',uncertain:false});
  const receipt = ref(null), receiptLoading = ref(false); let reader,receiptReader,revision = 0,receiptRevision = 0;
  const writer = new AbortController();
  async function failure(error) {
    if ([401,403].includes(error.status)) await session.refresh();
    return errorText(error);
  }
  async function load(configuration = false) {
    reader?.abort(); reader = new AbortController(); const expected = ++revision;
    state.loading = true; state.error = '';
    try {const response = await (configuration ? api.configuration(reader.signal):api.options(reader.signal)); if(expected !== revision)return; state.options = response.data; state.products = response.data.products || [];}
    catch(error) {if(error.name !== 'AbortError' && expected === revision)state.error = await failure(error);}
    finally {if(expected === revision)state.loading = false;}
  }
  async function refreshProducts() {
    if (!seller.value) return;
    try {state.products = (await api.products(writer.signal)).data;} catch(error) {if(error.name !== 'AbortError')state.error = await failure(error);}
  }
  async function execute(payload,key,action,notice = 'تم حفظ العملية.') {
    if (state.busy) return null;
    state.busy = true; state.error = ''; state.notice = ''; state.uncertain=false;
    try {const result = await action({...payload,idempotency_key:key.for(payload)},writer.signal); key.clear(); state.notice = notice; return result.data;}
    catch(error) {if(error.name !== 'AbortError'){state.uncertain=!error.status || error.status>=500;state.error = `${await failure(error)}${state.uncertain ? ' نتيجة العملية غير مؤكدة؛ أعد المحاولة من الزر نفسه أو راجع السجل قبل إصدار عملية جديدة.':''}`;} return null;}
    finally {state.busy = false;}
  }
  async function openReceipt(sale) {
    receiptReader?.abort(); receiptReader = new AbortController(); const expected = ++receiptRevision; receipt.value = null; receiptLoading.value = true; state.error = '';
    try {
      const detail = (await api.show(sale.id,receiptReader.signal)).data;
      const response = (await api.receipt(sale.id,receiptReader.signal)).data;
      if(expected !== receiptRevision)return;
      response.sale.attempts = detail.attempts || [];
      if(response.sale.status === 'Reprint Requested') {
        const requests = await api.list('reprint-requests',{status:'approved',q:String(sale.id),per_page:100},receiptReader.signal);
        response.reprint_approved = requests.data.some(request=>Number(request.id)===Number(response.sale.reprint_request_id));
      }
      if(expected === receiptRevision)receipt.value = response;
    } catch(error) {if(error.name !== 'AbortError' && expected === receiptRevision)state.error = await failure(error);}
    finally {if(expected === receiptRevision)receiptLoading.value = false;}
  }
  function clearReceipt() {receiptRevision++; receiptReader?.abort(); receipt.value = null; receiptLoading.value = false;}
  watch(()=>[identity.value?.account.id,identity.value?.user.id,session.can('data.pin'),session.can('sell.receipt')],(next,previous)=>{
    if(!previous || next[0]!==previous[0] || next[1]!==previous[1] || !next[2] || !next[3])clearReceipt();
    if(!next[0] || previous && next[0]!==previous[0]){revision++;reader?.abort();state.products=[];state.options={accounts:[],products:[]};}
  });
  onBeforeUnmount(()=>{revision++; receiptRevision++; reader?.abort(); receiptReader?.abort(); writer.abort(); receipt.value = null; state.products = [];});
  return {session,api,state,identity,ownId,seller,pos,can:session.can,receipt,receiptLoading,load,refreshProducts,execute,failure,openReceipt,clearReceipt,key:createMutationKey};
}

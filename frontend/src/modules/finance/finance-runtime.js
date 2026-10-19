import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { usePortal } from '../auth/session.js';
import { createPagedResource } from '../accounts/paged-resource.js';
import { allPages, createFinanceApi } from './finance-api.js';
import { accountTypes, createMutationKey, errorText, money, sum, time } from './finance-model.js';

export function useFinanceRuntime({ balances = false } = {}) {
  const { session } = usePortal(), api = createFinanceApi(session.api);
  const options = reactive(session.api.peekRequest?.('/finance/options?compact=1')?.data ?? { services: [], currencies: [], accounts: [], funding_policy: null });
  const loading = ref(false), busy = ref(false), error = ref(''), notice = ref('');
  const wallets = ref([]), balancesError = ref(''), balancesReady = ref(false);
  const loadedAccounts = reactive(new Set()), balanceGeneration = ref(0);
  const walletReads = new Map();
  let walletReadRevision = 0;
  let alive = true;
  let controller, revision = 0;
  const resources = [];
  const ownId = computed(() => Number(options.own_account_id ?? session.state.identity?.account.id));
  const identity = computed(() => session.state.identity);
  const owner = computed(() => identity.value?.membership?.kind === 'owner');
  const admin = computed(() => identity.value?.account.type === 'system');
  const ownVisible = computed(() => options.accounts.some(row=>Number(row.id)===ownId.value));
  const children = computed(() => options.accounts.filter((row) => Number(row.parent_id) === ownId.value && row.status !== 'disabled' && row.status !== 'deleted'));
  const hasChildren = computed(()=>options.child_count ?? children.value.length);
  function rememberAccounts(rows){const incoming=new Set(rows.map(row=>Number(row.id)));options.accounts=[...options.accounts.filter(row=>!incoming.has(Number(row.id))),...rows];if(options.accounts.length>500)options.accounts=options.accounts.filter(row=>Number(row.id)===ownId.value || incoming.has(Number(row.id))).concat(options.accounts.filter(row=>Number(row.id)!==ownId.value && !incoming.has(Number(row.id))).slice(-Math.max(0,499-rows.length)));}
  const name = (id) => options.accounts.find((row) => Number(row.id) === Number(id))?.name || (Number(id) === ownId.value ? identity.value?.account.name : `#${id}`);
  const serviceName = (id) => options.services.find((row) => row.id === id)?.name || id;
  const currencyLabel = () => 'د.ع';
  async function failure(failed) {
    if ([401, 403].includes(failed.status)) await session.refresh();
    return errorText(failed);
  }
  function resource(kind) {
    const value = createPagedResource((parameters, signal,options) => api.list(kind, parameters, signal,options), failure,{peek:parameters=>api.peekList(kind,parameters)});
    resources.push(value);
    onBeforeUnmount(() => { value.dispose(); const index=resources.indexOf(value); if(index>=0)resources.splice(index,1); });
    return value;
  }
  async function loadOptions({fresh=false} = {}) {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal, expected = ++revision;
    loading.value = !session.api.peekRequest?.('/finance/options?compact=1'); error.value = ''; balanceGeneration.value++; walletReads.clear();
    const balanceRead = balances ? loadWallets(signal,undefined,{fresh}).catch(failed => { if (failed.name !== 'AbortError') balancesError.value = errorText(failed); }) : null;
    try {
      const result = await api.options(signal,{fresh});
      if (expected !== revision) return;
      Object.assign(options, result.data);
      if(fresh)await balanceRead;
    } catch (failed) { if (failed.name !== 'AbortError' && expected === revision) error.value = await failure(failed); }
    finally { if (expected === revision) loading.value = false; }
  }
  async function loadWallets(signal = controller?.signal, accountIds = [ownId.value], { fresh = false } = {}) {
    if (!session.can('wallets.view') || !alive) return false;
    const ids=[...new Set(accountIds.map(Number).filter(id=>id>0))];
    if (!ids.length) return false;
    const expected = balanceGeneration.value;
    const readRevision = ++walletReadRevision;
    ids.forEach(id => walletReads.set(id, readRevision));
    balancesError.value = '';
    if(!fresh){
      const cached=api.peekList('wallets',{account_ids:ids,metrics:0,per_page:100,page:1});
      if(Array.isArray(cached?.data)&&(cached.meta?.last_page??1)===1){
        wallets.value=[...wallets.value.filter(row=>!ids.includes(Number(row.account_id))),...cached.data.filter(row=>ids.includes(Number(row.account_id)))];
        ids.forEach(id=>loadedAccounts.add(id));balancesReady.value=true;
      }
    }
    try {
      const result = await allPages(api, 'wallets', {account_ids:ids,metrics:0}, signal, {fresh});
      if (alive && expected === balanceGeneration.value) {
        const currentIds = ids.filter(id => walletReads.get(id) === readRevision);
        if (fresh && currentIds.length !== ids.length) return false;
        if (!currentIds.length) return false;
        wallets.value = [...wallets.value.filter(row=>!currentIds.includes(Number(row.account_id))),...result.filter(row=>currentIds.includes(Number(row.account_id)))];
        currentIds.forEach(id=>loadedAccounts.add(id));
        while(loadedAccounts.size>500){const old=loadedAccounts.values().next().value;loadedAccounts.delete(old);walletReads.delete(old);wallets.value=wallets.value.filter(row=>Number(row.account_id)!==old);}
        balancesReady.value = true;
        return fresh ? result.map(row => ({...row})) : true;
      }
    } catch (failed) {
      if (failed.name === 'AbortError') return false;
      if (alive && expected === balanceGeneration.value && ids.some(id => walletReads.get(id) === readRevision)) { ids.filter(id => walletReads.get(id) === readRevision).forEach(id=>loadedAccounts.delete(id)); balancesError.value = await failure(failed); }
    }
    return false;
  }
  function walletAmount(id, service, currency, field = 'available') {
    if (!balancesReady.value || balancesError.value || !loadedAccounts.has(Number(id))) return null;
    return sum(wallets.value.filter((row) => Number(row.account_id) === Number(id) && (!service || row.service === service) && row.currency === currency).map((row) => row[field]));
  }
  async function execute(payload, key, action, message = 'تم حفظ العملية.') {
    if (busy.value) return null;
    busy.value = true; error.value = ''; notice.value = '';
    try {
      const result = await action({ ...payload, idempotency_key: key.for(payload) });
      key.clear(); notice.value = message;
      return result;
    } catch (failed) {
      const message = await failure(failed);
      error.value = !failed.status || failed.status >= 500 ? `${message} نتيجة العملية غير مؤكدة؛ أعد المحاولة من الزر نفسه، أو راجع السجل قبل بدء عملية جديدة.` : message;
      return null;
    }
    finally { busy.value = false; }
  }
  async function savePolicy(payload) {
    if (busy.value) return null;
    busy.value = true; error.value = ''; notice.value = '';
    try { const result = await api.savePolicy(payload); options.funding_policy = result.data; notice.value = 'تم حفظ إعدادات التمويل.'; return result; }
    catch (failed) { error.value = await failure(failed); return null; }
    finally { busy.value = false; }
  }
  onBeforeUnmount(() => { alive=false; revision += 1; balanceGeneration.value++; controller?.abort(); resources.forEach((value) => value.dispose()); wallets.value = []; walletReads.clear(); });
  return { session, api, options, identity, ownId, owner, admin, ownVisible, children, hasChildren, rememberAccounts, loading, busy, error, notice, wallets, balancesError, balancesReady, balanceGeneration, can: session.can, name, serviceName, currencyLabel, money, time, accountTypes, loadOptions, loadWallets, walletAmount, resource, execute, savePolicy, key: createMutationKey, failure };
}

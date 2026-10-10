import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { usePortal } from '../auth/session.js';
import { createPagedResource } from '../accounts/paged-resource.js';
import { allPages, createFinanceApi } from './finance-api.js';
import { accountTypes, createMutationKey, errorText, money, sum, time } from './finance-model.js';

export function useFinanceRuntime() {
  const { session } = usePortal(), api = createFinanceApi(session.api);
  const options = reactive(session.api.peekRequest?.('/finance/options')?.data ?? { services: [], currencies: [], accounts: [], funding_policy: null });
  const loading = ref(false), busy = ref(false), error = ref(''), notice = ref('');
  const wallets = ref([]), balancesError = ref(''), balancesReady = ref(false);
  const rememberedWallets = [];
  for (let page = 1; page <= 500; page++) {
    const cached = session.api.peekRequest?.(`/finance/wallets?per_page=100&page=${page}`);
    if (!Array.isArray(cached?.data)) break;
    rememberedWallets.push(...cached.data);
    if (page >= (cached.meta?.last_page ?? 1)) {
      wallets.value = rememberedWallets; balancesReady.value = true; break;
    }
  }
  let controller, revision = 0;
  const resources = [];
  const ownId = computed(() => Number(options.own_account_id ?? session.state.identity?.account.id));
  const identity = computed(() => session.state.identity);
  const owner = computed(() => identity.value?.membership?.kind === 'owner');
  const admin = computed(() => identity.value?.account.type === 'system');
  const ownVisible = computed(() => options.accounts.some(row=>Number(row.id)===ownId.value));
  const children = computed(() => options.accounts.filter((row) => Number(row.parent_id) === ownId.value && row.status !== 'disabled' && row.status !== 'deleted'));
  const name = (id) => options.accounts.find((row) => Number(row.id) === Number(id))?.name || (Number(id) === ownId.value ? identity.value?.account.name : `#${id}`);
  const serviceName = (id) => options.services.find((row) => row.id === id)?.name || id;
  const currencyLabel = () => 'د.ع';
  async function failure(failed) {
    if ([401, 403].includes(failed.status)) await session.refresh();
    return errorText(failed);
  }
  function resource(kind) {
    const value = createPagedResource((parameters, signal) => api.list(kind, parameters, signal), failure);
    resources.push(value);
    onBeforeUnmount(() => { value.dispose(); const index=resources.indexOf(value); if(index>=0)resources.splice(index,1); });
    return value;
  }
  async function loadOptions() {
    controller?.abort(); controller = new AbortController(); const signal = controller.signal, expected = ++revision;
    loading.value = true; error.value = '';
    try {
      const result = await api.options(signal);
      if (expected !== revision) return;
      Object.assign(options, result.data);
      void loadWallets(signal).catch(failed => { if (failed.name !== 'AbortError') balancesError.value = errorText(failed); });
    } catch (failed) { if (failed.name !== 'AbortError' && expected === revision) error.value = await failure(failed); }
    finally { if (expected === revision) loading.value = false; }
  }
  let balanceRevision = 0;
  async function loadWallets(signal) {
    if (!session.can('wallets.view')) return;
    const expected = ++balanceRevision;
    balancesError.value = '';
    try {
      const result = await allPages(api, 'wallets', {}, signal);
      if (expected === balanceRevision) { wallets.value = result; balancesReady.value = true; }
    } catch (failed) {
      if (failed.name === 'AbortError') throw failed;
      if (expected === balanceRevision) { wallets.value = []; balancesReady.value = false; balancesError.value = await failure(failed); }
    }
  }
  function walletAmount(id, service, currency, field = 'available') {
    if (!balancesReady.value || balancesError.value) return null;
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
  onBeforeUnmount(() => { revision += 1; balanceRevision += 1; controller?.abort(); resources.forEach((value) => value.dispose()); wallets.value = []; });
  return { session, api, options, identity, ownId, owner, admin, ownVisible, children, loading, busy, error, notice, wallets, balancesError, balancesReady, can: session.can, name, serviceName, currencyLabel, money, time, accountTypes, loadOptions, loadWallets, walletAmount, resource, execute, savePolicy, key: createMutationKey, failure };
}

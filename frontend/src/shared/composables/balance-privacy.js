import { readonly, ref, watch } from 'vue';

// Presentation only. Amounts, PINs and account data never enter this state.
const concealed = ref(false);

export function useBalancePrivacy(accountScope) {
  if (accountScope) watch(accountScope, () => { concealed.value = false; }, { immediate: true });
  return { hidden: readonly(concealed), toggle() { concealed.value = !concealed.value; } };
}

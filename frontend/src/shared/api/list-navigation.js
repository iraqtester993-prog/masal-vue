export function useListNavigation(api,key) {
  const scope=api.viewStateScope?.(),saved=api.peekViewState?.(key)??null;
  // Page navigation owns scroll; delayed list reads only restore filters and paging.
  async function restoreScroll(){}
  function remember(value){const {scroll,...preferences}=value;api.rememberViewState?.(key,preferences,scope);}
  return {saved,remember,restoreScroll};
}

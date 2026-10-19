export function installTextSelectionGuard(doc = globalThis.document) {
  if (!doc?.addEventListener) return () => {};
  const blockedEvents = ['contextmenu', 'selectstart', 'copy', 'cut'];
  const capture = {capture:true};
  const preventSelection = event => event.preventDefault();
  const clearSelection = () => {
    const selection = doc.getSelection?.();
    if (selection && !selection.isCollapsed) selection.removeAllRanges();
  };
  for (const name of blockedEvents) doc.addEventListener(name, preventSelection, capture);
  doc.addEventListener('selectionchange', clearSelection);
  clearSelection();
  return () => {
    for (const name of blockedEvents) doc.removeEventListener(name, preventSelection, capture);
    doc.removeEventListener('selectionchange', clearSelection);
  };
}

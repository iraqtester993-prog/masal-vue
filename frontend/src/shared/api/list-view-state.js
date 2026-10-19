// View preferences only. API results, balances, receipts and form drafts stay out.
export function createListViewState({limit=20}={}) {
  const views=new Map();let scope=0;
  function clear(){scope++;views.clear();}
  function peek(key){return views.has(key)?structuredClone(views.get(key)):null;}
  function remember(key,value,expected=scope){
    if(expected!==scope)return;
    views.delete(key);views.set(key,structuredClone(value));
    while(views.size>limit)views.delete(views.keys().next().value);
  }
  return {clear,peek,remember,scope:()=>scope};
}

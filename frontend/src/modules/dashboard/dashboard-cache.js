// Display hydration uses the authenticated API client's memory cache. Mutations,
// account/permission changes and logout invalidate that cache centrally.
export const dashboardSummaryPath=(currency='IQD',view='cards')=>`/dashboard/summary?currency=${encodeURIComponent(currency)}&view=${view}`;
export const dashboardActivityPath=(limit,cursor='')=>`/dashboard/activity?limit=${encodeURIComponent(limit)}${cursor?`&cursor=${encodeURIComponent(cursor)}`:''}`;
export const dashboardReadScope=identity=>JSON.stringify([identity?.portal,identity?.user?.id,identity?.account?.id,identity?.membership,identity?.permissions]);

export function dashboardCatalogSnapshot(api,can,maxAge=120000){
 const rows=path=>{const value=api.peekRequest?.(path,maxAge)?.data;return Array.isArray(value)?value:[];};
 return {products:can('sell.create')?rows('/sales/products'):[],offers:can('digital.view')?rows('/digital/offers?'):[]};
}

export function dashboardTopupAvailable(offers=[]){
 const topup=offers.filter(row=>row.provider==='topup'),values=new Set(topup.map(row=>row.wallet_available));
 return topup.length&&values.size===1?topup[0].wallet_available??null:null;
}

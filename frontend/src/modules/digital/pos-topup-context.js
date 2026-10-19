export const topupKinds=Object.freeze([{id:'',name:'الكل'},{id:'topup',name:'تعبئة رصيد'},{id:'bundle',name:'باقات'},{id:'bill',name:'تسديد فاتورة'}]);
export function topupKind(value){return typeof value==='string'&&topupKinds.some(item=>item.id===value)?value:'';}
export function topupCatalogRoute({type='',q=''}={}){const selected=topupKind(type);return{name:'sell',query:{service:'topup',...(selected?{type:selected}:{}),...(typeof q==='string'&&q.trim()?{q:q.trim().slice(0,160)}:{})}};}
export function topupWalletRoute({funding=false}={}){return{name:'wallets',query:{service:'topup',...(funding?{quick:'funding'}:{})}};}
export function topupHistoryRoute(){return{name:'sales',query:{service:'topup'}};}

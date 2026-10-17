import {minor} from './finance-model.js';

export function topupUnavailableReason(offer) {
  if(offer?.provider!=='topup')return '';
  if(offer.availability_reason==='funding_required')return 'رصيد Topup غير ممول؛ اطلب التمويل من الوكيل.';
  if(offer.wallet_available===null||offer.wallet_available===undefined||typeof offer.purchasable!=='boolean')return 'تعذر التحقق من رصيد Topup؛ أعد فتح الفئة.';
  try {
    if(offer.availability_reason==='insufficient_balance')return 'رصيد Topup غير كافٍ لهذه الفئة.';
    if(minor(offer.wallet_available)<=0n)return 'رصيد Topup غير ممول؛ اطلب التمويل من الوكيل.';
    if(minor(offer.wallet_available)<minor(offer.retail))return 'رصيد Topup غير كافٍ لهذه الفئة.';
  } catch {return 'تعذر التحقق من رصيد Topup؛ أعد فتح الفئة.';}
  return offer.purchasable?'':'هذه الفئة غير متاحة للبيع حاليًا.';
}

// Server summaries already apply account scope. Never derive a spendable wallet
// from an allocation, provider balance, point totals, or accounting margin.
export function topupWalletMetrics(summary,topup,{own=false,group=false,costs=false,profits=false}={}) {
  const wallet=summary||{};
  const rows=[
    {id:'available',label:own?'رصيدي المتاح في Topup':'الرصيد الممول المتاح للحسابات المعروضة',value:wallet[own?'own_available':'available']},
    {id:'held',label:own?'رصيدي المحجوز في Topup':'الرصيد المحجوز للحسابات المعروضة',value:wallet[own?'own_held':'held']},
  ];
  if(group&&topup){
    rows.push(
      ...(costs?[{id:'allocation',label:'حصة Topup الكلية',value:topup.allocation_balance}]:[]),
      {id:'funded-points',label:'أرصدة Topup الممولة للتابعين',value:topup.funded_points_balance},
      {id:'funded-points-held',label:'المحجوز لدى التابعين',value:topup.funded_points_held},
      ...(profits?[{id:'accounting-margin',label:'ربح الوكيل المحاسبي · غير قابل للصرف',value:topup.agent_margin_accounting}]:[]),
    );
  }
  return rows.filter(row=>row.id==='available'||row.id==='held'||row.value!==undefined);
}

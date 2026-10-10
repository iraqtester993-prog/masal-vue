import { exportReport } from '../reports/report-export.js';
export function categoryReport(connection){
  const types={topup:'تعبئة رصيد',bundle:'باقة',bill:'فاتورة',voucher:'بطاقة'};
  const excluded=connection.excluded_catalog,offers=connection.offers||[],hasCost=offers.some(row=>row.cost!==undefined);
  const summary=[{name:'الوكيل',value:connection.account_name},{name:'الفئات المرتبطة',value:offers.length},...Object.entries(types).map(([key,name])=>({name,value:offers.filter(row=>row.type===key).length})),{name:'المستبعدة بسبب السعر',value:excluded===undefined?'غير متاح في هذا العرض':excluded===null?'لم تُسجّل؛ أعد جلب فئات الشركة':excluded.length}];
  const columns=[{key:'name',label:'اسم الفئة لدى الشركة'},{key:'type',label:'النوع'},{key:'remote_id',label:'معرّف الشركة'},...(hasCost?[{key:'cost',label:'سعر الشركة · د.ع',type:'money'}]:[]),{key:'retail',label:connection.allocation?'سعر الصرف · د.ع':'سعر البيع · د.ع',type:'money'},{key:'status',label:connection.allocation?'التخصيص':'الحالة'}];
  return {currency:'IQD',timezone:'Asia/Baghdad',generated_at:new Date().toISOString(),sections:[
    {title:'ملخص فئات الوكيل',available:true,note:'آخر جلب: '+(connection.catalog_updated_at||'غير معلوم'),columns:[{key:'name',label:'البيان'},{key:'value',label:'القيمة'}],rows:summary},
    {title:'الفئات المرتبطة',available:true,columns,rows:offers.map(row=>({name:row.remote_name||row.name,type:types[row.type]||'غير محدد',remote_id:String(row.remote_id||''),cost:row.cost,retail:row.retail,status:connection.allocation?(row.active?'محددة للوكيل':'غير محددة للوكيل'):(row.active?'مفعّلة للبيع':'معطّلة')}))},
    {title:'السجلات المستبعدة',available:true,note:excluded===undefined?'تفاصيل المستبعدات غير متاحة في هذا العرض.':excluded===null?'تفاصيل المستبعدات غير مسجلة؛ أعد جلب فئات الشركة.':'سجلات للمراجعة فقط؛ لا يمكن بيعها أو تخصيصها.',columns:[{key:'name',label:'اسم السجل لدى الشركة'},{key:'remote_id',label:'معرّف الشركة'},{key:'type',label:'النوع'},{key:'price',label:'السعر · د.ع'},{key:'reason',label:'سبب الاستبعاد'}],rows:(excluded||[]).map(row=>({name:row.remote_name,remote_id:String(row.remote_id||''),type:types[row.type]||'غير محدد',price:row.price===null?'لم ترسله الشركة':row.price,reason:row.reason}))}
  ]};
}
export async function exportCategories(connection,pdf=false){
  return exportReport({export:async()=>({data:categoryReport(connection)})},{},pdf);
}

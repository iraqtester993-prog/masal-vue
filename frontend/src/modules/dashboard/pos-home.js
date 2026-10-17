export function dashboardCompanies(products=[],offers=[]){
 const result=new Map();
 for(const product of Array.isArray(products)?products:[]){
  if(product.provider_id==null)continue;
  const key=String(product.provider_id);
  if(!result.has(key))result.set(key,{key:`stock:${key}`,name:product.provider_name,image:product.provider_image_url||null});
 }
 for(const offer of Array.isArray(offers)?offers:[]){
  if(offer.retail==null&&offer.price==null)continue;
  const company=offer.provider==='topup'?(offer.product_provider_id??'topup'):(offer.provider_id??'rabiaa');
  const key=String(company);
  if(!result.has(key))result.set(key,{key:`digital:${key}`,provider:offer.provider,name:offer.provider==='topup'?(offer.product_provider_name||'آسياسيل'):offer.company_name||offer.provider_name||'الرابعة',image:offer.provider_image_url||null});
 }
 return [...result.values()];
}

// Presentation only. Every choice keeps the original catalog row and its exact
// stock/digital identity; this module never constructs a purchase payload.
function positiveDecimal(value) {
 const raw=String(value??'');
 if(!/^\d{1,30}(?:\.\d{1,2})?$/.test(raw))return null;
 const [whole,fraction='']=raw.split('.'),minor=BigInt(whole)*100n+BigInt(fraction.padEnd(2,'0'));
 return minor>0n?minor:null;
}
function stockFamilyKey(product) {
 if(product.digital_offer||product.kind!=='card'||!/^\d+$/.test(String(product.id))||Number(product.id)<=0||!/^\d+$/.test(String(product.provider_id))||Number(product.provider_id)<=0)return null;
 const kind=product.catalog_kind,currency=product.currency;
 if(typeof kind!=='string'||!kind||typeof currency!=='string'||! /^[A-Z]{3}$/.test(currency))return null;
 return JSON.stringify([String(product.provider_id),kind,currency]);
}
function entry(products,key,grouped=false) {
 const first=products[0],prices=products.map(row=>({raw:row.price,value:positiveDecimal(row.price)})).filter(row=>row.value!==null);
 const lowest=prices.reduce((result,row)=>!result||row.value<result.value?row:result,null);
 return {key,products,grouped,name:grouped?first.provider_name:first.name,kind:first.kind,catalog_kind:first.catalog_kind,currency:first.currency,provider_name:first.provider_name,image_url:grouped?first.provider_image_url||null:first.image_url||first.provider_image_url||null,price:lowest?.raw??null};
}
export function catalogFamilies(products) {
 const buckets=new Map();
 for(const product of products){const key=stockFamilyKey(product);if(key){const bucket=buckets.get(key)||[];bucket.push(product);buckets.set(key,bucket);}}
 const grouped=new Map();
 for(const [key,rows] of buckets){
  const values=rows.map(row=>positiveDecimal(row.face_value));
  // A missing or repeated denomination makes the entire family ambiguous. Keep
  // exact named choices visible instead of choosing one product on its behalf.
  if(rows.length>1&&values.every(value=>value!==null)&&new Set(values.map(String)).size===rows.length){
   const choices=[...rows].sort((a,b)=>positiveDecimal(a.face_value)<positiveDecimal(b.face_value)?-1:1);
   grouped.set(key,entry(choices,`family:${key}`,true));
  }
 }
 const emitted=new Set(),result=[];
 for(const [index,product] of products.entries()){
  const key=stockFamilyKey(product),family=grouped.get(key);
  if(family){if(!emitted.has(key)){result.push(family);emitted.add(key);}}
  else result.push(entry([product],`choice:${product.key||index}`));
 }
 return result;
}

// Filtering never merges/reidentifies choices. In particular, a favorite or a
// search result cannot reveal a different denomination as an implicit choice.
export function visibleCatalogFamilies(families,visibleProducts) {
 const visible=new Set(visibleProducts.map(product=>product.key));
 return families.flatMap(family=>{
  const products=family.products.filter(product=>visible.has(product.key));
  return products.length?[entry(products,family.key,family.grouped)]:[];
 });
}

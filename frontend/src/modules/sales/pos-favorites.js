export function readFavorites(storage,accountId){
 if(!Number.isSafeInteger(Number(accountId))||Number(accountId)<1)return [];
 try{const rows=JSON.parse(storage.getItem(`dananeer:favorite-companies:${accountId}`)||'[]');return Array.isArray(rows)?[...new Set(rows.filter(value=>typeof value==='string'&&/^[a-z0-9:_-]{1,80}$/i.test(value)))].slice(0,500):[];}catch{return [];}
}
export function saveFavorites(storage,accountId,rows){
 if(!Number.isSafeInteger(Number(accountId))||Number(accountId)<1)return;
 try{storage.setItem(`dananeer:favorite-companies:${accountId}`,JSON.stringify(rows.slice(0,500)));}catch{}
}

// Category IDs keep stock cards and each digital connection separate.
function categoryIds(rows){
 return Array.isArray(rows)?[...new Set(rows.filter(value=>typeof value==='string'&&/^(stock:\d+|digital:\d+:\d+)$/.test(value)&&value.length<=80))].slice(0,1000):[];
}
export function readCategoryFavorites(storage,accountId){
 if(!Number.isSafeInteger(Number(accountId))||Number(accountId)<1)return [];
 try{return categoryIds(JSON.parse(storage.getItem(`dananeer:favorite-categories:${Number(accountId)}`)||'[]'));}catch{return [];}
}
export function saveCategoryFavorites(storage,accountId,rows){
 if(!Number.isSafeInteger(Number(accountId))||Number(accountId)<1)return;
 try{storage.setItem(`dananeer:favorite-categories:${Number(accountId)}`,JSON.stringify(categoryIds(rows)));}catch{}
}

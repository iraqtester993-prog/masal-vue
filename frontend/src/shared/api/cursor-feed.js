// The API owns the order and signs each continuation. The view retains only
// the pages already read for the current filters, never an offset or a total.
export function createCursorFeed({read,peek=()=>null,key,parameters=()=>({}),state,onError=()=>{}}){
 const pages=[];
 let revision=0,controller,pending,query='';
 const emptyMeta=()=>({pagination:'cursor',per_page:10,has_more:false,next_cursor:null});
 function validate(response,cursor){
  const meta=response?.meta;
  if(!Array.isArray(response?.data)||response.data.length>10||typeof meta?.has_more!=='boolean'||meta.has_more&&(typeof meta.next_cursor!=='string'||!meta.next_cursor||meta.next_cursor===cursor))throw Error('تعذر قراءة متابعة السجل. اسحب للتحديث أو أعد المحاولة.');
  return response;
 }
 function publish(){
  const unique=new Map();
  for(const page of pages)for(const row of page.data)if(!unique.has(key(row)))unique.set(key(row),row);
  state.rows=[...unique.values()];state.meta=pages.at(-1)?.meta??emptyMeta();
 }
 function requestParameters(params,cursor){return{...params,pagination:'cursor',per_page:10,...(cursor?{cursor}:{})};}
 function hydrate(count=1){
  const params=parameters();query=JSON.stringify(params);pages.length=0;let cursor;
  const seen=new Set();
  for(let page=0;page<Math.max(1,Number(count)||1);page++){
   if(seen.has(cursor))break;seen.add(cursor);
   const cached=peek(requestParameters(params,cursor));if(!cached)break;
   try{validate(cached,cursor);if(cached.meta.has_more&&seen.has(cached.meta.next_cursor))break;}catch{break;}
   pages.push(cached);if(!cached.meta.has_more)break;cursor=cached.meta.next_cursor;
  }
  publish();
 }
 function cancel(){revision++;controller?.abort();controller=undefined;pending=undefined;state.loading=false;}
 function load({append=false,fresh=false,count=1}={}){
  if(append&&pending)return pending;
  const params=parameters(),identity=JSON.stringify(params);
  if(append&&identity!==query)return load();
  if(append&&pages.length&&!state.meta.has_more)return Promise.resolve();
  cancel();const expected=revision;controller=new AbortController();const signal=controller.signal;
  if(identity!==query){pages.length=0;query=identity;publish();}
  const next=append?[...pages]:[],limit=append?1:Math.max(1,Number(count)||1);
  let cursor=append?pages.at(-1)?.meta.next_cursor:undefined;
  const seen=new Set(append?[undefined,...next.slice(0,-1).map(page=>page.meta.next_cursor)]:[]);
  state.loading=true;state.error='';
  const work=(async()=>{
   try{
    for(let page=0;page<limit;page++){
     if(seen.has(cursor))throw Error('تعذر قراءة متابعة السجل. اسحب للتحديث أو أعد المحاولة.');seen.add(cursor);
     const response=await read(requestParameters(params,cursor),signal,{fresh});
     if(expected!==revision||signal.aborted)return;validate(response,cursor);
     if(response.meta.has_more&&seen.has(response.meta.next_cursor))throw Error('تعذر قراءة متابعة السجل. اسحب للتحديث أو أعد المحاولة.');next.push(response);
     if(!response.meta.has_more)break;cursor=response.meta.next_cursor;
    }
    if(expected!==revision||signal.aborted)return;
    pages.splice(0,pages.length,...next);publish();
   }catch(error){
    if(expected===revision&&error.name!=='AbortError'){
     if([401,403,404].includes(error.status)||error.status===422&&error.errors?.cursor){pages.length=0;publish();}
     state.error=error.message||'تعذر تحميل السجل. اسحب للتحديث أو أعد المحاولة.';await onError(error);
    }
   }finally{if(expected===revision){state.loading=false;pending=undefined;}}
  })();
  pending=work;return work;
 }
 return{hydrate,load,more:()=>load({append:true}),refresh:()=>load({fresh:true,count:Math.max(1,pages.length)}),cancel,pageCount:()=>Math.max(1,pages.length)};
}

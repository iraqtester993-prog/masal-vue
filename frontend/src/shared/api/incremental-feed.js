export function createIncrementalFeed({read,peek=()=>null,key,parameters=()=>({}),state,onError=()=>{}}) {
  const pages=new Map();
  let revision=0,controller,pending,query='';
  function publish(){
    const unique=new Map();
    for(const [,response] of [...pages].sort((a,b)=>a[0]-b[0]))for(const row of response.data)if(!unique.has(key(row)))unique.set(key(row),row);
    state.rows=[...unique.values()];
    const last=pages.get(Math.max(0,...pages.keys()));
    state.meta=last?.meta??{current_page:1,last_page:1,total:0};
  }
  function hydrate(count=1){
    const params=parameters();query=JSON.stringify(params);pages.clear();
    for(let page=1;page<=count;page++){
      const cached=peek({...params,page,per_page:10});
      if(!cached)break;
      pages.set(page,cached);
    }
    publish();
  }
  function cancel(){revision++;controller?.abort();controller=undefined;pending=undefined;state.loading=false;}
  function load({append=false,fresh=false,count=1}={}){
    if(append&&pending)return pending;
    const params=parameters(),identity=JSON.stringify(params);
    if(append&&identity!==query)return load();
    if(append&&pages.size&&state.meta.current_page>=state.meta.last_page)return Promise.resolve();
    cancel();
    const expected=revision;controller=new AbortController();const signal=controller.signal;
    if(identity!==query){pages.clear();query=identity;publish();}
    const first=append?pages.size+1:1,last=append?first:Math.max(1,count),next=append?new Map(pages):new Map();
    state.loading=true;state.error='';
    const work=(async()=>{
      try{
        for(let page=first;page<=last;page++){
          const response=await read({...params,page,per_page:10},signal,{fresh});
          if(expected!==revision||signal.aborted)return;
          next.set(page,response);
          if(page>=response.meta.last_page)break;
        }
        if(expected!==revision||signal.aborted)return;
        pages.clear();for(const [page,response] of next)pages.set(page,response);publish();
      }catch(error){
        if(expected===revision&&error.name!=='AbortError'){
          if([401,403,404].includes(error.status)){pages.clear();publish();}
          state.error=error.message||'تعذر تحميل السجل. اسحب للتحديث أو أعد المحاولة.';
          await onError(error);
        }
      }finally{if(expected===revision){state.loading=false;pending=undefined;}}
    })();
    pending=work;return work;
  }
  return {hydrate,load,more:()=>load({append:true}),refresh:()=>load({fresh:true,count:Math.max(1,pages.size)}),cancel,pageCount:()=>Math.max(1,pages.size)};
}

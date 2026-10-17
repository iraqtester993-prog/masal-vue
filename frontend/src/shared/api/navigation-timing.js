// Timings only: no response bodies, credentials or business data are retained.
export function createNavigationTiming({now,publish,schedule}) {
  let current=null,sequence=0;
  const output=()=>publish({path:current.path,paint_ms:current.paint===null?null:Math.round(current.paint-current.start),ready_ms:current.finished?Math.round(Math.max(current.paint,current.lastEnd)-current.start):null,pending:current.pending,requests:current.requests,errors:current.errors,network_ms:Math.round(current.network),server_ms:current.server===null?null:Math.round(current.server)});
  function settle(entry) {
    const revision=entry.revision;
    schedule(()=>{if(current!==entry||entry.revision!==revision||entry.pending||entry.paint===null||entry.finished)return;entry.finished=true;output();});
  }
  function start(path){current={id:++sequence,path,start:now(),paint:null,lastEnd:0,pending:0,requests:0,errors:0,network:0,server:null,revision:0,finished:false};output();}
  function paint(path){if(!current||current.path!==path)return;current.paint=now();output();settle(current);}
  function read(path,method='GET') {
    if(!current||current.finished||method!=='GET'||/^\/(auth|maps|preferences|notifications)(?:\/|\?|$)/.test(path))return ()=>{};
    const entry=current;entry.pending++;entry.requests++;entry.revision++;output();let ended=false;
    return (failed=false)=>{if(ended||current!==entry)return;ended=true;entry.pending--;entry.lastEnd=now();if(failed)entry.errors++;entry.revision++;output();settle(entry);};
  }
  function network(path,method='GET') {
    const entry=current;
    if(!entry||entry.finished||method!=='GET'||/^\/(auth|maps|preferences|notifications)(?:\/|\?|$)/.test(path))return ()=>{};
    return (duration,server)=>{if(current!==entry||entry.finished)return;if(Number.isFinite(duration))entry.network=Math.max(entry.network,duration);if(Number.isFinite(server))entry.server=Math.max(entry.server??0,server);output();};
  }
  return {start,paint,read,network,ready:()=>current?.finished===true};
}

export const navigationTiming=typeof document==='undefined'?null:createNavigationTiming({
  now:()=>performance.now(),
  schedule:callback=>setTimeout(()=>setTimeout(callback,0),0),
  publish:values=>{for(const [key,value]of Object.entries(values)){const name='nav'+key.replace(/(^|_)([a-z])/g,(_,prefix,letter)=>letter.toUpperCase());document.documentElement.dataset[name]=value===null?'':String(value);}},
});

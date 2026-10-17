export function refreshBlocked({pendingMutation=false,dialogOpen=false,printerBusy=false}={}){return pendingMutation||dialogOpen||printerBusy;}
export function installPullRefresh({target=window,canStart,onRefresh,onProgress=()=>{},scrollTop=()=>window.scrollY}){
 let start=null,progress=0;
 const reset=()=>{start=null;progress=0;onProgress(0);};
 function down(event){reset();if(event.touches.length!==1||scrollTop()>0||!canStart()||event.target?.closest?.('input,textarea,select,button,dialog,.sidebar,.pos-company-strip,.pos-sale-company-track,.pos-app-nav'))return;const point=event.touches[0];start={x:point.clientX,y:point.clientY};}
 function move(event){if(!start||event.touches.length!==1)return;const point=event.touches[0],dy=point.clientY-start.y,dx=Math.abs(point.clientX-start.x);if(dx>18&&dx>dy){reset();return;}if(dy<0||!canStart()){reset();return;}if(dy>16&&dy>dx*1.3){if(event.cancelable)event.preventDefault();progress=Math.min(96,dy*.45);onProgress(progress);}}
 function up(){const ready=progress>=64&&canStart();reset();if(ready)onRefresh();}
 target.addEventListener('touchstart',down,{passive:true});target.addEventListener('touchmove',move,{passive:false});target.addEventListener('touchend',up);target.addEventListener('touchcancel',reset);
 return ()=>{target.removeEventListener('touchstart',down);target.removeEventListener('touchmove',move);target.removeEventListener('touchend',up);target.removeEventListener('touchcancel',reset);reset();};
}

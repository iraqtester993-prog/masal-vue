export function refreshBlocked({pendingMutation=false,dialogOpen=false,printerBusy=false}={}){return pendingMutation||dialogOpen||printerBusy;}
export function pullScrollTop(node,{surface=typeof document==='undefined'?null:document,target=typeof window==='undefined'?null:window}={}){
 const root=surface?.scrollingElement;let top=Number(root?.scrollTop??target?.scrollY)||0;
 for(let element=node; element&&element!==root; element=element.parentElement){
  // A nested list must be at its top too; otherwise a downward gesture scrolls
  // that list instead of refreshing the page behind it.
  if(Number(element.scrollHeight)>Number(element.clientHeight)+1)top=Math.max(top,Number(element.scrollTop)||0);
 }
 return top;
}
export function installPullRefresh({target=window,canStart,onRefresh,onProgress=()=>{},scrollTop=node=>pullScrollTop(node,{target})}){
 let start=null,progress=0;
 const reset=()=>{start=null;progress=0;onProgress(0);};
 function down(event){reset();if(event.touches.length!==1||scrollTop(event.target)>0||!canStart()||event.target?.closest?.('input,textarea,select,button,a,[contenteditable="true"],dialog,[role="dialog"],.sidebar,.pos-company-strip,.pos-sale-company-track,.pos-app-nav,[data-no-pull-refresh]'))return;const point=event.touches[0];start={x:point.clientX,y:point.clientY};}
 function move(event){if(!start||event.touches.length!==1)return;const point=event.touches[0],dy=point.clientY-start.y,dx=Math.abs(point.clientX-start.x);if(dx>18&&dx>dy){reset();return;}if(dy<0||!canStart()){reset();return;}if(dy>16&&dy>dx*1.3){if(event.cancelable)event.preventDefault();progress=Math.min(96,dy*.45);onProgress(progress);}}
 function up(){const ready=progress>=64&&canStart();reset();if(ready)onRefresh();}
 target.addEventListener('touchstart',down,{passive:true});target.addEventListener('touchmove',move,{passive:false});target.addEventListener('touchend',up);target.addEventListener('touchcancel',reset);
 return ()=>{target.removeEventListener('touchstart',down);target.removeEventListener('touchmove',move);target.removeEventListener('touchend',up);target.removeEventListener('touchcancel',reset);reset();};
}

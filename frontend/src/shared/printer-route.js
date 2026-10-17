import {ref,onMounted,onBeforeUnmount} from 'vue';
import {requiresNativePrinter,getNativePrinterCapabilities} from './native-print.js';
export function usePrinterRoute(){
 const printRoute=ref(requiresNativePrinter()?'direct':'browser');let alive=true;
 async function refresh(){if(!requiresNativePrinter())return;try{const capabilities=await getNativePrinterCapabilities();if(alive)printRoute.value=capabilities.printRoute==='browser'?'browser':'direct';}catch{/* Older bridges keep their existing direct path. */}}
 onMounted(()=>{refresh();window.addEventListener('dananeer:printer-change',refresh);});
 onBeforeUnmount(()=>{alive=false;window.removeEventListener('dananeer:printer-change',refresh);});
 return {printRoute};
}

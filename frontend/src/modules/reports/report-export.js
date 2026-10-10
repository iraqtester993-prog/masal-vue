import { workbookSections, printableReport } from './report-model.js';
export async function exportReport(api, parameters, print, signal, current = () => true) {
  const response=await api.export({...parameters,purpose:print?'print':'export'},signal);
  if(!current()||signal?.aborted) return;
  if(!Array.isArray(response?.data?.sections)) throw new Error('استجابة التقرير غير متوقعة.');
  if(print) {
    const frame=document.createElement('iframe'); frame.title='طباعة التقرير'; frame.style.cssText='position:fixed;width:0;height:0;border:0';
    frame.srcdoc=printableReport(response.data);
    frame.addEventListener('load',()=>{frame.contentWindow.addEventListener('afterprint',()=>frame.remove(),{once:true});frame.contentWindow.focus();frame.contentWindow.print();},{once:true});
    document.body.append(frame);
  } else {
    const {reportWorkbook}=await import('../../shared/files/excel-export.js');
    if(!current()||signal?.aborted) return;
    const blob=reportWorkbook(workbookSections(response.data));
    const url=URL.createObjectURL(blob),anchor=document.createElement('a');
    anchor.href=url;anchor.download=`masal-report-${response.data.generated_at?.slice(0,10)||'export'}.xlsx`;anchor.click();
    setTimeout(()=>URL.revokeObjectURL(url),1000);
  }
}

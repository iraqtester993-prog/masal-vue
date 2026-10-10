import {createOperationsApi} from './operations-api.js';

// Only actual interaction extends idle time; polling and client timestamps never do.
export function trackTimeActivity(session, surface = window, now = () => Date.now()) {
  const controller = new AbortController(), api = createOperationsApi(session.api);
  let previous = -Infinity, pending = false, disposed = false;
  async function activity(event) {
    if (disposed || !event.isTrusted || !session.state.identity || surface.document?.visibilityState === 'hidden' || pending || now()-previous<30000) return;
    pending = true; previous = now();
    try {await api.activity(controller.signal);}
    catch (cause) {if ([401,403].includes(cause.status)) await session.refresh();}
    finally {pending = false;}
  }
  const events = ['pointerdown','keydown','touchstart','wheel'];
  for (const event of events) surface.addEventListener(event,activity,{passive:true});
  return () => {disposed=true;controller.abort();for (const event of events) surface.removeEventListener(event,activity);};
}

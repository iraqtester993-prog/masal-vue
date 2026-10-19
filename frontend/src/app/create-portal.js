import { createApp } from 'vue';
import PortalRoot from './PortalRoot.vue';
import { portalConfigs } from './portal-config.js';
import { createSession, portalContextKey } from '../modules/auth/session.js';
import { createPortalRouter } from '../router/index.js';
import { createUserPreferences, preferencesContextKey } from '../modules/preferences/preferences-state.js';
import '../shared/styles/base.css';
import {createPasswordResetMonitor} from '../modules/auth/password-reset.js';
import {createIdentityMonitor} from '../modules/auth/identity-monitor.js';
import {installTextSelectionGuard} from '../shared/text-selection.js';

export function mountPortal(id, layout) {
  const portal = portalConfigs[id];
  if (!portal) throw new Error('Unknown portal');
  const session = createSession(id);
  const preferences = createUserPreferences(session);
  const router = createPortalRouter(session);
  const passwordResetMonitor = createPasswordResetMonitor(session);
  const identityMonitor = createIdentityMonitor(session);
  document.documentElement.dataset.portal = id;
  const releaseTextSelectionGuard = id === 'pos' ? installTextSelectionGuard(document) : () => {};
  document.title = id==='pos'?'دنانير':`ماسال | ${portal.title}`;
  if(id==='pos'){
    if('serviceWorker' in navigator && window.isSecureContext){const register=()=>navigator.serviceWorker.register('/sw.js',{scope:'/',updateViaCache:'none'}).catch(()=>{});if(document.readyState==='complete')register();else window.addEventListener('load',register,{once:true});}
  }
  const app = createApp(PortalRoot, { layout });
  app.provide(portalContextKey, { portal, session });
  app.provide(preferencesContextKey, preferences);
  app.onUnmount(() => {releaseTextSelectionGuard();preferences.dispose();passwordResetMonitor.dispose();identityMonitor.dispose();router.posBackNavigation?.dispose();});
  app.use(router);
  router.isReady().then(() => app.mount('#app'));
}

import { createApp } from 'vue';
import PortalRoot from './PortalRoot.vue';
import { portalConfigs } from './portal-config.js';
import { createSession, portalContextKey } from '../modules/auth/session.js';
import { createPortalRouter } from '../router/index.js';
import { createUserPreferences, preferencesContextKey } from '../modules/preferences/preferences-state.js';
import '../shared/styles/base.css';

export function mountPortal(id, layout) {
  const portal = portalConfigs[id];
  if (!portal) throw new Error('Unknown portal');
  const session = createSession(id);
  const preferences = createUserPreferences(session);
  const router = createPortalRouter(session);
  document.documentElement.dataset.portal = id;
  document.title = `ماسال | ${portal.title}`;
  const app = createApp(PortalRoot, { layout });
  app.provide(portalContextKey, { portal, session });
  app.provide(preferencesContextKey, preferences);
  app.onUnmount(() => preferences.dispose());
  app.use(router);
  router.isReady().then(() => app.mount('#app'));
}

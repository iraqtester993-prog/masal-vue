import {hasOwnerAuthority} from './capabilities.js';
export function passwordResetState(value) {
  const data = value?.data?.password_reset || value?.data || value?.password_reset || value;
  return typeof data?.required === 'boolean' && Number.isSafeInteger(data.version) && data.version >= 0
    ? {required:data.required,version:data.version} : null;
}

export const passwordChangeRequired = identity => identity?.password_reset?.required === true;

export function newPasswordError(password, confirmation) {
  if ([...String(password)].length < 9) return 'كلمة المرور يجب ألا تقل عن 9 خانات.';
  if (new TextEncoder().encode(password).length > 72) return 'كلمة المرور يجب ألا تتجاوز 72 بايت.';
  if (password !== confirmation) return 'تأكيد كلمة المرور غير مطابق.';
  return '';
}

export function canResetAccountPassword(identity, account) {
  return hasOwnerAuthority(identity) && ['system','main_agent','sub_agent','sub_branch'].includes(identity?.account?.type)
    && identity?.permissions?.includes('account.password_reset')
    && account?.can_reset_password === true && account.id !== identity.account.id && account.type !== 'system';
}

export async function bootstrapPasswordRecoveryDevice(api, options = {}) {
  const device = options.readIdentity && options.signChallenge ? options : await import('../../shared/device-identity.js');
  const info = await (device.readIdentity || device.readDeviceIdentity)();
  options.signal?.throwIfAborted();
  if(info?.proof_unavailable&&(options.nativeBridge??(await import('../../shared/native-print.js')).requiresNativePrinter())){
    const error=Error('تعذر الوصول إلى اعتماد الجهاز مؤقتًا.');error.code='recovery_bridge_unavailable';throw error;
  }
  if (!info?.installation_id || !info.proof_public_key || !['android-keystore','webcrypto'].includes(info.proof_key_kind)) return null;
  const payload = {installation_id:info.installation_id,proof_public_key:info.proof_public_key,proof_key_kind:info.proof_key_kind};
  const challenge = await api.mutate('/auth/password-reset/device-challenge','POST',payload,options.signal);
  options.signal?.throwIfAborted();
  if (!challenge?.data?.challenge_id || typeof challenge.data.challenge !== 'string' || !challenge.data.challenge) throw Error('تعذر التحقق من اعتماد الجهاز.');
  const signature = await (device.signChallenge || device.signDeviceChallenge)(challenge.data.challenge);
  options.signal?.throwIfAborted();
  if (typeof signature !== 'string' || !signature) throw Error('تعذر توقيع اعتماد الجهاز.');
  return (await api.mutate('/auth/password-reset/device-bind','POST',{...payload,proof_challenge_id:challenge.data.challenge_id,proof_signature:signature},options.signal)).data;
}

export function createRecoveryDeviceAttempt(bootstrap,{now=Date.now}={}){
  let attempted=false,successful=false,disposed=false,retryAt=Infinity,pending=null;
  async function run({focus=false}={}){
    if(disposed||successful)return null;
    if(pending)return pending;
    if(attempted&&(!focus||now()<retryAt))return null;
    attempted=true;retryAt=Infinity;
    pending=(async()=>{
      await Promise.resolve();if(disposed){pending=null;return null;}
      try{const value=await bootstrap();if(disposed)return null;successful=!!value;return value;}
      catch(error){
        if(!disposed&&error.name!=='AbortError'&&(error.status===0||error.status>=500||['native_timeout','recovery_bridge_unavailable'].includes(error.code)))retryAt=now()+30000;
        return null;
      }finally{pending=null;}
    })();return pending;
  }
  return {run,dispose(){disposed=true;}};
}

// A small uncached session check. Page navigation continues using its existing read cache.
export function createPasswordResetMonitor(session, environment = {}) {
  const surface = environment.surface ?? (typeof window === 'undefined' ? null : window);
  const document = environment.document ?? surface?.document;
  const setTimer = environment.setInterval ?? globalThis.setInterval;
  const clearTimer = environment.clearInterval ?? globalThis.clearInterval;
  let disposed = false, pending = null;
  const saving = () => session.state.busy || session.api.hasPendingMutation?.();
  const current = (identity, version) => !disposed && !saving()
    && session.state.identity === identity && (session.state.identity?.password_reset?.version ?? -1) === version;
  async function refresh() {
    if (disposed || !session.state.identity || saving() || document?.visibilityState === 'hidden') return;
    if (pending) return pending;
    const identity = session.state.identity, version = identity.password_reset?.version ?? -1;
    pending = (async () => {
      try {
        const response = await session.api.passwordResetStatus();
        if (current(identity, version))
          await session.applyPasswordResetStatus(response);
      } catch (error) {
        if (current(identity, version) && [401,403].includes(error.status)
          && error.code !== 'PASSWORD_CHANGE_REQUIRED') await session.refresh();
      } finally { pending = null; }
    })();
    return pending;
  }
  const timer = setTimer(refresh, 5000);
  surface?.addEventListener('focus',refresh);
  document?.addEventListener('visibilitychange',refresh);
  return {refresh,dispose(){disposed=true;clearTimer(timer);surface?.removeEventListener('focus',refresh);document?.removeEventListener('visibilitychange',refresh);}};
}

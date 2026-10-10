import {reactive} from 'vue';
import {validTrackingToken} from './inquiry-model.js';

export const inquiryNotificationsKey = Symbol('company.inquiry-notifications');
const storageKey = 'masal.site-inquiry-notifications.v1';
const maxAge = 90 * 24 * 60 * 60 * 1000;

export function createInquiryNotifications(storage, now = () => Date.now()) {
  const state = reactive({records:[], detail:null, detailToken:''});
  function persist() {
    try { storage?.setItem(storageKey, JSON.stringify(state.records)); } catch { /* Private browsing may disable storage. */ }
  }
  function restore() {
    try {
      const records = JSON.parse(storage?.getItem(storageKey) || '[]');
      if (Array.isArray(records)) state.records = records.filter(row => validTrackingToken(row?.token)
        && Number.isSafeInteger(row.seen) && row.seen >= 0 && Number.isSafeInteger(row.latest) && row.latest >= row.seen
        && Number.isSafeInteger(row.unread) && row.unread >= 0 && Number.isFinite(row.time)
        && row.time <= now() && now() - row.time < maxAge).slice(-5);
    } catch { state.records = []; }
  }
  function remember(token) {
    if (!validTrackingToken(token)) return;
    if (!state.records.some(row => row.token === token)) {
      state.records.push({token, seen:0, latest:0, unread:0, version:0, time:now()});
      state.records = state.records.slice(-5);
      persist();
    }
  }
  function accept(token, detail, read = false) {
    remember(token);
    const row = state.records.find(item => item.token === token);
    if (!row) return;
    if (Number.isSafeInteger(detail?.version) && detail.version < (row.version || 0)) return;
    row.version = Number.isSafeInteger(detail?.version) ? detail.version : row.version || 0;
    const replies = (detail?.messages || []).filter(message => message.sender === 'staff' && Number.isSafeInteger(message.id));
    row.latest = Math.max(row.latest, ...replies.map(message => message.id));
    row.time = now();
    if (read) row.seen = row.latest;
    row.unread = replies.filter(message => message.id > row.seen).length;
    state.detailToken = token;
    state.detail = detail;
    persist();
  }
  function markRead(token) {
    const row = state.records.find(item => item.token === token);
    if (row) {row.seen = row.latest; row.unread = 0; persist();}
  }
  function forget(token) {state.records = state.records.filter(row => row.token !== token); persist();}
  return {state, restore, remember, accept, markRead, forget};
}

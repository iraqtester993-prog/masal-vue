import test from 'node:test';
import assert from 'node:assert/strict';
import {createApiClient,ApiError} from '../src/shared/api/client.js';
import {createSupportApi} from '../src/modules/support/support-api.js';
import {createNotificationsApi} from '../src/modules/notifications/notifications-api.js';
import {createMutationKey} from '../src/modules/support/communications.js';

test('support upload stays multipart and cookie authenticated; direct messages reference only the server-issued attachment ID',async () => {
  const previousFetch = globalThis.fetch, previousDocument = globalThis.document, calls = [];
  globalThis.document = {cookie:''};
  globalThis.fetch = async (url,options) => {
    calls.push({url,options});
    if (url === '/sanctum/csrf-cookie') {globalThis.document.cookie = 'XSRF-TOKEN=support-token'; return new Response(null,{status:204});}
    return Response.json({data:{id:31}},{status:201});
  };
  try {
    const api = createSupportApi(createApiClient('/api/v1'));
    const file = new File([new Uint8Array([137,80,78,71])],'private.png',{type:'image/png'});
    const uploaded = await api.upload(file,'support');
    await api.create({recipient_id:9,title:'رسالة',description:'محتوى',attachment_id:uploaded.data.id,idempotency_key:'thread-1'});
    assert.equal(calls[0].url,'/sanctum/csrf-cookie');
    const upload = calls[1].options; assert.equal(upload.credentials,'include'); assert.equal(upload.headers['X-XSRF-TOKEN'],'support-token'); assert.equal(upload.headers['Content-Type'],undefined);
    assert.ok(upload.body instanceof FormData); assert.equal(upload.body.get('kind'),'support'); assert.equal(upload.body.get('file').type,'image/png');
    assert.deepEqual(JSON.parse(calls[2].options.body),{recipient_id:9,title:'رسالة',description:'محتوى',attachment_id:31,idempotency_key:'thread-1'});
    assert.ok(!calls[2].options.body.includes('data:image')); assert.equal(calls[2].options.headers['X-Masal-Portal'],undefined);
  } finally {globalThis.fetch = previousFetch; globalThis.document = previousDocument;}
});

test('a lost reply response retains the same CAS version and idempotency key for retry; a changed message receives another key',async () => {
  const previousFetch = globalThis.fetch, previousDocument = globalThis.document, requests = [];
  globalThis.document = {cookie:'XSRF-TOKEN=token'};
  globalThis.fetch = async (url,options) => {requests.push({url,payload:JSON.parse(options.body)}); if (requests.length === 1) throw Error('connection lost'); return Response.json({data:{id:4,version:7}});};
  try {
    let sequence = 0; const keys = createMutationKey(() => `reply-${++sequence}`), api = createSupportApi(createApiClient('/api/v1'));
    const payload = {body:'نفس الرد',version:6};
    const send = data => api.reply(4,{...data,idempotency_key:keys.for({id:4,...data})});
    await assert.rejects(send(payload),error => error instanceof ApiError && error.status === 0);
    await send(payload); assert.deepEqual(requests[0],requests[1]);
    await send({...payload,body:'رد مصحح'}); assert.notEqual(requests[2].payload.idempotency_key,requests[1].payload.idempotency_key);
    assert.equal(requests[1].payload.version,6); assert.equal(requests[1].url,'/api/v1/support/tickets/4/replies');
  } finally {globalThis.fetch = previousFetch; globalThis.document = previousDocument;}
});

test('notice search cannot inject recipient filters, unread actions identify only the notice, and stale support actions retain 409 semantics',async () => {
  const previousFetch = globalThis.fetch, previousDocument = globalThis.document, calls = [];
  globalThis.document = {cookie:'XSRF-TOKEN=token'};
  globalThis.fetch = async (url,options) => {calls.push({url,options}); return url.endsWith('/status') ? Response.json({message:'تغيرت المحادثة'},{status:409}) : Response.json({data:[],meta:{}});};
  try {
    const client = createApiClient('/api/v1'), notices = createNotificationsApi(client), support = createSupportApi(client);
    await notices.list({query:'اسم & user_id=999',filter:'unread',locale:'ckb',page:2});
    const query = new URL(calls[0].url,'https://portal.example').searchParams;
    assert.equal(query.get('query'),'اسم & user_id=999'); assert.equal(query.get('user_id'),null); assert.equal(query.get('locale'),'ckb');
    await notices.read(18); assert.equal(calls[1].url,'/api/v1/notifications/18/read'); assert.deepEqual(JSON.parse(calls[1].options.body),{});
    await assert.rejects(support.status(7,{action:'escalate',version:3,idempotency_key:'status-1'}),error => error.status === 409 && error.message === 'تغيرت المحادثة');
  } finally {globalThis.fetch = previousFetch; globalThis.document = previousDocument;}
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient, cookieValue, ApiError } from '../src/shared/api/client.js';

test('summary cache respects refresh, financial writes, provider settlement and auth boundaries', async () => {
  const oldFetch = globalThis.fetch, oldDocument = globalThis.document;
  globalThis.document = { cookie: 'XSRF-TOKEN=current' };
  let reads = 0;
  globalThis.fetch = async url => Response.json({ data: { value: url.includes('/dashboard/') ? ++reads : 1 } });
  try {
    const api = createApiClient('/api/v1');
    const path = '/dashboard/summary?currency=IQD';
    await api.request(path); await api.request(path);
    assert.equal(reads, 1);
    await api.mutate('/sales/device-session', 'POST', {});
    await api.request('/digital/orders/summary?provider=topup');
    await api.request(path); assert.equal(reads, 1);
    await api.request(path, { fresh: true }); assert.equal(reads, 2);
    await api.mutate('/finance/transfers', 'POST', {});
    assert.equal(api.peekRequest(path), null);
    await api.request(path); assert.equal(reads, 3);
    await api.request('/digital/orders/order-id');
    assert.equal(api.peekRequest(path), null);
    await api.request(path); await api.logout();
    assert.equal(api.peekRequest(path), null);
    globalThis.fetch = async () => Response.json({}, { status: 403 });
    await assert.rejects(api.request('/accounts'));
    assert.equal(api.peekRequest(path), null);
  } finally { globalThis.fetch = oldFetch; globalThis.document = oldDocument; }
});

test('CSRF request and login use cookies without a client-selected portal or role', async () => {
  const previousFetch = globalThis.fetch;
  const previousDocument = globalThis.document;
  const calls = [];
  globalThis.document = { cookie: 'XSRF-TOKEN=token%3Dvalue; unrelated=other' };
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    return url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
      : Response.json({ data: {} });
  };
  try {
    const api = createApiClient('/api/v1');
    await api.csrf();
    await api.login({ login: 'test', password: 'test' });
    assert.equal(calls[0].url, '/sanctum/csrf-cookie');
    assert.equal(calls[1].url, '/api/v1/auth/login');
    assert.equal(calls[1].options.credentials, 'include');
    assert.equal(calls[1].options.headers['X-XSRF-TOKEN'], 'token=value');
    assert.equal(calls[1].options.headers['X-Masal-Portal'], undefined);
    assert.deepEqual(JSON.parse(calls[1].options.body), { login: 'test', password: 'test' });
  } finally { globalThis.fetch = previousFetch; globalThis.document = previousDocument; }
});

test('unexpected HTML cannot be accepted as a successful API response', async () => {
  const previousFetch = globalThis.fetch;
  globalThis.fetch = async () => new Response('<html>Login</html>', { status: 200, headers: { 'content-type': 'text/html' } });
  try {
    await assert.rejects(createApiClient('/api/v1').me(), (error) => error instanceof ApiError);
  } finally { globalThis.fetch = previousFetch; }
});

test('server failures do not expose server internals in the UI', async () => {
  const previousFetch = globalThis.fetch;
  globalThis.fetch = async () => Response.json({ message: 'SQL credentials and stack trace' }, { status: 500 });
  try {
    await assert.rejects(createApiClient('/api/v1').me(), (error) => error.status === 500 && !error.message.includes('SQL'));
  } finally { globalThis.fetch = previousFetch; }
});

test('malformed CSRF cookies are ignored safely', () => {
  const previousDocument = globalThis.document;
  globalThis.document = { cookie: 'XSRF-TOKEN=%INVALID' };
  try { assert.equal(cookieValue('XSRF-TOKEN'), ''); }
  finally { globalThis.document = previousDocument; }
});

test('account creation obtains a CSRF cookie when missing before posting the supplied account and user', async () => {
  const previousFetch = globalThis.fetch;
  const previousDocument = globalThis.document;
  const calls = [];
  const payload = { name: 'Branch', type: 'sub_agent', parent_id: 10, user: { name: 'Owner', login: 'owner', email: 'owner@example.test', password: 'LongPassword123', password_confirmation: 'LongPassword123' } };
  globalThis.document = { cookie: '' };
  globalThis.fetch = async (url, options) => {
    calls.push({ url, options });
    if (url === '/sanctum/csrf-cookie') {
      globalThis.document.cookie = 'XSRF-TOKEN=fresh%3Dtoken';
      return new Response(null, { status: 204 });
    }
    return Response.json({ data: { account: { id: 11 } } }, { status: 201 });
  };
  try {
    await createApiClient('/api/v1').createAccount(payload);
    assert.equal(calls[0].url, '/sanctum/csrf-cookie');
    assert.equal(calls[1].url, '/api/v1/accounts');
    assert.equal(calls[1].options.method, 'POST');
    assert.equal(calls[1].options.headers['X-XSRF-TOKEN'], 'fresh=token');
    assert.equal(calls[1].options.credentials, 'include');
    assert.deepEqual(JSON.parse(calls[1].options.body), payload);
  } finally { globalThis.fetch = previousFetch; globalThis.document = previousDocument; }
});

test('validation errors retain nested account-user field paths for the form', async () => {
  const previousFetch = globalThis.fetch;
  const previousDocument = globalThis.document;
  globalThis.document = { cookie: 'XSRF-TOKEN=valid' };
  globalThis.fetch = async (url) => url === '/sanctum/csrf-cookie' ? new Response(null, { status: 204 })
    : Response.json({ message: 'Validation failed', errors: { 'user.login': ['Already taken'] } }, { status: 422 });
  try {
    await assert.rejects(createApiClient('/api/v1').createAccount({}), (error) => error.status === 422 && error.errors['user.login'][0] === 'Already taken');
  } finally { globalThis.fetch = previousFetch; globalThis.document = previousDocument; }
});

test('server account filters encode search values without injecting query fields', async () => {
  const previousFetch=globalThis.fetch;let requested;
  globalThis.fetch=async(url)=>{requested=url;return Response.json({data:[],meta:{}});};
  try{await createApiClient('/api/v1').accounts({kind:'agents',q:'name & parent_id=999',page:2,type:'',status:null});const query=new URL(requested,'https://example.test').searchParams;assert.equal(query.get('q'),'name & parent_id=999');assert.equal(query.get('parent_id'),null);assert.equal(query.get('kind'),'agents');assert.equal(query.has('type'),false);}
  finally{globalThis.fetch=previousFetch;}
});

test('private image uploads keep FormData intact and let the browser set multipart boundaries', async () => {
  const previousFetch=globalThis.fetch,previousDocument=globalThis.document;const calls=[];
  globalThis.document={cookie:'XSRF-TOKEN=current'};
  globalThis.fetch=async(url,options)=>{calls.push({url,options});return url==='/sanctum/csrf-cookie'?new Response(null,{status:204}):Response.json({data:{id:1},account_version:2},{status:201});};
  try{const form=new FormData();form.append('version','1');form.append('kind','agent_image');form.append('file',new Blob(['test'],{type:'image/png'}),'test.png');await createApiClient('/api/v1').uploadAttachment(4,form);assert.equal(calls.length,1);assert.equal(calls[0].url,'/api/v1/accounts/4/attachments');assert.equal(calls[0].options.body,form);assert.equal(calls[0].options.headers['Content-Type'],undefined);assert.equal(calls[0].options.headers['X-XSRF-TOKEN'],'current');assert.equal(calls[0].options.credentials,'include');}
  finally{globalThis.fetch=previousFetch;globalThis.document=previousDocument;}
});
test('expired CSRF does not automatically repeat an account mutation', async () => {
  const previousFetch=globalThis.fetch,previousDocument=globalThis.document;let calls=0;
  globalThis.document={cookie:'XSRF-TOKEN=expired'};
  globalThis.fetch=async()=>{calls+=1;return Response.json({message:'Expired'},{status:419});};
  try{await assert.rejects(createApiClient('/api/v1').updateAccount(4,{version:1,name:'New name'}),(failure)=>failure.status===419);assert.equal(calls,1);}
  finally{globalThis.fetch=previousFetch;globalThis.document=previousDocument;}
});

test('API reads bypass browser cache and discard a response cancelled during JSON parsing', async () => {
  const previousFetch = globalThis.fetch;
  const controller = new AbortController();
  let finishBody, bodyStarted;
  const started = new Promise(resolve => { bodyStarted = resolve; });
  globalThis.fetch = async (_, options) => {
    assert.equal(options.cache, 'no-store');
    return { ok: true, status: 200, headers: new Headers({'content-type':'application/json'}), json: () => {
      bodyStarted();
      return new Promise(resolve => { finishBody = resolve; });
    }};
  };
  try {
    const pending = createApiClient('/api/v1').request('/accounts', {signal:controller.signal});
    await started;
    controller.abort();
    finishBody({data:[{id:123}]});
    await assert.rejects(pending, error => error.name === 'AbortError');
  } finally { globalThis.fetch = previousFetch; }
});

test('API deadline remains active while the response body is downloading', async () => {
  const previousFetch = globalThis.fetch;
  let aborted = false;
  globalThis.fetch = async (_, {signal}) => ({
    ok: true, status: 200, headers: new Headers({'content-type':'application/json'}),
    json: () => new Promise((resolve, reject) => {
      signal.addEventListener('abort', () => {aborted = true; reject(signal.reason);}, {once:true});
    }),
  });
  try {
    await assert.rejects(createApiClient('/api/v1').request('/dashboard/summary', {timeoutMs:20}), error => error.name === 'ApiError');
    assert.equal(aborted, true);
  } finally { globalThis.fetch = previousFetch; }
});

import test from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient, cookieValue, ApiError } from '../src/shared/api/client.js';
test('refresh guard stays active until every pending business mutation settles',async()=>{
 const previousFetch=globalThis.fetch,previousDocument=globalThis.document;globalThis.document={cookie:''};let release;globalThis.fetch=()=>new Promise(resolve=>release=()=>resolve(Response.json({data:{}})));
 try{const api=createApiClient('/api/v1'),write=api.request('/sales',{method:'POST',body:{}});assert.equal(api.hasPendingMutation(),true);release();await write;assert.equal(api.hasPendingMutation(),false);}finally{globalThis.fetch=previousFetch;globalThis.document=previousDocument;}
});
test('recent activity and account options share navigation reads but pending purchases remain live',async()=>{
 const originalFetch=globalThis.fetch,originalDocument=globalThis.document;globalThis.document={cookie:'XSRF-TOKEN=current'};const calls=new Map();globalThis.fetch=async url=>{calls.set(url,(calls.get(url)||0)+1);return Response.json({data:[]});};
 try{const api=createApiClient('/api/v1');for(const path of ['/dashboard/activity?limit=20','/account-options','/permission-catalog']){await api.request(path);await api.request(path);assert.equal(calls.get('/api/v1'+path),1);}await api.request('/digital/orders/7');await api.request('/digital/orders/7');assert.equal(calls.get('/api/v1/digital/orders/7'),2);await api.mutate('/accounts/2','PATCH',{});await api.request('/dashboard/activity?limit=20');assert.equal(calls.get('/api/v1/dashboard/activity?limit=20'),2);}finally{globalThis.fetch=originalFetch;globalThis.document=originalDocument;}
});

test('reload displays a scoped snapshot while fresh requests and financial mutations remain authoritative', async () => {
  const originals = {fetch:globalThis.fetch,document:globalThis.document,storage:globalThis.sessionStorage};
  const saved=new Map(); let balance=94000, calls=0;
  globalThis.sessionStorage={getItem:key=>saved.get(key)??null,setItem:(key,value)=>saved.set(key,value),removeItem:key=>saved.delete(key)};
  globalThis.document={cookie:'XSRF-TOKEN=current'};
  globalThis.fetch=async url=>{if(url.includes('/dashboard/'))calls++;return Response.json({data:{cards:[{key:'topup',value:balance}]}});};
  const path='/dashboard/summary?currency=IQD';
  try{
    const first=createApiClient('/api/v1');first.setReadScope('agent14');await first.request(path);
    const reload=createApiClient('/api/v1');assert.equal(reload.peekRequest(path),null);
    reload.setReadScope('agent14');assert.equal(reload.peekRequest(path).data.cards[0].value,94000);
    for(const route of ['/maps/heartbeat','/maps/location','/maps/disconnect','/operations/activity','/account-lookup'])await reload.mutate(route,'POST',{});
    assert.equal(reload.peekRequest(path).data.cards[0].value,94000);
    balance=74000;assert.equal((await reload.request(path,{fresh:true})).data.cards[0].value,74000);assert.equal(calls,2);
    await reload.mutate('/topup/distribution/14','PUT',{});assert.equal(reload.peekRequest(path),null);
    await reload.request(path);reload.setReadScope('agent33');assert.equal(reload.peekRequest(path),null);
    reload.clearReadCache();assert.equal(reload.peekRequest(path),null);
  }finally{globalThis.fetch=originals.fetch;globalThis.document=originals.document;if(originals.storage===undefined)delete globalThis.sessionStorage;else globalThis.sessionStorage=originals.storage;}
});

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

test('shared page options deduplicate concurrent reads and reload after an edit', async () => {
  const oldFetch=globalThis.fetch, oldDocument=globalThis.document;
  globalThis.document={cookie:'XSRF-TOKEN=current'};let calls=0;
  globalThis.fetch=async()=>Response.json({data:{revision:++calls}});
  try{
    const api=createApiClient('/api/v1');api.setReadScope('admin');
    const [a,b]=await Promise.all([api.request('/catalog/options'),api.request('/catalog/options')]);
    assert.deepEqual(a,b);assert.equal(calls,1);
    await api.request('/catalog/options');assert.equal(calls,1);
    await api.mutate('/catalog/products/1','PATCH',{});
    assert.equal(api.peekRequest('/catalog/options'),null);
    await api.request('/catalog/options');assert.equal(calls,3);
    api.setReadScope('pos');assert.equal(api.peekRequest('/catalog/options'),null);
  }finally{globalThis.fetch=oldFetch;globalThis.document=oldDocument;}
});

test('marking visible notices read does not restart in-flight wallet reads, while a money write still invalidates them',async()=>{
 const previousFetch=globalThis.fetch,previousDocument=globalThis.document;globalThis.document={cookie:'XSRF-TOKEN=current'};
 let walletCalls=0,release;
 globalThis.fetch=async(url)=>{
  if(url.includes('/finance/wallets?')){walletCalls++;if(walletCalls===1)return new Promise(resolve=>release=()=>resolve(Response.json({data:[{available:'3500.00'}]})));}
  return Response.json({data:[]});
 };
 try{
  const api=createApiClient('/api/v1'),path='/finance/wallets?service=topup&summary=1';
  const pending=api.request(path);await Promise.resolve();assert.equal(walletCalls,1);
  await api.mutate('/notifications/read-page','POST',{page:'wallets'});release();
  assert.equal((await pending).data[0].available,'3500.00');assert.equal(walletCalls,1,'A sidebar acknowledgement must not restart the page request');
  await api.request(path);assert.equal(walletCalls,1);
  await api.mutate('/finance/transfers','POST',{service:'topup'});
  await api.request(path);assert.equal(walletCalls,2,'Actual financial mutations still require a new server balance');
 }finally{globalThis.fetch=previousFetch;globalThis.document=previousDocument;}
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

test('compact dashboard display survives reload separately from full details and both invalidate after a write',async()=>{
 const originals={fetch:globalThis.fetch,document:globalThis.document,storage:globalThis.sessionStorage};const saved=new Map();
 globalThis.sessionStorage={getItem:key=>saved.get(key)??null,setItem:(key,value)=>saved.set(key,value),removeItem:key=>saved.delete(key)};globalThis.document={cookie:'XSRF-TOKEN=current'};
 globalThis.fetch=async url=>Response.json({data:{cards:[{key:'topup',balanceRows:url.includes('view=cards')?[]:[{name:'detail'}]}]}});
 const full='/dashboard/summary?currency=IQD',compact=full+'&view=cards';
 try{const api=createApiClient('/api/v1');api.setReadScope('agent');await api.request(full);await api.request(compact);
 const reload=createApiClient('/api/v1');reload.setReadScope('agent');assert.equal(reload.peekRequest(full).data.cards[0].balanceRows.length,1);assert.equal(reload.peekRequest(compact).data.cards[0].balanceRows.length,0);
 await reload.mutate('/finance/transfers','POST',{});assert.equal(reload.peekRequest(full),null);assert.equal(reload.peekRequest(compact),null);
 }finally{globalThis.fetch=originals.fetch;globalThis.document=originals.document;if(originals.storage===undefined)delete globalThis.sessionStorage;else globalThis.sessionStorage=originals.storage;}
});

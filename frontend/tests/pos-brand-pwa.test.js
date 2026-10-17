import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createHash} from 'node:crypto';
import {runInNewContext} from 'node:vm';
import {posBrandStyle} from '../src/layouts/pos-brand.js';
import {readFavorites,saveFavorites} from '../src/modules/sales/pos-favorites.js';

test('company favorites are isolated by account and corrupted or blocked storage does not break selling',()=>{
 const values=new Map(),storage={getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value)};
 saveFavorites(storage,12,['1','topup']);assert.deepEqual(readFavorites(storage,12),['1','topup']);assert.deepEqual(readFavorites(storage,13),[]);
 values.set('dananeer:favorite-companies:12','bad JSON');assert.deepEqual(readFavorites(storage,12),[]);
 const blocked={getItem(){throw Error('blocked');},setItem(){throw Error('blocked');}};assert.deepEqual(readFavorites(blocked,12),[]);assert.doesNotThrow(()=>saveFavorites(blocked,12,['1']));
});

test('agent colors have readable button text and a dark header, including white and black identities',()=>{
 assert.equal(posBrandStyle('#ffffff')['--pos-brand-ink'],'#111617');
 assert.equal(posBrandStyle('#000000')['--pos-brand-ink'],'#FFFFFF');
 assert.equal(posBrandStyle('url(unsafe)')['--pos-brand'],'#0D5C63');
 assert.equal(posBrandStyle('#ffffff')['--pos-header'],'#525252');
 assert.equal(posBrandStyle('#ffffff')['--pos-link-light'],'#525252');
 assert.equal(posBrandStyle('#000000')['--pos-link-dark'],'#b4b4b4');
});

test('POS service worker excludes every financial API, private image and mutation from caching',async()=>{
 const source=await readFile(new URL('../portals/pos/public/sw.js',import.meta.url),'utf8');
 const handlers={},cached=[],removed=[];
 const cache={addAll:async paths=>cached.push(...paths),put:async(path)=>cached.push(path)};
 runInNewContext(source,{URL,self:{location:{origin:'https://pos.example.test'},addEventListener:(name,fn)=>handlers[name]=fn},caches:{open:async()=>cache,match:async()=>null,keys:async()=>['dananeer-shell-old','other-app'],delete:async key=>removed.push(key)},fetch:async()=>({ok:true,headers:{get:()=> 'text/html'},clone(){return this;}})});
 for(const [path,method] of [['/api/v1/digital/orders','POST'],['/api/v1/digital/orders','GET'],['/api/v1/auth/brand-image','GET'],['/sanctum/csrf-cookie','GET'],['/api/v1/sales/10/receipt','GET'],['/assets/private.js','POST']]){
  handlers.fetch({request:{url:'https://pos.example.test'+path,method,mode:'cors'},respondWith:()=>assert.fail(`Must not cache ${method} ${path}`)});
 }
 let install;handlers.install({waitUntil:promise=>install=promise});await install;
 assert.ok(cached.every(path=>!path.startsWith('/api/')));
 for(const path of ['/icons/dananeer-192-v3.png','/icons/dananeer-512-v3.png','/icons/dananeer-maskable-512-v3.png'])assert.ok(cached.includes(path),`Missing approved installation icon ${path}`);
 let activate;handlers.activate({waitUntil:promise=>activate=promise});await activate;
 assert.deepEqual(removed,['dananeer-shell-old']);
});

test('Dananeer installation uses local usable icons, an Arabic name and POS launch route',async()=>{
 const root=new URL('../portals/pos/public/',import.meta.url);
 const manifest=JSON.parse(await readFile(new URL('manifest.webmanifest',root),'utf8'));
 assert.equal(manifest.name,'دنانير');assert.equal(manifest.display,'standalone');assert.equal(manifest.start_url,'/dashboard');
 assert.deepEqual(manifest.icons.map(({src,purpose})=>({src,purpose})),[{src:'/icons/dananeer-192-v3.png',purpose:'any'},{src:'/icons/dananeer-512-v3.png',purpose:'any'},{src:'/icons/dananeer-maskable-512-v3.png',purpose:'maskable'}]);
 for(const icon of manifest.icons){const data=await readFile(new URL(icon.src.slice(1),root));assert.equal(data.subarray(1,4).toString(),'PNG');const size=Number(icon.sizes.split('x')[0]);assert.equal(data.readUInt32BE(16),size);assert.equal(data.readUInt32BE(20),size);}
});

test('approved recharge artwork is identical in web and Android, and visible app icons use its new cache key',async()=>{
 const root=new URL('../portals/pos/',import.meta.url);
 for(const [path,expected] of [['public/icons/dananeer-192-v3.png','ac1ce77f1d2e2ba50da332fd5f3f5665671f7a62e39736e038ac865c146bbfb9'],['public/icons/dananeer-512-v3.png','f2593729b9f520bbbf001819cc99b97d4cdbc61fffdb1cb1a831b1ced1240cbc']])assert.equal(createHash('sha256').update(await readFile(new URL(path,root))).digest('hex'),expected);
 const androidIcon=await readFile(new URL('../../android-pos/app/src/main/res/drawable-nodpi/dananeer_icon_v3.png',import.meta.url));
 assert.equal(createHash('sha256').update(androidIcon).digest('hex'),'f2593729b9f520bbbf001819cc99b97d4cdbc61fffdb1cb1a831b1ced1240cbc');
 for(const path of ['../portals/pos/index.html','../src/layouts/PosAbout.vue','../src/modules/auth/LoginPage.vue']){
  const source=await readFile(new URL(path,import.meta.url),'utf8');
  assert.ok(source.includes('/icons/dananeer-192-v3.png'),`${path} must show the approved icon`);
  assert.ok(!source.includes('/icons/dananeer-192-v2.png'));
 }
});

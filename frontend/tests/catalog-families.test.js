import test from 'node:test';
import assert from 'node:assert/strict';
import {catalogFamilies,visibleCatalogFamilies} from '../src/modules/sales/catalog-families.js';

const stock=(id,face_value,extra={})=>({id,key:`stock:${id}`,provider_id:7,provider_name:'شركة حقيقية',name:`المنتج ${id}`,kind:'card',catalog_kind:'prepaid',currency:'IQD',face_value,price:'10500.00',...extra});

test('families use explicit provider, original kind, currency and unique face values while preserving exact rows',()=>{
 const a=Object.freeze(stock(9,'15000.00')),b=Object.freeze(stock(3,'5000')),rows=Object.freeze([a,b]);
 const [family]=catalogFamilies(rows);
 assert.equal(family.grouped,true);assert.equal(family.name,'شركة حقيقية');
 assert.deepEqual(family.products,[b,a]);assert.equal(family.products[0],b);assert.equal(family.products[1],a);
 assert.equal(rows[0],a,'source order and objects are never mutated');
});

test('identical names never combine providers, stock kinds, currencies, or digital connection/offer identities',()=>{
 const rows=[stock(1,'5000'),stock(2,'10000',{provider_id:8}),stock(3,'15000',{catalog_kind:'internet'}),stock(4,'20000',{currency:'USD'}),
  stock(5,'25000',{kind:'topup',key:'digital:4:5',digital_offer:{connection_id:4,id:5}}),stock(5,'50000',{kind:'topup',key:'digital:8:5',digital_offer:{connection_id:8,id:5}})].map(row=>({...row,name:'نفس الاسم'}));
 const families=catalogFamilies(rows);
 assert.equal(families.length,6);assert.ok(families.every(row=>!row.grouped));
 assert.equal(new Set(families.map(row=>row.key)).size,6);
});

test('missing, invalid and duplicate denominations keep every exact named choice visible',()=>{
 for(const invalid of [undefined,null,'','0','-2','5,000','5000.001','10000.00']){
  const rows=[stock(1,'10000'),stock(2,invalid)];const families=catalogFamilies(rows);
  assert.equal(families.length,2,String(invalid));assert.deepEqual(families.flatMap(row=>row.products),rows);
 }
 const unknown=[stock(1,'5000',{catalog_kind:undefined}),stock(2,'10000',{catalog_kind:undefined})];
 assert.equal(catalogFamilies(unknown).length,2,'old snapshots without original kind stay explicit');
});

test('favorites/search filter exact family members and recompute the displayed price without exposing a different denomination',()=>{
 const a=stock(1,'5000',{price:'5250.00'}),b=stock(2,'10000',{price:'10500.00'}),families=catalogFamilies([a,b]);
 const filtered=visibleCatalogFamilies(families,[b]);
 assert.equal(filtered.length,1);assert.equal(filtered[0].key,families[0].key);assert.deepEqual(filtered[0].products,[b]);
 assert.equal(filtered[0].price,'10500.00');assert.equal(filtered[0].grouped,true);assert.equal(families[0].price,'5250.00');
 assert.deepEqual(families[0].products,[a,b]);assert.deepEqual(visibleCatalogFamilies(families,[]),[]);
});

test('price summary is based on exact decimal catalog prices, without floating point or title parsing',()=>{
 const rows=[stock(1,'5000',{name:'50000',price:'9007199254740993.02'}),stock(2,'10000',{name:'not a denomination',price:'9007199254740993.01'})];
 assert.equal(catalogFamilies(rows)[0].price,'9007199254740993.01');
 assert.equal(catalogFamilies([stock(1,'5000',{price:null}),stock(2,'10000',{price:null})])[0].price,null);
});

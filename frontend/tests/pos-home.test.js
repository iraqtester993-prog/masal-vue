import test from 'node:test';
import assert from 'node:assert/strict';
import {dashboardCompanies} from '../src/modules/dashboard/pos-home.js';
test('home company circles retain assigned providers even with exhausted stock or unavailable prices',()=>{
 const companies=dashboardCompanies([
  {provider_id:2,provider_name:'Asiacell',provider_image_url:'/actual-logo',price:'5000',stock_available:10},
  {provider_id:2,provider_name:'Asiacell',price:'10000',stock_available:10},
  {provider_id:3,provider_name:'Hidden',price:null,stock_available:10},
  {provider_id:4,provider_name:'Empty',price:'5000',stock_available:0},
 ],[
  {provider:'topup',product_provider_id:2,retail:'5000'},
  {provider:'rabiaa',provider_id:8,company_name:'Company eight',retail:'10000'},
  {provider:'rabiaa',provider_id:9,company_name:'Company nine',retail:'20000'},
 ]);
 assert.deepEqual(companies.map(row=>row.key),['stock:2','stock:3','stock:4','digital:8','digital:9']);
 assert.equal(companies[0].image,'/actual-logo');assert.equal(companies[3].name,'Company eight');
 assert.deepEqual(dashboardCompanies(null,null),[]);
});

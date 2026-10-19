import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
import {runInNewContext} from 'node:vm';
import {parse} from '@vue/compiler-sfc';
import {computed,createSSRApp,h,reactive,ref,shallowRef} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';
import {nextChoice,selectChoice,selectChoices} from '../src/shared/components/select-options.js';

test('choice menus preserve native typed values, disabled groups and multiple-selection semantics',()=>{
 const options=[{_value:7,value:'7',label:'سبعة',selected:false},{value:'all',label:'الكل',selected:true},{value:'blocked',disabled:true},{value:'grouped',parentElement:{disabled:true}},{value:'hidden',hidden:true}],select={options,matches:()=>false};
 assert.equal(selectChoices(select)[0].value,7);assert.equal(selectChoices(select)[1].value,'all');
 assert.equal(selectChoice(select,2),false);assert.equal(selectChoice(select,3),false);assert.equal(selectChoice(select,4),false);
 assert.equal(selectChoice(select,0),true);assert.equal(options[0].selected,true);assert.equal(options[1].selected,false);
 select.multiple=true;assert.equal(selectChoice(select,1),true);assert.equal(options[0].selected,true);assert.equal(options[1].selected,true);
 select.disabled=true;assert.equal(selectChoice(select,0),false);assert.equal(options[0].selected,true);
});

test('keyboard movement skips unavailable options and wraps within the branded menu',()=>{
 const options=[{index:0},{index:1,disabled:true},{index:2,hidden:true},{index:3}];
 assert.equal(nextChoice(options,0,'ArrowDown'),3);assert.equal(nextChoice(options,3,'ArrowDown'),0);
 assert.equal(nextChoice(options,0,'ArrowUp'),3);assert.equal(nextChoice(options,3,'Home'),0);assert.equal(nextChoice(options,0,'End'),3);
 assert.equal(nextChoice([{index:0,disabled:true}],0,'ArrowDown'),null);
});

test('the actual select keeps a native control for administration and provides an accessible POS trigger with form validation',async()=>{
 const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
 try{
  const Select=(await server.ssrLoadModule('/src/shared/components/AppSelect.vue')).default,{portalContextKey}=await server.ssrLoadModule('/src/modules/auth/session.js');
  for(const type of ['pos','system']){
   const app=createSSRApp({render:()=>h(Select,{modelValue:7,required:true,name:'account',id:'account-field','aria-label':'الحساب'},()=>[h('option',{value:7},'الحساب المحدد'),h('option',{value:8,disabled:true},'غير متاح')])});
   app.provide(portalContextKey,{session:{state:{identity:{account:{type}}}}});
   const html=await renderToString(app);assert.ok(html.includes('name="account"'));assert.ok(html.includes('required'));assert.ok(html.includes('value="7"'));
   if(type==='pos'){assert.ok(html.includes('aria-haspopup="dialog"'));assert.ok(html.includes('class="app-select-native"'));assert.ok(html.includes('tabindex="-1"'));assert.equal((html.match(/id="account-field"/g)||[]).length,1);}
   else{assert.ok(html.startsWith('<select'));assert.ok(!html.includes('app-select-trigger'));}
  }
 }finally{await server.close();}
});

test('invalid required POS selection cancels browser focus and opens its accessible menu; disabled controls do not open',async()=>{
 const source=await readFile(new URL('../src/shared/components/AppSelect.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const props={modelValue:'',required:true,disabled:false},scope={computed,ref,shallowRef,getCurrentInstance:()=>null,portalContextKey:Symbol('portal'),nextTick:async()=>{},inject:()=>({session:{state:{identity:{account:{type:'pos'}}}}}),useAttrs:()=>({'aria-label':'الحساب'}),usePreferencesTranslator:()=>value=>value,defineOptions(){},defineProps:()=>props,defineEmits:()=>()=>{},onMounted(){},onUpdated(){},onBeforeUnmount(){},nextChoice,selectChoice,selectChoices};
 runInNewContext(script+'\nglobalThis.controls={native,open,invalid,show,validation,selectedText,sync};',scope);const page=scope.controls;
 page.native.value={options:[{value:'available',label:'الخيار المتاح',selected:false}],matches:()=>false,closest:()=>null};page.sync();assert.equal(page.selectedText.value,'اختر','an unmatched value may not display the first option as a selection');let canceled=0;
 page.invalid({preventDefault(){canceled++;}});await Promise.resolve();assert.equal(canceled,1);assert.equal(page.open.value,true);assert.ok(page.validation.value);
 page.open.value=false;page.native.value.form={querySelector:()=>({id:'earlier-invalid-field'})};page.invalid({preventDefault(){canceled++;}});assert.equal(page.open.value,false,'another invalid field keeps control of focus');
 props.disabled=true;await page.show();assert.equal(page.open.value,false);
});

test('value-controlled selectors remain reactive and numeric multiple models keep an array',async()=>{
 const source=await readFile(new URL('../src/shared/components/AppSelect.vue',import.meta.url),'utf8'),script=parse(source).descriptor.scriptSetup.content.replace(/^import .*;\r?$/gm,'');
 const props=reactive({value:'ar',modelValue:undefined,modelModifiers:{number:true}}),received=[];
 const scope={computed,ref,shallowRef,getCurrentInstance:()=>({vnode:{props:{'onUpdate:modelValue':value=>received.push(value)}}}),portalContextKey:Symbol('portal'),nextTick:async()=>{},inject:()=>null,useAttrs:()=>({}),usePreferencesTranslator:()=>value=>value,defineOptions(){},defineProps:()=>props,defineEmits:()=>()=>{throw Error('Numeric arrays must not pass through Vue whole-value numeric conversion');},onMounted(){},onUpdated(){},onBeforeUnmount(){},nextChoice,selectChoice,selectChoices};
 runInNewContext(script+'\nglobalThis.controls={current};',scope);
 assert.equal(scope.controls.current.value,'ar');props.value='en';assert.equal(scope.controls.current.value,'en');
 scope.controls.current.value=['7','8'];assert.deepEqual(Array.from(received[0]),[7,8]);
});

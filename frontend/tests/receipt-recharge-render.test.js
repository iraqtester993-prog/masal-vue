import test from 'node:test';
import assert from 'node:assert/strict';
import {fileURLToPath} from 'node:url';
import {createServer} from 'vite';
import {createSSRApp,h,reactive,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';

const cards=[
  {id:1,serial:'SERIAL-ONE',expiry:'2027-01-01',fields:{pin:'0012345678901234'}},
  {id:2,serial:'SERIAL-TWO',expiry:'2027-02-01',fields:{pin:'0098765432109876'}},
];
function fixture({qr=true,template='*123*{pin}#',vouchers=cards,order=['codes']}={}){
  return {cards:vouchers,design:{width:58,color:'#172b4d',qr_enabled:qr,recharge_template:template,display_order:order}};
}
const qrImages=html=>[...html.matchAll(/<img\b(?=[^>]*\bclass="receipt-recharge-qr")[^>]*\bsrc="([^"]+)"[^>]*>/g)].map(match=>match[1]);
const dialLinks=html=>[...html.matchAll(/href="(tel:[^"]+)"/g)].map(match=>match[1]);

test('actual ReceiptCard renders QR visibility, per-card recharge and safe designer preview',async t=>{
  const server=await createServer({root:fileURLToPath(new URL('../',import.meta.url)),mode:'pos',logLevel:'error',server:{middlewareMode:true,hmr:false,ws:false},appType:'custom'});
  try{
    const ReceiptCard=(await server.ssrLoadModule('/src/modules/sales/ReceiptCard.vue')).default;
    const ReceiptDialog=(await server.ssrLoadModule('/src/modules/sales/ReceiptDialog.vue')).default;
    const render=(receipt,preview=false)=>renderToString(createSSRApp({render:()=>h(ReceiptCard,{receipt,preview})}));

    await t.test('hiding QR keeps each complete highlighted PIN and the original receipt fields',async()=>{
      const html=await render(fixture({qr:false}));
      assert.equal(qrImages(html).length,0);assert.deepEqual(dialLinks(html),[]);
      assert.equal((html.match(/class="pin receipt-pin"[^>]*data-native-pin/g)||[]).length,2);
      for(const card of cards){assert.ok(html.includes(`>${card.fields.pin}</div>`));assert.ok(html.includes(card.serial));assert.ok(html.includes(card.expiry));}
    });

    await t.test('two actual cards receive distinct QR images and their own correctly encoded dial links',async()=>{
      const html=await render(fixture());
      const images=qrImages(html);assert.equal(images.length,2);assert.notEqual(images[0],images[1]);
      assert.ok(images.every(src=>src.startsWith('data:image/svg+xml;')));
      assert.deepEqual(dialLinks(html),cards.map(card=>`tel:*123*${card.fields.pin}%23`));
      assert.equal((html.match(/data-native-width-pixels="222"/g)||[]).length,2);
      for(const card of cards)assert.ok(html.includes(`>*123*${card.fields.pin}#</a>`));
      assert.equal(html.includes('PREVIEW ONLY'),false);
    });

    await t.test('grouped voucher PIN remains unchanged on paper while its QR and dial URI use every original digit',async()=>{
      const grouped=[{...cards[0],fields:{pin:'0012-0034-0056-0078'}},{...cards[1],fields:{pin:'0098 0076 0054 0032'}}];
      const html=await render(fixture({vouchers:grouped}));
      assert.equal(qrImages(html).length,2);
      assert.deepEqual(dialLinks(html),['tel:*123*0012003400560078%23','tel:*123*0098007600540032%23']);
      for(const card of grouped){assert.ok(html.includes(`>${card.fields.pin}</div>`));assert.ok(html.includes(card.serial));}
      assert.equal(grouped[0].fields.pin,'0012-0034-0056-0078');
      assert.equal(grouped[1].fields.pin,'0098 0076 0054 0032');
      assert.equal((html.match(/class="pin receipt-pin"[^>]*data-native-pin/g)||[]).length,2);
    });

    await t.test('alphanumeric test vouchers display a plain PIN QR with no call instructions or dial links',async()=>{
      const vouchers=[{...cards[0],fields:{pin:'a1B2-c3D4-0056'}},{...cards[1],fields:{pin:'00Ab 01cD'}}];
      const html=await render(fixture({vouchers}));
      assert.equal(qrImages(html).length,2);assert.deepEqual(dialLinks(html),[]);
      assert.equal((html.match(/امسح الرمز لعرض البن/g)||[]).length,2);
      assert.equal(html.includes('اضغط اتصال'),false);assert.equal(html.includes('receipt-recharge-code'),false);
      for(const card of vouchers){assert.ok(html.includes(`>${card.fields.pin}</div>`));assert.ok(html.includes(card.serial));}
      assert.notEqual(qrImages(html)[0],qrImages(html)[1]);
      for(const receipt of [fixture({qr:false,vouchers}),fixture({template:'',vouchers})]) {
        const hidden=await render(receipt);assert.equal(qrImages(hidden).length,0);assert.deepEqual(dialLinks(hidden),[]);
        for(const card of vouchers)assert.ok(hidden.includes(`>${card.fields.pin}</div>`));
      }
      const mixed=await render(fixture({vouchers:[cards[0],vouchers[0]]}));
      assert.equal(qrImages(mixed).length,2);assert.deepEqual(dialLinks(mixed),[`tel:*123*${cards[0].fields.pin}%23`]);
      assert.ok(mixed.includes('امسح الرمز لعرض البن'));assert.ok(mixed.includes('اضغط اتصال'));
    });

    await t.test('invalid template or PIN hides only its recharge QR and preserves the printable PIN and serial',async()=>{
      for(const receipt of [fixture({template:'https://example.test/{pin}'}),fixture({vouchers:[{...cards[0],fields:{pin:'12#34'}}]})]){
        const html=await render(receipt);
        assert.equal(qrImages(html).length,0);assert.deepEqual(dialLinks(html),[]);
        for(const card of receipt.cards){assert.ok(html.includes(`>${card.fields.pin}</div>`));assert.ok(html.includes(card.serial));}
        assert.ok(html.includes('data-native-pin'));
      }
      const html=await render(fixture({vouchers:[cards[0],{...cards[1],fields:{pin:'INVALID#'}}]}));
      assert.equal(qrImages(html).length,1);assert.deepEqual(dialLinks(html),[`tel:*123*${cards[0].fields.pin}%23`]);
      assert.ok(html.includes('>INVALID#</div>'));assert.ok(html.includes(cards[1].serial));
    });

    await t.test('the app explains an enabled QR with an unusable PIN without adding the warning to the printed receipt',async()=>{
      for(const [pin,enabled,warning] of [['0012-3456-7890-1234',true,false],['a1b2c3d4-0000-1111-2222-1234567890ab',true,false],['12#34',true,true],['a1b2c3d4-0000-1111-2222-1234567890ab',false,false]]){
        const receipt={...fixture({qr:enabled,vouchers:[{...cards[0],fields:{pin}}]}),sale:{id:9,account_id:12,status:'Printed',issued_at:'2026-10-16T10:00:00Z',print_pending:false,quantity:1,retail_total:'5000.00'},print_policy:{},daily_print:{}};
        const vm={state:reactive({busy:false,error:''}),receipt:ref(receipt),identity:ref({account:{id:12,type:'pos'}}),pos:ref(true),can:()=>true,key:()=>({for(){throw Error('render must not write');},clear(){}}),clearReceipt(){}};
        const context={};await renderToString(createSSRApp({render:()=>h(ReceiptDialog,{vm})}),context);
        const html=context.teleports.body;
        assert.equal(html.includes('receipt-qr-unavailable'),warning);
        assert.equal(html.includes('تعذر إنشاء QR لبعض رموز البطاقات'),warning);
        const receiptStart=html.indexOf('class="receipt"');assert.ok(receiptStart>0);
        assert.ok(!html.slice(receiptStart).includes('تعذر إنشاء QR لبعض رموز البطاقات'),'native printer captures only .receipt; status stays outside');
        assert.ok(html.includes(pin),'the original PIN remains unchanged');
      }
    });

    await t.test('designer preview has a clearly harmless example QR and no real dial URI',async()=>{
      const html=await render(fixture({vouchers:[]}),true);
      assert.equal(qrImages(html).length,1);assert.deepEqual(dialLinks(html),[]);
      assert.ok(html.includes('نموذج QR — غير صالح للتعبئة'));assert.ok(html.includes('معاينة QR تجريبي · ليس للتعبئة'));
      assert.ok(html.includes('•••• •••• ••••'));assert.ok(!html.includes('tel:'));
      for(const card of cards)assert.ok(!html.includes(card.fields.pin));
    });

    await t.test('omitting the authorized codes block emits neither PIN nor recharge QR',async()=>{
      const html=await render(fixture({order:['header']}));
      assert.equal(qrImages(html).length,0);assert.deepEqual(dialLinks(html),[]);
      for(const card of cards)assert.ok(!html.includes(card.fields.pin));
    });
  }finally{await server.close();}
});

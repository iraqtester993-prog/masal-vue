import test from 'node:test';
import assert from 'node:assert/strict';
import {posBrandStyle} from '../src/layouts/pos-brand.js';

const luminance=color=>[1,3,5].map(index=>parseInt(color.slice(index,index+2),16)/255).map(c=>c<=.04045?c/12.92:((c+.055)/1.055)**2.4).reduce((sum,c,i)=>sum+c*[.2126,.7152,.0722][i],0);
const contrast=(a,b)=>{const values=[luminance(a),luminance(b)].sort((x,y)=>y-x);return (values[0]+.05)/(values[1]+.05);};
test('chosen agent colours produce readable action and hero surfaces, including extreme colours',()=>{
 for(const color of ['#000000','#ffffff','#777777','#ffff00','#00ff00','#0000ff','#ff0000','#0D5C63','#8040cc','#ff8800','#eeccee']){
  const palette=posBrandStyle(color);
  assert.equal(palette['--pos-brand'],color);
  assert.ok(contrast(color,palette['--pos-brand-ink'])>=4.5,color+' action');
  for(const surface of ['--pos-hero','--pos-hero-card'])assert.ok(contrast(palette[surface],'#ffffff')>=4.5,color+' '+surface);
  assert.ok(contrast(palette['--pos-hero-card'],palette['--pos-hero-muted'])>=4.5,color+' supporting text');
  assert.ok(contrast('#ffffff',palette['--pos-link-light'])>=4.5,color+' light links');
  assert.ok(contrast('#142329',palette['--pos-link-dark'])>=4.5,color+' dark links');
 }
});
test('invalid identity colours fall back safely and different agents have different hero surfaces',()=>{
 for(const invalid of [null,undefined,'red','url(x)','#12345',';background:red','#ffffffff'])assert.deepEqual(posBrandStyle(invalid),posBrandStyle('#0D5C63'));
 assert.notEqual(posBrandStyle('#0055aa')['--pos-hero-card'],posBrandStyle('#aa2255')['--pos-hero-card']);
});

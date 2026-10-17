import test from 'node:test';
import assert from 'node:assert/strict';
import {posSystemAppearance} from '../src/layouts/pos-system-appearance.js';
import {posBrandStyle} from '../src/layouts/pos-brand.js';

test('changing page or agent retains the approved status colour without colouring the bottom navigation',()=>{
  for(const dark of [false,true])for(const brand of ['#667119','#0D5C63','#8040cc','#ffffff','#000000']){
    const hero=posBrandStyle(brand)['--pos-hero'];
    const home=posSystemAppearance({home:true,dark,hero});
    const page=posSystemAppearance({dark,hero});
    assert.equal(home.statusBarColor,hero);
    assert.equal(home.navigationBarColor,page.navigationBarColor);
    assert.equal(home.navigationBarColor,dark?'#142329':'#FFFFFF');
    assert.equal(page.statusBarColor,page.backgroundColor);
    assert.equal(home.backgroundColor,page.backgroundColor);
    // The deployed 1.4.2 contract still receives exactly its old per-page colour.
    assert.equal(home.color,hero);assert.equal(page.color,dark?'#0D171B':'#F5F7F6');
    assert.ok(!('blocks' in home)&&!('jobId' in home));
  }
});

test('invalid hero colour cannot enter the bridge payload or change neutral system surfaces',()=>{
  for(const hero of [null,'red','#123','url(x)','#ffffff00']){
    const payload=posSystemAppearance({home:true,hero});
    assert.equal(payload.statusBarColor,'#F5F7F6');assert.equal(payload.navigationBarColor,'#FFFFFF');
    assert.equal(payload.theme,'light');assert.equal(payload.action,'appearance');
  }
});

test('a full-screen dialog or drawer paints the top bar to match its own header, then restores the page colours',()=>{
  for(const dark of [false,true]){
    const hero=posBrandStyle('#667119')['--pos-hero'];
    const normal=posSystemAppearance({home:true,dark,hero});
    const opened=posSystemAppearance({home:true,dark,hero,overlay:true});
    assert.equal(opened.statusBarColor,opened.navigationBarColor);
    assert.notEqual(opened.statusBarColor,normal.statusBarColor);
    assert.equal(opened.color,opened.statusBarColor);
    assert.equal(opened.backgroundColor,normal.backgroundColor);
    assert.equal(posSystemAppearance({home:true,dark,hero}).statusBarColor,hero);
  }
});

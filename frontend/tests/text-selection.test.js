import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {installTextSelectionGuard} from '../src/shared/text-selection.js';

function documentFixture() {
  const doc = new EventTarget();
  const selection = {isCollapsed:true, cleared:0, removeAllRanges(){this.cleared++;this.isCollapsed=true;}};
  doc.getSelection = () => selection;
  return {doc,selection};
}

test('POS blocks native text selection and clipboard defaults for content added after startup', () => {
  const {doc}=documentFixture(),dispose=installTextSelectionGuard(doc);
  for(const type of ['contextmenu','selectstart','copy','cut']) {
    const event=new Event(type,{cancelable:true});
    assert.equal(doc.dispatchEvent(event),false,type);
    assert.equal(event.defaultPrevented,true,type);
  }
  dispose();
});

test('the guard does not cancel typing, paste, taps, pointer scrolling or touch scrolling', () => {
  const {doc}=documentFixture(),dispose=installTextSelectionGuard(doc);
  for(const type of ['beforeinput','input','keydown','paste','click','pointerdown','pointermove','touchstart','touchmove','wheel']) {
    const event=new Event(type,{cancelable:true});
    assert.equal(doc.dispatchEvent(event),true,type);
    assert.equal(event.defaultPrevented,false,type);
  }
  dispose();
});

test('existing and later display selections are cleared without changing a collapsed caret', () => {
  const {doc,selection}=documentFixture();selection.isCollapsed=false;
  const dispose=installTextSelectionGuard(doc);assert.equal(selection.cleared,1);
  doc.dispatchEvent(new Event('selectionchange'));assert.equal(selection.cleared,1);
  selection.isCollapsed=false;doc.dispatchEvent(new Event('selectionchange'));assert.equal(selection.cleared,2);
  dispose();selection.isCollapsed=false;doc.dispatchEvent(new Event('selectionchange'));assert.equal(selection.cleared,2);
});

test('unmount releases listeners and missing document/selection APIs are harmless', () => {
  const {doc}=documentFixture(),dispose=installTextSelectionGuard(doc);dispose();dispose();
  for(const type of ['contextmenu','selectstart','copy','cut']) assert.equal(doc.dispatchEvent(new Event(type,{cancelable:true})),true);
  assert.doesNotThrow(()=>installTextSelectionGuard(null)());
  assert.doesNotThrow(()=>installTextSelectionGuard(new EventTarget())());
});

test('the guard and selection CSS apply only to the POS entry, including portalled dialogs', async () => {
  const entry=await readFile(new URL('../src/app/create-portal.js',import.meta.url),'utf8');
  const css=await readFile(new URL('../src/shared/styles/base.css',import.meta.url),'utf8');
  assert.match(entry,/id === 'pos' \? installTextSelectionGuard\(document\) : \(\) => \{\}/);
  assert.match(entry,/app\.onUnmount\(\(\) => \{releaseTextSelectionGuard\(\)/);
  assert.match(css,/html\[data-portal="pos"\],html\[data-portal="pos"\] \*\{\s*-webkit-user-select:none!important;\s*user-select:none!important;\s*-webkit-touch-callout:none!important;/);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {createInquiryNotifications} from '../src/modules/company/inquiry-notifications.js';
const token = id => `${id}.${'a'.repeat(64)}`;
function storage() {const values = new Map(); return {getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value),values};}
const detail = (version,messages) => ({version,messages});
test('only new administrator replies notify, acknowledgment survives page refresh', () => {
  const saved=storage(), inbox=createInquiryNotifications(saved);
  inbox.remember(token(1));
  inbox.accept(token(1),detail(1,[{id:1,sender:'visitor',body:'Private question'}]));
  assert.equal(inbox.state.records[0].unread,0);
  inbox.accept(token(1),detail(2,[{id:1,sender:'visitor'},{id:2,sender:'staff',body:'Private reply'}]));
  assert.equal(inbox.state.records[0].unread,1);
  inbox.accept(token(1),detail(2,[{id:2,sender:'staff'}]));
  assert.equal(inbox.state.records[0].unread,1);
  const restored=createInquiryNotifications(saved); restored.restore();
  assert.equal(restored.state.records[0].unread,1);
  restored.markRead(token(1));
  const again=createInquiryNotifications(saved); again.restore();
  again.accept(token(1),detail(3,[{id:2,sender:'staff'},{id:3,sender:'visitor'}]));
  assert.equal(again.state.records[0].unread,0);
  again.accept(token(1),detail(4,[{id:2,sender:'staff'},{id:4,sender:'staff'}]));
  assert.equal(again.state.records[0].unread,1);
  assert.ok(![...saved.values.values()].join('').includes('Private'));
});
test('reading a conversation clears old replies and older network responses cannot erase a new notification', () => {
  const inbox=createInquiryNotifications(storage());
  inbox.accept(token(1),detail(2,[{id:2,sender:'staff'}]),true);
  assert.equal(inbox.state.records[0].unread,0);
  inbox.accept(token(1),detail(3,[{id:2,sender:'staff'},{id:3,sender:'staff'}]));
  inbox.accept(token(1),detail(2,[{id:2,sender:'staff'}]));
  assert.equal(inbox.state.records[0].unread,1);
  assert.equal(inbox.state.detail.version,3);
  inbox.forget(token(1)); assert.equal(inbox.state.records.length,0);
});
test('tokens are bounded, invalid tokens rejected, old records expire, unavailable storage is tolerated', () => {
  const saved=storage(); let time=10000000000;
  const inbox=createInquiryNotifications(saved,()=>time);
  inbox.remember('invalid'); assert.equal(inbox.state.records.length,0);
  for(let id=1;id<=7;id++)inbox.remember(token(id));
  assert.equal(inbox.state.records.length,5); assert.equal(inbox.state.records[0].token,token(3));
  time+=91*24*60*60*1000;
  const expired=createInquiryNotifications(saved,()=>time); expired.restore();
  assert.equal(expired.state.records.length,0);
  const denied=createInquiryNotifications({getItem(){throw Error('Blocked');},setItem(){throw Error('Blocked');}});
  denied.restore(); denied.remember(token(1)); assert.equal(denied.state.records.length,1);
});

import test from 'node:test';
import assert from 'node:assert/strict';
import {activityDate} from '../src/modules/dashboard/activity-model.js';

test('activity timestamps follow selected UI language and Baghdad time across UTC and explicit offsets', () => {
  const utc = '2026-10-13T22:31:00Z';
  const local = '2026-10-14T01:31:00+03:00';
  for (const [language, locale] of [['ar','ar-IQ'],['en','en-GB'],['ckb','ckb-IQ']]) {
    const expected = new Date(utc).toLocaleString(locale, {numberingSystem:'latn',timeZone:'Asia/Baghdad',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit'});
    assert.equal(activityDate(utc, language), expected);
    assert.equal(activityDate(local, language), expected);
  }
  assert.notEqual(activityDate(utc, 'ar'), activityDate(utc, 'en'));
  assert.equal(activityDate('2026-10-13 22:31:00','ar'), activityDate(utc,'ar'));
  assert.equal(activityDate(null), '—');
  assert.equal(activityDate('invalid'), '—');
});

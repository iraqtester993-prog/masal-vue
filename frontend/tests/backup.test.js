import test from 'node:test';
import assert from 'node:assert/strict';
import {File} from 'node:buffer';
import {fileForPreview,restorePayload,manifestCounts,UPLOAD_BYTES} from '../src/modules/backups/backup-model.js';
import {createBackupApi} from '../src/modules/backups/backup-api.js';
test('backup upload and restore require actual signed-review metadata and bounded file size',()=>{
  const file=new File(['signed snapshot'],'backup.json');assert.equal(fileForPreview(file).get('file').name,'backup.json');
  assert.throws(()=>fileForPreview(new File(['x'],'old.txt')),/JSON/);
  assert.throws(()=>fileForPreview({name:'backup.json',size:UPLOAD_BYTES+1}),/15/);
  assert.throws(()=>restorePayload({id:'real',version:1,reference_verified:false},'password'),/لم يكتمل/);
  assert.throws(()=>restorePayload({id:'real',version:1,reference_verified:true},''),/كلمة/);
  assert.deepEqual(restorePayload({id:'real',version:3,reference_verified:true},'actual-password'),{preview_id:'real',version:3,current_password:'actual-password'});
  assert.deepEqual(manifestCounts(null),{accounts:null,cards:null,files:null,rows:null});
  assert.deepEqual(manifestCounts({counts:{accounts:7,stock_cards:50000,users:9},files:3}),{accounts:7,cards:50000,files:3,rows:50016});
});
test('backup API uses current backend contracts, server backup IDs and long bounded verification timeout',async()=>{
 const calls=[],api=createBackupApi({request:async(...args)=>calls.push(args),mutate:async(...args)=>calls.push(args)});
 await api.create();await api.preview('real-server-backup');await api.restore({preview_id:'p',version:1,current_password:'secret'});await api.job('job');
 assert.equal(calls[0][0],'/backups');assert.equal(calls[0][4].timeoutMs,300000);
 assert.deepEqual(calls[1][2],{backup_id:'real-server-backup'});assert.equal(calls[2][0],'/backups/restore-jobs');assert.equal(calls[3][0],'/backups/restore-jobs/job');
});
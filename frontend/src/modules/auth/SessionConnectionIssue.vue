<script setup>
import {useRouter} from 'vue-router';
import {onMounted,onBeforeUnmount,ref} from 'vue';
import {usePortal} from './session.js';
import {landingRoute,routeAllowed} from '../../router/access.js';
import '../finance/finance-parity.css';
const {session}=usePortal(),router=useRouter();
const dialog=ref(null);
onMounted(()=>dialog.value?.showModal());
onBeforeUnmount(()=>dialog.value?.close());
async function retry(){
  if(session.state.checking)return;
  await session.refresh();
  if(!session.state.checked)return;
  if(!session.state.identity){await router.replace({name:'login'});return;}
  const route=router.currentRoute.value;
  if(route.name==='session-unavailable'){
    const next=route.query.next;
    const target=typeof next==='string'&&next.startsWith('/')&&!next.startsWith('//')?router.resolve(next):null;
    await router.replace(target&&target.name!=='session-unavailable'&&routeAllowed(target,session)?target.fullPath:landingRoute(router,session));
  }else if(!routeAllowed(route,session))await router.replace(landingRoute(router,session));
}
</script>
<template>
  <Teleport to="body"><dialog ref="dialog" class="finance-modal session-connection-issue" @cancel.prevent aria-labelledby="session-connection-title"><section role="alert">
    <h2 id="session-connection-title">تعذر التحقق من الجلسة</h2>
    <p>توقفت العمليات مؤقتًا حتى يعود الاتصال ويتم التحقق من صلاحيات حسابك.</p>
    <p>{{session.state.error}}</p>
    <button type="button" class="btn primary" :disabled="session.state.checking" @click="retry">{{session.state.checking?'جارٍ التحقق…':'إعادة المحاولة'}}</button>
  </section></dialog></Teleport>
</template>
<style scoped>
.session-connection-issue{width:min(520px,calc(100vw - 32px));padding:24px;border-radius:18px}
.session-connection-issue h2{margin-top:0}
.session-connection-issue p{line-height:1.8}
</style>

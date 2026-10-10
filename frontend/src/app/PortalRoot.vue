<script setup>
import {provide,shallowRef,onBeforeUnmount} from 'vue';
import {useRouter} from 'vue-router';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import { usePortal } from '../modules/auth/session.js';
import PresenceController from '../modules/maps/PresenceController.vue';
defineProps({ layout: { type: Object, required: true } });
const { session } = usePortal();
const presence=shallowRef(null), pendingPath=shallowRef(null), router=useRouter();
const stopBefore=router.beforeEach((to,from)=>{pendingPath.value=to.path===from.path?null:to.fullPath;});
const stopAfter=router.afterEach((to)=>{if(pendingPath.value===to.fullPath)pendingPath.value=null;});
const stopError=router.onError(()=>{pendingPath.value=null;});
onBeforeUnmount(()=>{stopBefore();stopAfter();stopError();});
provide(presenceContextKey,presence);
</script>

<template>
  <component :is="layout" v-if="session.state.identity">
    <PresenceController @ready="presence=$event" />
    <RouterView v-slot="{ Component, route }"><component v-show="!pendingPath" :is="Component" :key="`${session.state.identity.user.id}:${route.path}`" /></RouterView>
  </component>
  <RouterView v-else />
</template>


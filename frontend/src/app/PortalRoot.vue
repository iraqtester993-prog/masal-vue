<script setup>
import {provide,shallowRef,onBeforeUnmount,watch,defineAsyncComponent} from 'vue';
import {useRouter} from 'vue-router';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import { usePortal } from '../modules/auth/session.js';
import PresenceController from '../modules/maps/PresenceController.vue';
import SessionConnectionIssue from '../modules/auth/SessionConnectionIssue.vue';
import {passwordChangeRequired} from '../modules/auth/password-reset.js';
const PasswordChangePage=defineAsyncComponent(()=>import('../modules/auth/PasswordChangePage.vue'));
defineProps({ layout: { type: Object, required: true } });
const { session } = usePortal();
const presence=shallowRef(null), pendingPath=shallowRef(null), router=useRouter();
const stopBefore=router.beforeEach((to,from)=>{pendingPath.value=to.path===from.path?null:to.fullPath;});
const stopAfter=router.afterEach((to)=>{if(pendingPath.value===to.fullPath)pendingPath.value=null;});
const stopError=router.onError(()=>{pendingPath.value=null;});
watch(()=>session.state.identity,(identity,previous)=>{if(previous&&!identity&&router.currentRoute.value.meta.requiresAuth)void router.replace({name:'login'});});
onBeforeUnmount(()=>{stopBefore();stopAfter();stopError();});
provide(presenceContextKey,presence);
</script>

<template>
  <PasswordChangePage v-if="passwordChangeRequired(session.state.identity)"/>
  <component :is="layout" v-else-if="session.state.identity">
    <PresenceController @ready="presence=$event" />
    <SessionConnectionIssue v-if="session.state.checked===false&&session.state.error"/>
    <RouterView v-slot="{ Component, route }"><component v-show="!pendingPath&&!(session.state.checked===false&&session.state.error)" :is="Component" :key="`${session.state.identity.user.id}:${route.path}`" /></RouterView>
  </component>
  <RouterView v-else />
</template>


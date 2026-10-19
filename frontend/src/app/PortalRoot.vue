<script setup>
import {computed,provide,shallowRef,watch,defineAsyncComponent} from 'vue';
import {useRouter} from 'vue-router';
import {presenceContextKey} from '../modules/maps/presence-context.js';
import { usePortal } from '../modules/auth/session.js';
import PresenceController from '../modules/maps/PresenceController.vue';
import SessionConnectionIssue from '../modules/auth/SessionConnectionIssue.vue';
import {passwordChangeRequired} from '../modules/auth/password-reset.js';
import {landingRoute,routeAllowed} from '../router/access.js';
const PasswordChangePage=defineAsyncComponent(()=>import('../modules/auth/PasswordChangePage.vue'));
defineProps({ layout: { type: Object, required: true } });
const { session } = usePortal();
const presence=shallowRef(null), router=useRouter();
const routeScope=computed(()=>{const identity=session.state.identity;return JSON.stringify([identity?.user.id,identity?.account.id,identity?.membership,identity?.permissions,identity?.capabilities]);});
// Keep the mounted page during a routine session read. Only a newly accepted
// identity can revoke its visible route; controls still use session.can.
const visibleRouteAllowed=computed(()=>{const identity=session.state.identity;return !identity||routeAllowed(router.currentRoute.value,{state:{identity},can:key=>identity.permissions?.includes(key)===true});});
watch(routeScope,()=>{if(session.state.identity&&session.state.accessConfirmed&&router.currentRoute.value.meta.requiresAuth&&!visibleRouteAllowed.value)void router.replace(landingRoute(router,session));});
watch(()=>session.state.identity,(identity,previous)=>{if(previous&&!identity&&router.currentRoute.value.meta.requiresAuth)void router.replace({name:'login'});});
provide(presenceContextKey,presence);
</script>

<template>
  <PasswordChangePage v-if="passwordChangeRequired(session.state.identity)"/>
  <component :is="layout" v-else-if="session.state.identity">
    <PresenceController @ready="presence=$event" />
    <SessionConnectionIssue v-if="session.state.checked===false&&session.state.error"/>
    <RouterView v-slot="{ Component, route }"><component v-if="visibleRouteAllowed" v-show="!(session.state.checked===false&&session.state.error)" :is="Component" :key="`${routeScope}:${route.path}`" /></RouterView>
  </component>
  <RouterView v-else />
</template>


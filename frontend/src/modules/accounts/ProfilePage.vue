<script setup>
import {useRouter} from 'vue-router';
import {landingRoute} from '../../router/access.js';
import {usePortal} from '../auth/session.js';
import SelfProfileDialog from './SelfProfileDialog.vue';
import './accounts.css';
const router = useRouter(), {session} = usePortal();
function close() { router.replace(landingRoute(router, session)); }
</script>
<template>
  <section class="account-workspace"><div class="card"><h2>{{session.state.identity?.user.name}}</h2><p class="caption">{{session.state.identity?.user.email || session.state.identity?.user.login}}</p></div><SelfProfileDialog @close="close" @saved="close" /></section>
</template>

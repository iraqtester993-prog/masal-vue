<script setup>
import {usePortal} from './session.js';
import {useRouter} from 'vue-router';
import {usePreferencesTranslator} from '../preferences/preferences-state.js';
const {session} = usePortal(), router = useRouter(), uiLabel = usePreferencesTranslator();
async function logout() { await session.logout(); await router.replace({name:'login'}); }
</script>
<template>
  <section class="card"><h2>{{uiLabel('حسابك')}}</h2><p>{{uiLabel('لا توجد صفحات متاحة حاليًا. تواصل مع مسؤول الحساب.')}}</p><button type="button" class="btn" @click="logout">{{uiLabel('تسجيل الخروج')}}</button></section>
</template>

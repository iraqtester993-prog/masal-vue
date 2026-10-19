<script setup>
import AppSelect from '../../shared/components/AppSelect.vue';
import {usePreferences} from './preferences-state.js';
import {languages} from './translate.js';
import './preferences.css';
const preferences=usePreferences();
</script>
<template><AppSelect class="language-picker" aria-label="لغة الواجهة" :value="preferences?.state.language||'ar'" :disabled="!preferences||preferences.state.busy||preferences.state.loading" @change="preferences?.setLanguage($event.target.value)"><option v-for="language in languages" :key="language.id" :value="language.id" :selected="language.id===(preferences?.state.language||'ar')">{{language.name}}</option></AppSelect></template>

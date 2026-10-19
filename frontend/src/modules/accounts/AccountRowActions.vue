<script setup>
import {usePreferencesTranslator as useUiLabelTranslator} from '../preferences/preferences-state.js';
const uiLabel=useUiLabelTranslator();
import { computed } from 'vue';
import { usePortal } from '../auth/session.js';
import { childTypes } from './child-types.js';
import {canResetAccountPassword} from '../auth/password-reset.js';
import {hasOwnerAuthority,hasSystemScope} from '../auth/capabilities.js';
const props=defineProps({account:Object,compact:Boolean,tree:Boolean});
defineEmits(['details','edit','create','status','permissions','login','categories','archive','password-reset']);
const {session}=usePortal();
const actions=computed(()=>{
 const a=props.account, own=a.id===session.state.identity?.account.id, managed=!own && a.type!=='system';
 const entries={details:{label:'التفاصيل',show:true},edit:{label:'تعديل',show:managed&&session.can('account.update')},status:{label:a.status==='active'?'إيقاف':'تفعيل',show:!props.tree&&managed&&session.can('account.toggle')},archive:{label:'حذف',danger:true,show:managed&&session.can('agents.archive')&&session.state.identity?.account.type==='system'},categories:{label:'الفئات',show:managed&&session.can('agents.categories')},permissions:{label:'صلاحيات التابع',show:managed&&session.can('account.permissions')},create:{label:'إنشاء حساب تابع',show:!props.compact&&session.can('account.create')&&hasOwnerAuthority(session.state.identity)&&childTypes(a).length&&(a.type!=='sub_branch'||session.can('pos.device')&&session.can('pos.location'))},login:{label:'حساب الدخول',show:!props.compact&&managed&&session.can('account.login')&&hasSystemScope(session.state.identity)}};
 entries['password-reset']={label:'إعادة تعيين كلمة المرور',show:canResetAccountPassword(session.state.identity,a)};
 return (props.tree?['details','archive','categories','edit','permissions','password-reset']:['details','edit','status','archive','categories','permissions','create','login','password-reset']).filter(key=>entries[key].show).map(key=>({key,...entries[key]}));
});
</script>
<template><div class="actions"><button v-for="action in actions" :key="action.key" class="btn small" :class="{danger:action.danger}" @click="$emit(action.key,account)">{{uiLabel(action.label)}}</button></div></template>

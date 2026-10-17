<script setup>
import {computed} from 'vue';
import {usePreferences,usePreferencesTranslator} from '../preferences/preferences-state.js';
import {attachmentUrl,time} from '../support/communications.js';
import {navIconPath} from '../../layouts/navigation.js';
import {notificationContext,notificationGroups} from './pos-notifications.js';
const props=defineProps({rows:{type:Array,default:()=>[]},reading:{type:Number,default:null}}),emit=defineEmits(['open']);
const preferences=usePreferences(),uiLabel=usePreferencesTranslator(),groups=computed(()=>notificationGroups(props.rows,{language:preferences?.state.language||'ar'}));
</script>
<template>
 <div class="pos-notice-groups">
  <section v-for="group in groups" :key="group.key" class="pos-notice-group" :aria-label="uiLabel(group.label)">
   <h3>{{uiLabel(group.label)}}</h3>
   <div class="pos-notice-items"><button v-for="notice in group.rows" :key="notice.id" type="button" class="pos-notice-item" :class="{'is-unread':!notice.mine&&!notice.read}" :disabled="reading!==null" @click="emit('open',notice)">
    <span class="pos-notice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path :d="navIconPath(notificationContext(notice).icon)"/></svg></span>
    <span class="pos-notice-content"><span class="pos-notice-context">{{uiLabel(notificationContext(notice).label)}}<span v-if="notice.mine"> · {{uiLabel('صادر')}}</span><span v-else class="pos-notice-read"> · {{uiLabel(notice.read?'مقروء':'غير مقروء')}}</span></span><strong translate="no" dir="auto">{{notice.title}}</strong><span class="pos-notice-body" translate="no" dir="auto">{{notice.body}}</span><img v-if="notice.attachment_id" :src="attachmentUrl(notice.attachment_id)" alt="الصورة المرفقة بالإشعار" loading="lazy"><time>{{time(notice.time)}}</time></span>
    <span class="pos-notice-open"><i v-if="!notice.mine&&!notice.read" aria-hidden="true"/><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m14 6-6 6 6 6"/></svg><span class="sr-only">{{uiLabel(notice.can_open?'فتح التفاصيل':'عرض الإشعار')}}</span></span>
   </button></div>
  </section>
 </div>
</template>

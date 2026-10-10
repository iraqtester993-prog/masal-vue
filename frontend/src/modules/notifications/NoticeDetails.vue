<script setup>
import {attachmentUrl,time} from '../support/communications.js';
defineProps({notice:{type:Object,required:true}});
</script>
<template>
  <article class="notice-detail"><h3 translate="no" dir="auto">{{notice.title}}</h3><div class="notice-detail-meta"><span>من: {{notice.sender_name}}</span><time>{{time(notice.time)}}</time></div><p class="notice-detail-body" translate="no" dir="auto">{{notice.body}}</p><img v-if="notice.attachment_id" :src="attachmentUrl(notice.attachment_id)" class="notice-detail-image" alt="الصورة المرفقة بالإشعار"></article>
</template>

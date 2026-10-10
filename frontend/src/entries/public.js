import { createApp, h } from 'vue';
import { createRouter, createWebHistory, RouterView } from 'vue-router';
import CompanyPage from '../modules/company/CompanyPage.vue';
import CompanyReplyNotifications from '../modules/company/CompanyReplyNotifications.vue';
import {createInquiryNotifications,inquiryNotificationsKey} from '../modules/company/inquiry-notifications.js';
import { createSession, portalContextKey } from '../modules/auth/session.js';
import '../shared/styles/base.css';

const session = createSession('public');
const router = createRouter({history:createWebHistory(import.meta.env.BASE_URL),routes:[{path:'/messages',name:'companyMessages',component:()=>import('../modules/company/CompanyTrackingPage.vue')},{path:'/:pathMatch(.*)*',component:CompanyPage,props:{publicPage:true}}]});
let storage;
try { storage = window.localStorage; } catch { /* Notifications still work for this visit. */ }
const notifications = createInquiryNotifications(storage);
const app = createApp({render:()=>[h(CompanyReplyNotifications),h(RouterView)]});
app.provide(inquiryNotificationsKey, notifications);
app.provide(portalContextKey, {portal:{id:'public',title:'ماسال'},session});
app.use(router);
router.isReady().then(()=>app.mount('#app'));

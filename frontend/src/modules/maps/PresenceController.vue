<script setup>
import {onBeforeUnmount,onMounted,reactive,watch} from 'vue';
import {usePortal} from '../auth/session.js';
import {createMapsApi} from './maps-api.js';
import {createPresenceRuntime} from './presence-runtime.js';
import {identityKey} from './maps-model.js';
import {trackTimeActivity} from '../security/time-activity.js';
import LocationGate from './LocationGate.vue';
const props=defineProps({deviceModel:{type:String,default:''},appVersion:{type:String,default:''}}),emit=defineEmits(['ready','changed','error']);
const {session}=usePortal(),state=reactive({required:false,ready:false,sharing:false,permission:'',status:'',checking:false});
const base=createMapsApi(session.api),api={...base,heartbeat:(_,signal)=>base.heartbeat({...props.deviceModel?{device_model:props.deviceModel}:{},...props.appVersion?{app_version:props.appVersion}:{}},signal)};
let runtime,untrack;
function startLocationSharing(){return runtime?.startLocationSharing();}function stopLocationSharing(){return runtime?.stopLocationSharing();}
async function logout(){try{await session.logout();}catch(cause){state.status=cause.message;emit('error',cause);}}
function apply(next){Object.assign(state,next);emit('changed',{...next});}
onMounted(()=>{runtime=createPresenceRuntime({api,onState:apply,onUnauthorized:()=>session.refresh()});untrack=trackTimeActivity(session);runtime.setIdentity(session.state.identity);emit('ready',{state,startLocationSharing,stopLocationSharing,refresh:()=>runtime?.refreshOwn()});});
watch(()=>identityKey(session.state.identity),()=>runtime?.setIdentity(session.state.identity));
onBeforeUnmount(()=>{runtime?.dispose();runtime=null;untrack?.();untrack=null;});
defineExpose({state,startLocationSharing,stopLocationSharing});
</script>

<template><LocationGate v-bind="state" @enable="startLocationSharing" @logout="logout" /></template>

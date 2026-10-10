<script setup>
import { computed } from 'vue';
import {usePreferencesTranslator,usePreferencesMessageTranslator} from '../preferences/preferences-state.js';
const t=usePreferencesTranslator(),systemMessage=usePreferencesMessageTranslator();
const props = defineProps({ name: String, label: String, modelValue: [String, Number], type: { type: String, default: 'text' }, required: Boolean, maxlength: Number, minlength: Number, options: Array, hint: String, error: [String, Array], disabled: Boolean, full: Boolean });
const emit = defineEmits(['update:modelValue']);
const id = computed(() => `account-field-${props.name.replaceAll('.', '-')}`);
const message = computed(() => Array.isArray(props.error) ? props.error[0] : props.error);
const describedBy = computed(() => [props.hint && `${id.value}-hint`, message.value && `${id.value}-error`].filter(Boolean).join(' ') || undefined);
</script>
<template>
  <div class="form-field" :class="{full}"><label :for="id">{{ t(label) }}</label>
    <select v-if="options" :id="id" :name="name" :value="modelValue" :required="required" :disabled="disabled" :aria-invalid="!!message" :aria-describedby="describedBy" @change="emit('update:modelValue', $event.target.value)"><option v-for="option in options" :key="option.value ?? option" :value="option.value ?? option">{{ option.label ? t(option.label) : option }}</option></select>
    <textarea v-else-if="type === 'textarea'" :id="id" :name="name" :value="modelValue" rows="3" :required="required" :minlength="minlength" :maxlength="maxlength" :disabled="disabled" :aria-invalid="!!message" :aria-describedby="describedBy" @input="emit('update:modelValue', $event.target.value)"/>
    <input v-else :id="id" :name="name" :value="modelValue" :type="type" :required="required" :maxlength="maxlength" :minlength="minlength" :disabled="disabled" :dir="['email','tel','password'].includes(type) ? 'ltr' : undefined" :autocomplete="type === 'password' ? 'new-password' : type === 'email' ? 'email' : 'off'" :aria-invalid="!!message" :aria-describedby="describedBy" @input="emit('update:modelValue', $event.target.value)"/>
    <small v-if="hint" :id="`${id}-hint`">{{ t(hint) }}</small><span v-if="message" :id="`${id}-error`" class="field-error">{{ /[\u0600-\u06ff]/.test(message) ? systemMessage(message) : t('تحقق من قيمة هذا الحقل.') }}</span>
  </div>
</template>

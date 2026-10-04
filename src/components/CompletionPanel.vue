<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["completion-panel"]);
export default options;
</script>

<template>
  <section class="operations-panel">
    <div
      v-if="s.securityPolicy.enforceSessions"
      class="actions"
      style="margin-bottom: 18px"
    >
      <span class="badge">{{ t("الجلسة مطلوبة للعمليات") }}</span>
    </div>
    <teleport to="body"
      ><div
        v-if="open"
        class="overlay security-dialog"
        role="presentation"
        @click.self="close"
      >
        <section
          class="modal"
          role="dialog"
          aria-modal="true"
          @keydown.esc.stop.prevent="close"
          @keydown.tab="trap"
          :aria-label="t('أمان حسابي')"
        >
          <div class="cardhead">
            <h2>{{ t("أمان حسابي") }} · {{ $root.tr(vm.actor.name) }}</h2>
            <button class="iconbtn" @click="close" :aria-label="t('إغلاق')">
              ×
            </button>
          </div>
          <div class="notice">
            {{
              t(
                "رموز الاختبار تظهر هنا محليًا؛ لا يجري إرسال بريد أو رسالة هاتف.",
              )
            }}
          </div>
          <label
            >{{ t("الإجراء")
            }}<select v-model="mode" @change="clear">
              <option
                v-for="x in [{id:'login',label:'تسجيل الدخول'},{id:'enroll',label:'تفعيل التحقق بخطوتين'},{id:'stepup',label:'تأكيد إجراء حساس'},{id:'forgot',label:'نسيت كلمة المرور'},{id:'first',label:'تغيير كلمة المرور المؤقتة'},{id:'recovery',label:'استخدام كود استعادة'}].filter(x=&gt;vm.actor.role!=='owner'||x.id!=='forgot')"
                :value="x.id"
              >
                {{ t(x.label) }}
              </option>
            </select></label
          >
          <div class="formgrid">
            <label v-if="mode === 'forgot'"
              >{{ t("البريد أو معرف الحساب")
              }}<input v-model="identity" autocomplete="username" /></label
            ><label v-if="['login', 'enroll', 'stepup', 'first'].includes(mode)"
              >{{ t("كلمة المرور")
              }}<input
                type="password"
                v-model="password"
                autocomplete="current-password" /></label
            ><label v-if="['forgot', 'first', 'recovery'].includes(mode)"
              >{{ t("كلمة المرور الجديدة")
              }}<input
                type="password"
                v-model="nextPassword"
                autocomplete="new-password" /></label
            ><label v-if="mode === 'recovery'"
              >{{ t("كود استعادة لمرة واحدة")
              }}<input v-model="recoveryCode" autocomplete="off" /></label
            ><label v-if="mode === 'login'"
              >{{ t("IP جلسة الاختبار") }}<input v-model="network.ip" /></label
            ><label v-if="mode === 'login'"
              ><input type="checkbox" v-model="network.vpn" />{{
                t("محاكاة اتصال VPN")
              }}</label
            >
          </div>
          <button class="btn primary" :disabled="busy" @click="start">
            {{ t("متابعة") }}
          </button>
          <p role="status">{{ t(message) }}</p>
          <div v-if="challenge?.demoCode" class="notice">
            <b
              >{{ t("صندوق رسائل الاختبار") }}:
              {{ $root.tr(challenge.demoCode) }}</b
            >
            <p>{{ t("الرمز صالح لدقيقتين وخمس محاولات فقط") }}</p>
            <label
              >{{ t("رمز التحقق")
              }}<input
                v-model="otp"
                inputmode="numeric"
                maxlength="6"
                autocomplete="one-time-code" /></label
            ><button class="btn primary" :disabled="busy" @click="confirm">
              {{ t("تأكيد الرمز") }}
            </button>
          </div>
          <div v-if="recoveryCodes.length" class="card">
            <h3>{{ t("أكواد الاستعادة — احفظها الآن") }}</h3>
            <p
              v-for="code in recoveryCodes"
              dir="ltr"
              style="overflow-wrap: anywhere"
            >
              {{ $root.tr(code) }}
            </p>
          </div>
          <details>
            <summary>{{ t("الجلسات ومفتاح المرور") }}</summary>
            <div v-for="x in sessions" class="rowline">
              <span>{{ $root.tr(vm.formatTime(new Date(x.created))) }}</span
              ><span>{{ t(x.active ? "فعال" : "منتهية") }}</span>
            </div>
            <div class="actions">
              <button class="btn" @click="revoke">
                {{ t("إنهاء جلساتي") }}</button
              ><button class="btn" @click="biometric">
                {{ t("فحص دعم مفتاح المرور") }}
              </button>
            </div>
            <p>{{ t(biometricStatus) }}</p>
          </details>
        </section>
      </div></teleport
    >
  </section>
</template>

<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["password-reset-panel"]);
export default options;
</script>

<template>
  <div>
    <button
      v-if="vm.loginScreen &amp;&amp; showLoginReset"
      type="button"
      class="btn reset-login-link"
      @click="start()"
    >
      {{ $root.tr("إعادة تعيين كلمة المرور") }}</button
    ><teleport to="body"
      ><dialog
        ref="dialog"
        class="reset-password-dialog"
        @cancel.prevent="close"
      >
        <form @submit.prevent="submit" novalidate="">
          <h2>
            {{
              $root.tr(
                phase === "identify"
                  ? "التحقق من الحساب"
                  : phase === "otp"
                    ? "رمز التحقق"
                    : "كلمة المرور الجديدة",
              )
            }}
          </h2>
          <p
            v-if="user &amp;&amp; phase==='password'"
            class="reset-account-name"
          >
            {{ $root.tr(name) }}
          </p>
          <p v-if="phase === 'identify'" class="help">
            {{ $root.tr("أدخل رقم الهاتف المسجل لحسابك لاستلام رمز التحقق.") }}
          </p>
          <template v-if="phase === 'identify'"
            ><label for="reset-account-phone"
              >{{ $root.tr("رقم الهاتف المسجل")
              }}<input
                id="reset-account-phone"
                v-model.trim="phone"
                inputmode="tel"
                type="tel"
                autocomplete="tel"
                maxlength="24"
                required=""
                :placeholder="
                  $root.tr('07712345678 أو +9647712345678')
                " /></label></template
          ><template v-else-if="phase === 'otp'"
            ><p class="notice">
              {{ $root.tr("رمز التحقق للرقم المنتهي بـ ")
              }}<b dir="ltr">{{ $root.tr(phoneHint) }}</b
              >.
            </p>
            <p class="notice warn">
              {{
                $root.tr(
                  "وضع تجربة محلي — الرمز 123456. لم تُرسل رسالة واتساب.",
                )
              }}
            </p>
            <label for="reset-otp"
              >{{ $root.tr("رمز التحقق")
              }}<input
                id="reset-otp"
                v-model.trim="code"
                inputmode="numeric"
                autocomplete="one-time-code"
                maxlength="6"
                pattern="[0-9]{6}"
                required="" /></label></template
          ><template v-else=""
            ><p class="help" id="reset-password-hint">
              {{ $root.tr("8 أحرف على الأقل، تتضمن حرفًا إنكليزيًا ورقمًا.") }}
            </p>
            <label for="reset-new-password">{{
              $root.tr("كلمة المرور الجديدة")
            }}</label>
            <div class="reset-password-field">
              <input
                id="reset-new-password"
                :type="showPassword ? 'text' : 'password'"
                v-model="password"
                aria-describedby="reset-password-hint"
                minlength="8"
                autocomplete="new-password"
                required=""
              /><button
                type="button"
                class="btn small"
                :aria-label="
                  $root.tr(
                    showPassword
                      ? 'إخفاء كلمة المرور الجديدة'
                      : 'إظهار كلمة المرور الجديدة',
                  )
                "
                :aria-pressed="showPassword"
                aria-controls="reset-new-password"
                @click="showPassword = !showPassword"
              >
                {{ $root.tr(showPassword ? "إخفاء" : "إظهار") }}
              </button>
            </div>
            <label for="reset-confirm-password">{{
              $root.tr("تأكيد كلمة المرور")
            }}</label>
            <div class="reset-password-field">
              <input
                id="reset-confirm-password"
                :type="showConfirmation ? 'text' : 'password'"
                v-model="confirmation"
                minlength="8"
                autocomplete="new-password"
                required=""
              /><button
                type="button"
                class="btn small"
                :aria-label="
                  $root.tr(
                    showConfirmation
                      ? 'إخفاء تأكيد كلمة المرور'
                      : 'إظهار تأكيد كلمة المرور',
                  )
                "
                :aria-pressed="showConfirmation"
                aria-controls="reset-confirm-password"
                @click="showConfirmation = !showConfirmation"
              >
                {{ $root.tr(showConfirmation ? "إخفاء" : "إظهار") }}
              </button>
            </div></template
          >
          <p v-if="error" role="alert">{{ $root.tr(error) }}</p>
          <button
            v-if="phase === 'otp'"
            type="button"
            class="btn small"
            :disabled="busy"
            @click="resend"
          >
            {{ $root.tr("إعادة إرسال الرمز") }}
          </button>
          <div class="formfoot">
            <button type="button" class="btn" :disabled="busy" @click="close">
              {{ $root.tr("إلغاء") }}</button
            ><button class="btn primary" :disabled="busy">
              {{
                $root.tr(
                  phase === "identify"
                    ? "إرسال رمز التحقق"
                    : phase === "otp"
                      ? "تحقق من الرمز"
                      : "حفظ كلمة المرور",
                )
              }}
            </button>
          </div>
        </form>
      </dialog></teleport
    >
  </div>
</template>

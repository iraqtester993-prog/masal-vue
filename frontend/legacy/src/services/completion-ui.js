(function (root) {
  "use strict";
  const panel = {
    data() {
      return {
        open: false,
        mode: "login",
        password: "",
        nextPassword: "",
        identity: "",
        otp: "",
        challenge: null,
        recoveryCode: "",
        recoveryCodes: [],
        message: "",
        busy: false,
        network: { ip: "127.0.0.1", vpn: false },
        policy: {},
        route: {
          severity: "مرتفعة",
          channel: "داخل النظام",
          target: "all",
          delay: 0,
        },
        biometricStatus: "",
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      s() {
        return this.vm.s;
      },
      e() {
        return this.vm.engine;
      },
      user() {
        return this.vm.currentUser;
      },
      sessions() {
        return this.s.localSessions.filter((x) => x.user === this.user);
      },
      outbox() {
        return this.s.alertOutbox.filter(
          (x) =>
            this.vm.actor.role === "owner" ||
            this.vm.accounts.some((a) => a.id === x.target),
        );
      },
    },
    mounted() {
      this.policy = { ...this.s.securityPolicy };
      this.identity = this.user;
    },
    watch: {
      open(value) {
        if (value) {
          this.lastFocus = document.activeElement;
          this.$nextTick(() =>
            document.querySelector(".security-dialog select")?.focus(),
          );
        } else this.lastFocus?.focus?.();
      },
      user() {
        this.clear();
        this.identity = this.user;
        this.route.target = this.vm.actor.agent || "all";
      },
    },
    methods: {
      t(value) {
        return this.vm.tr(value);
      },
      clear() {
        this.password = "";
        this.nextPassword = "";
        this.otp = "";
        this.challenge = null;
        this.recoveryCode = "";
        this.recoveryCodes = [];
        this.message = "";
      },
      close() {
        this.open = false;
        this.clear();
      },
      trap(event) {
        const nodes = [
          ...event.currentTarget.querySelectorAll(
            "button,input,select,textarea,summary,a",
          ),
        ].filter((n) => !n.disabled && n.getClientRects().length);
        if (!nodes.length) return;
        const first = nodes[0],
          last = nodes.at(-1);
        if (event.shiftKey && document.activeElement === first) {
          event.preventDefault();
          last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      },
      async task(action) {
        if (this.busy) return;
        this.busy = true;
        const actor = this.user;
        try {
          await action();
          if (actor !== this.user) {
            this.clear();
            throw Error("تغير الحساب أثناء العملية");
          }
          this.vm.persist?.();
        } catch (e) {
          this.message = e.message;
        } finally {
          this.busy = false;
        }
      },
      async start() {
        return this.task(async () => {
          if (this.mode === "forgot") {
            this.challenge = await MasalAuth.forgot(this.s, this.identity);
            this.message = this.challenge.message;
            return;
          }
          if (this.mode === "recovery") {
            await MasalAuth.recovery(
              this.s,
              this.user,
              this.recoveryCode,
              this.nextPassword,
            );
            this.message = "تمت استعادة الحساب وإبطال الجلسات القديمة";
            this.recoveryCode = "";
            this.nextPassword = "";
            return;
          }
          if (this.mode === "first") {
            await MasalAuth.firstPassword(
              this.s,
              this.user,
              this.password,
              this.nextPassword,
            );
            this.message = "تم تغيير كلمة المرور؛ سجل الدخول بالكلمة الجديدة";
            this.password = this.nextPassword = "";
            return;
          }
          const result = await MasalAuth.start(
            this.s,
            this.user,
            this.password,
            this.mode,
            this.network,
          );
          this.password = "";
          if (result.session) {
            this.message = "تم تسجيل الدخول المحلي";
            this.challenge = null;
          } else {
            this.challenge = result;
            this.message = "أدخل رمز قناة الاختبار المحلية";
          }
        });
      },
      async confirm() {
        return this.task(async () => {
          if (!this.challenge?.id) throw Error("ابدأ طلبًا جديدًا");
          if (this.mode === "forgot") {
            await MasalAuth.reset(
              this.s,
              this.challenge.id,
              this.otp,
              this.nextPassword,
            );
            this.message = "تم تغيير كلمة المرور وإبطال الجلسات القديمة";
            this.nextPassword = "";
          } else {
            const result = await MasalAuth.confirm(
              this.s,
              this.challenge.id,
              this.otp,
            );
            if (result.recovery) {
              this.recoveryCodes = result.recovery;
              this.message =
                "تم التفعيل؛ احفظ أكواد الاستعادة الآن، لن تظهر مرة أخرى";
            } else
              this.message = result.verified
                ? "تم تأكيد الإجراء الحساس لمدة خمس دقائق؛ أعد تنفيذ الإجراء"
                : "تم تسجيل الدخول المحلي";
          }
          this.challenge = null;
          this.otp = "";
        });
      },
      savePolicy() {
        this.vm.run(
          () => this.e.saveSecurityPolicy(this.policy),
          "تم حفظ سياسة الأمان",
        );
      },
      revoke() {
        this.vm.run(() => {
          MasalAuth.revoke(this.s, this.user);
          this.message = "تم إنهاء جميع جلسات هذا الحساب";
        });
      },
      addRoute() {
        this.vm.run(
          () => this.e.saveAlertRoute(this.route),
          "تم حفظ قاعدة التوجيه",
        );
      },
      routeNow() {
        this.vm.run(() => this.e.routeAlerts(), "تم فحص طابور التنبيهات");
      },
      toggleRoute(rule) {
        this.vm.run(() => {
          this.e.requirePermission("notifications.rules");
          if (rule.target === "all")
            this.e.requirePermission("notifications.broadcast");
          else this.e.accountCheck(rule.target);
          rule.active = !rule.active;
          this.e.log("حالة قاعدة توجيه", rule.id, null, {
            active: rule.active,
          });
        });
      },
      async biometric() {
        return this.task(async () => {
          if (!window.PublicKeyCredential || !navigator.credentials)
            throw Error("التحقق البيومتري غير متاح في هذا المتصفح");
          if (
            location.protocol !== "https:" &&
            location.hostname !== "localhost"
          )
            throw Error(
              "تسجيل مفتاح المرور يحتاج نسخة HTTPS أو localhost وربط المصادقة",
            );
          const available =
            await PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable();
          this.biometricStatus = available
            ? "الجهاز يدعم مفتاح المرور؛ تسجيله واعتماده ينتظر خادم المصادقة"
            : "لا يوجد موثق بيومتري متاح على هذا الجهاز";
        });
      },
    },
  };
  root.MasalCompletionUI = {
    install(o) {
      const data = o.data;
      o.data = function () {
        const d = data.call(this);
        MasalAuth.init(d.s);
        return d;
      };
      o.components["completion-panel"] = panel;
      const savedWatcher = o.watch.s.handler;
      o.watch.s.handler = function (value) {
        MasalAuth.init(value);
        savedWatcher.call(this, value);
      };
      o.methods.receiptTr = function (value) {
        const label = this.receiptAgent.receiptLanguage;
        const lang =
          label === "English"
            ? "en"
            : label === "کوردی"
              ? "ckb"
              : label === "العربية"
                ? "ar"
                : this.lang;
        return MasalLocale.translate(value, lang);
      };
      const mounted = o.mounted;
      o.mounted = function () {
        mounted.call(this);
        this._sessionTimer = setInterval(() => {
          MasalAuth.expire(this.s);
        }, 15000);
        this._sessionTouch = () => {
          try {
            const session = MasalAuth.valid(this.s, this.currentUser);
            if (session) MasalAuth.touch(this.s, session.id);
          } catch (e) {
            MasalAuth.revoke(this.s, this.currentUser);
          }
        };
        document.addEventListener("pointerdown", this._sessionTouch);
        document.addEventListener("keydown", this._sessionTouch);
      };
      o.beforeUnmount = function () {
        clearInterval(this._sessionTimer);
        document.removeEventListener("pointerdown", this._sessionTouch);
        document.removeEventListener("keydown", this._sessionTouch);
      };
    },
  };
})(globalThis);

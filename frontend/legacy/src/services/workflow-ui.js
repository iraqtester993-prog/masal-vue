(function (root) {
  "use strict";
  const component = {
    data() {
      return {
        price: {
          agent: "",
          product: "",
          city: "الكل",
          price: 0,
          rate: 1,
          effective: Masal.day(),
        },
        limits: { limit: 10, dailyQty: 100, dailyAmount: 500000 },
        limitAccount: "",
        batch: "",
        reason: "",
        hours: 24,
        provider: "",
        providerDraft: {},
        extraText: "",
        extraProduct: "",
        staff: "",
        staffKind: "central",
        staffAgent: "",
        creditLimit: 0,
        creditAgent: "",
        delivery: "",
        deliveryPassword: "",
        deliveryReference: "",
        user: "",
        password: "",
        newPassword: "",
        otp: "",
        challenge: null,
        authMessage: "",
        notificationThreshold: 10,
        bulkQuantity: 20,
        bulkStatus: [],
        bulkKey: Masal.id("BULK"),
        securityDraft: { minOS: "", require2FA: false },
        authBusy: false,
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
      page() {
        return this.vm.page;
      },
      visible() {
        return [
          "products",
          "providers",
          "users",
          "wallets",
          "exports",
          "sales",
          "sell",
          "security",
          "notifications",
          "support",
          "branding",
        ].includes(this.page);
      },
      exportRequests() {
        return this.s.exportRequests.filter((r) => this.e.allowed(r.agent));
      },
    },
    watch: {
      page() {
        this.reset();
      },
      "vm.currentUser"() {
        this.reset();
      },
      provider() {
        this.providerDraft = Masal.clone(
          this.s.providers.find((p) => p.id === this.provider) || {},
        );
      },
      extraProduct() {
        this.extraText = (
          this.s.products.find((p) => p.id === this.extraProduct)
            ?.extraFields || []
        )
          .map(
            (f) =>
              f.key + "|" + f.label + "|" + (f.required ? "مطلوب" : "اختياري"),
          )
          .join("\n");
      },
    },
    mounted() {
      this.reset();
    },
    methods: {
      can(k) {
        return this.vm.can(k);
      },
      act(fn, message = "تم الحفظ") {
        return this.vm.run(fn, message);
      },
      name(id) {
        return (
          [
            ...this.s.agents,
            ...this.s.pos,
            ...this.s.products,
            ...this.s.users,
          ].find((a) => a.id === id)?.name || id
        );
      },
      reset() {
        this.price.agent = this.vm.visibleAgents[0]?.id || "";
        this.price.product = this.s.products[0]?.id || "";
        this.limitAccount = this.price.agent;
        this.provider = this.s.providers[0]?.id || "";
        this.providerDraft = Masal.clone(this.s.providers[0] || {});
        this.extraProduct = this.price.product;
        this.batch = this.vm.visibleBatches[0]?.id || "";
        this.staff = "";
        this.staffAgent = "";
        this.creditAgent = this.price.agent;
        this.delivery =
          this.vm.visibleSales.find((t) => this.vm.canViewSaleCards(t))?.id ||
          "";
        this.user = this.vm.currentUser;
        this.password = "";
        this.newPassword = "";
        this.otp = "";
        this.challenge = null;
        this.securityDraft = {
          minOS: this.s.settings.minOS || "",
          require2FA: !!this.s.settings.require2FA,
        };
      },
      savePrice() {
        this.act(() => this.e.savePricePolicy(this.price));
      },
      saveLimits() {
        this.act(() =>
          this.e.saveLimits(this.limitAccount, this.price.product, this.limits),
        );
      },
      saveProvider() {
        this.act(() => {
          this.e.requirePermission("providers.images");
          const p = this.s.providers.find((p) => p.id === this.provider);
          if (!p) throw Error("اختر مزودًا");
          const old = Masal.clone(p);
          for (const k of ["logo", "appImage", "receiptImage"])
            p[k] = this.providerDraft[k] || "";
          this.e.log(
            "صور المزود",
            p.id,
            { name: old.name },
            { name: p.name, images: true },
          );
        });
      },
      saveExtra() {
        this.act(() => {
          this.e.requirePermission("products.fields");
          const p = this.s.products.find((p) => p.id === this.extraProduct);
          if (!p) throw Error("اختر فئة");
          const fields = this.extraText
            .split("\n")
            .filter((x) => x.trim())
            .map((line) => {
              const [key, label, required] = line
                .split("|")
                .map((x) => x.trim());
              if (
                !/^[a-z][a-z0-9_]{1,30}$/.test(key) ||
                !label ||
                [
                  "pin",
                  "expiry",
                  "serial",
                  "cvc",
                  "reference",
                  "constructor",
                  "prototype",
                  "__proto__",
                ].includes(key)
              )
                throw Error(
                  "مفتاح الحقل يجب أن يكون اسمًا إنكليزيًا مختلفًا عن الحقول الأساسية",
                );
              return { key, label, required: required === "مطلوب" };
            });
          if (new Set(fields.map((f) => f.key)).size !== fields.length)
            throw Error("مفتاح مكرر");
          p.extraFields = fields;
          this.e.log("حقول إضافية للفئة", p.id, null, fields);
        });
      },
      setStaff() {
        this.act(() => {
          this.e.requirePermission("users.role");
          this.e.requirePermission("users.scope");
          const u = this.s.users.find((u) => u.id === this.staff);
          if (!u || u.role === "owner" || u.id === this.vm.currentUser)
            throw Error("اختر موظفًا غير المدير أو حسابك");
          if (
            this.vm.actor.role !== "owner" &&
            MasalAccess.scope(this.s, u).some((a) => !this.e.allowed(a))
          )
            throw Error("الموظف خارج النطاق");
          if (this.staffKind === "central" && this.vm.actor.role !== "owner")
            throw Error("إنشاء حساب مركزي من صلاحية مدير النظام");
          if (this.staffKind === "agent") {
            this.e.require(this.staffAgent);
            if (!this.s.agents.some((a) => a.id === this.staffAgent))
              throw Error("اختر الوكيل");
            u.agent = this.staffAgent;
            u.assigned = [this.staffAgent];
            u.access = {
              ...(u.access || {}),
              scope: { roots: [this.staffAgent], descendants: true },
            };
          } else u.agent = "";
          u.employment = this.staffKind;
          this.e.log("تحديد تبعية الموظف", u.id, null, {
            employment: u.employment,
            agent: u.agent,
          });
        });
      },
      setCredit() {
        this.act(() => {
          this.e.requirePermission("wallets.creditLimit");
          this.e.require(this.creditAgent);
          if (!Number.isFinite(+this.creditLimit) || +this.creditLimit < 0)
            throw Error("سقف غير صالح");
          const a = this.s.agents.find((a) => a.id === this.creditAgent);
          a.creditLimit = +this.creditLimit;
          this.e.log("سقف مديونية", a.id, null, { limit: a.creditLimit });
        });
      },
      requestExport() {
        this.act(
          () => this.e.requestExport(this.batch, this.reason),
          "تم حجر المتبقي وسحب رصيده وفتح طلب تصدير",
        );
      },
      approveExport(r) {
        this.act(() => this.e.approveExport(r.id, this.hours));
      },
      selectExport(r) {
        this.vm.claimForm.batch = r.batch;
        this.vm.claimForm.reason = r.reason;
        this.vm.notify(
          "تم اختيار الدفعة؛ أدخل كلمة التشفير في نموذج التنزيل أدناه",
        );
      },
      async deliver() {
        const actor = this.vm.currentUser;
        try {
          this.e.requirePermission("sell.deliver");
          this.e.requirePermission("data.pin");
          const t = this.s.sales.find((t) => t.id === this.delivery);
          if (!t) throw Error("اختر عملية");
          this.e.requireOwnSeller(t.pos);
          if (
            this.deliveryPassword.length < 12 ||
            !this.deliveryReference.trim()
          )
            throw Error("كلمة تشفير 12 حرفًا ومرجع تسليم مطلوبان");
          const payload = {
            transaction: t.id,
            cards: this.s.cards
              .filter((c) => t.cards.includes(c.id))
              .map((c) => ({
                pin: c.pin,
                serial: c.serial,
                expiry: c.expiry,
                cvc: c.cvc || "",
                reference: c.reference || "",
                internal: c.internal,
                extra: c.extra || {},
              })),
          };
          const data = await MasalVault.encrypt(
            JSON.stringify(payload),
            this.deliveryPassword,
          );
          if (actor !== this.vm.currentUser) throw Error("تغير الحساب");
          this.e.markDelivered(t.id, "ملف مشفر", this.deliveryReference);
          data.purpose = "card-delivery";
          download(
            "masal-delivery-" + t.id + ".encrypted.json",
            JSON.stringify(data),
          );
          this.deliveryPassword = "";
          this.deliveryReference = "";
          this.delivery = "";
          this.vm.notify(
            "تم تنزيل ملف تسليم مشفر للبطاقات نفسها دون خصم إضافي",
          );
        } catch (e) {
          this.vm.notify(e.message, true);
        }
      },
      scan() {
        this.act(() => {
          this.e.requirePermission("notifications.rules");
          this.s.serviceSettings.stockThreshold = Number(
            this.notificationThreshold,
          );
          if (
            !Number.isFinite(this.s.serviceSettings.stockThreshold) ||
            this.s.serviceSettings.stockThreshold < 0
          )
            throw Error("حد غير صالح");
          this.e.scanAlerts();
        });
      },
      bulkSell() {
        this.act(() => {
          this.e.requirePermission("sell.bulk");
          this.e.requirePermission("data.pin");
          const f = this.vm.saleForm,
            p = this.s.products.find((p) => p.id === f.product),
            qty = Number(this.bulkQuantity);
          if (!p || !Number.isInteger(qty) || qty < 1 || qty > 1000)
            throw Error("كمية الجملة من 1 إلى 1000");
          let remaining = qty,
            index = 0;
          this.bulkStatus = [];
          while (remaining) {
            const n = Math.min(remaining, p.limit);
            try {
              const t = this.e.sell(
                f.pos,
                f.product,
                n,
                this.bulkKey + "-" + index,
              );
              this.bulkStatus.push({ id: t.id, quantity: n, status: "صادر" });
            } catch (e) {
              this.bulkStatus.push({
                id: "جزء " + (index + 1),
                quantity: n,
                status: e.message,
              });
              break;
            }
            remaining -= n;
            index++;
          }
        });
      },
      async resetPassword() {
        if (this.authBusy) return;
        this.authBusy = true;
        const actor = this.vm.currentUser;
        try {
          if (!this.newPassword) throw Error("أدخل كلمة المرور الجديدة");
          await MasalPasswordAdmin.directReset(
            this.e,
            this.user,
            this.newPassword,
            this.newPassword,
          );
          if (actor !== this.vm.currentUser) throw Error("تغير الحساب");
          this.newPassword = "";
          this.authMessage =
            "تم تغيير كلمة المرور مباشرة بواسطة مدير النظام وإبطال الجلسات القديمة.";
        } catch (e) {
          this.vm.notify(e.message, true);
        } finally {
          this.authBusy = false;
        }
      },
      async loginTest() {
        if (this.authBusy) return;
        this.authBusy = true;
        try {
          const u = this.s.users.find((u) => u.id === this.user);
          if (!this.s.settings.login || !u?.active)
            throw Error("الدخول أو الحساب موقوف");
          if (!(await MasalStaff.verifyPassword(this.password, u.credentials)))
            throw Error("كلمة المرور غير صحيحة");
          if (u.mustChangePassword) {
            if (
              !/^(?=.*[A-Za-z])(?=.*\d).{10,}$/.test(this.newPassword) ||
              this.newPassword === this.password
            )
              throw Error(
                "اكتب كلمة جديدة مختلفة من 10 أحرف تتضمن حرفًا ورقمًا",
              );
            u.credentials = await MasalStaff.passwordHash(this.newPassword);
            u.mustChangePassword = false;
          }
          if (this.s.settings.require2FA) {
            const value = new Uint32Array(1);
            crypto.getRandomValues(value);
            this.challenge = {
              user: u.id,
              code: String(value[0] % 1000000).padStart(6, "0"),
              expires: Date.now() + 120000,
              attempts: 0,
            };
            this.authMessage =
              "رمز محاكاة لمرة واحدة ظاهر أدناه بدل إرساله إلى هاتف.";
          } else this.finishLogin(u);
        } catch (e) {
          this.authMessage = e.message;
        } finally {
          this.authBusy = false;
        }
      },
      verifyOTP() {
        try {
          const c = this.challenge;
          if (!c || c.expires < Date.now() || c.attempts >= 5)
            throw Error("انتهت صلاحية الرمز؛ أعد اختبار الدخول");
          c.attempts++;
          if (this.otp !== c.code) throw Error("الرمز غير صحيح");
          const u = this.s.users.find((u) => u.id === c.user);
          if (!u?.active || !this.s.settings.login) throw Error("الدخول موقوف");
          this.finishLogin(u);
          this.challenge = null;
        } catch (e) {
          this.authMessage = e.message;
        }
      },
      finishLogin(u) {
        this.s.sessions.push({
          id: Masal.id("SESSION"),
          user: u.id,
          time: new Date().toISOString(),
          lastActivity: Date.now(),
          status: "فعال",
          local: true,
        });
        this.authMessage =
          "نجح اختبار الدخول المحلي. الجلسة محفوظة في السجل؛ حماية الدخول الفعلية تتطلب الخادم.";
        this.password = "";
        this.newPassword = "";
        this.otp = "";
      },
      saveSecurity() {
        this.act(() => {
          this.e.requirePermission("security.policies");
          Object.assign(this.s.settings, this.securityDraft);
          this.e.log(
            "سياسة إصدار ومصادقة",
            "settings",
            null,
            this.securityDraft,
          );
        });
      },
    },
  };
  root.MasalWorkflowUI = {
    install(o) {
      o.components["workflow-panel"] = component;
      o.methods.newWorkflowKey = () => Masal.id("BULK");
      const exportOriginal = o.methods.exportEncrypted;
      o.methods.exportEncrypted = async function () {
        try {
          this.engine.requirePermission("exports.encrypt");
          const r = this.s.exportRequests.find(
            (r) =>
              r.batch === this.claimForm.batch &&
              r.status === "معتمد" &&
              r.until > new Date().toISOString(),
          );
          if (!r) throw Error("اطلب التصدير واعتمده ضمن المهلة أولًا");
          await exportOriginal.call(this);
          const record = this.s.exports
            .slice()
            .reverse()
            .find((x) => x.batch === r.batch);
          if (record) {
            record.exportRequest = r.id;
            record.claim =
              this.s.claims.find((c) => c.id === r.claim)?.id || r.claim || "";
            record.status = "مشفّر";
            record.product =
              this.s.batches.find((b) => b.id === r.batch)?.product || "";
            record.provider =
              this.s.batches.find((b) => b.id === r.batch)?.provider || "";
            record.reason = r.reason;
            record.requestedBy = r.user;
            record.approver = r.approver;
            record.exportedAt = new Date().toISOString();
            this.engine.log("ربط ملف التصدير بطلبه", record.id, null, {
              exportRequest: r.id,
              claim: record.claim,
              product: record.product,
              provider: record.provider,
            });
          }
          r.status = "تم التنزيل";
          r.downloadedAt = new Date().toISOString();
        } catch (e) {
          this.notify(e.message, true);
        }
      };
      const price = o.methods.priceFor;
      o.methods.priceFor = function (agent, product) {
        const city = this.s.pos.find((p) => p.id === this.saleForm.pos)?.city;
        return this.engine.policyPrice(agent, product, city);
      };
    },
  };
})(globalThis);

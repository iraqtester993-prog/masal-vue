(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype;
  const fields = [
    {
      key: "app",
      label: "إيقاف النظام بالكامل",
      hint: "منع الدخول وتنفيذ العمليات للحسابات المستهدفة",
    },
    {
      key: "login",
      label: "إيقاف الدخول",
      hint: "منع الدخول واستخدام الجلسة للحسابات المستهدفة",
    },
    { key: "sales", label: "إيقاف البيع", hint: "إيقاف بيع البطاقات فقط" },
    { key: "printing", label: "إيقاف الطباعة", hint: "إيقاف طباعة البطاقات" },
    {
      key: "import",
      label: "إيقاف رفع الطلبيات",
      hint: "منع استيراد دفعات جديدة",
    },
  ];
  const scopes = [
    { id: "all", label: "عام", hint: "جميع الحسابات التشغيلية" },
    {
      id: "main",
      label: "الوكلاء الرئيسيون",
      hint: "الوكلاء فقط دون الفروع والنقاط",
    },
    { id: "branch", label: "الفروع", hint: "الفروع المباشرة فقط" },
    { id: "subbranch", label: "الفروع الفرعية", hint: "الفروع التابعة لفروع" },
    { id: "pos", label: "نقاط البيع", hint: "جميع نقاط البيع فقط" },
    { id: "custom", label: "مخصص", hint: "اختر حساباً أو عدة حسابات" },
  ];
  function accounts(s) {
    return [
      ...s.agents.map((a) => ({
        ...a,
        kind: !a.parent
          ? "main"
          : s.agents.find((x) => x.id === a.parent)?.parent
            ? "subbranch"
            : "branch",
      })),
      ...s.pos.map((p) => ({ ...p, kind: "pos" })),
    ];
  }
  function matches(s, r, id) {
    const a = accounts(s).find((x) => x.id === id);
    return (
      !!a &&
      (r.scope === "all" ||
        (r.scope === "custom" && r.accounts.includes(id)) ||
        r.scope === a.kind)
    );
  }
  function rules(s, id, key) {
    return (s.securityStops || []).filter(
      (r) =>
        r.active &&
        matches(s, r, id) &&
        (r.actions.includes("app") || r.actions.includes(key)),
    );
  }
  function manage(e) {
    const u = e.actor();
    if (
      !u?.active ||
      !e.can("security.policies") ||
      !(u.role === "owner" || u.staffAccount === "@system")
    )
      throw Error("إدارة التوقيف متاحة لإدارة النظام صاحبة الصلاحية فقط");
  }
  P.addSecurityStop = function (d) {
    manage(this);
    if (!scopes.some((x) => x.id === d.scope)) throw Error("حدد نطاق التوقيف");
    const actions = fields
      .filter((x) => d.actions.includes(x.key))
      .map((x) => x.key);
    if (!actions.length) throw Error("اختر نوع التوقيف");
    const ids = [...new Set(d.accounts || [])];
    if (
      d.scope === "custom" &&
      (!ids.length ||
        ids.some((id) => !accounts(this.s).some((a) => a.id === id)))
    )
      throw Error("اختر حسابات صحيحة");
    const reason = String(d.reason || "").trim();
    if (!reason) throw Error("اكتب سبب التوقيف");
    const r = {
      id: M.id("STOP"),
      scope: d.scope,
      accounts: d.scope === "custom" ? ids : [],
      actions,
      reason,
      active: true,
      time: new Date().toISOString(),
      user: this.user,
    };
    (this.s.securityStops ??= []).unshift(r);
    this.log("تطبيق توقيف", r.id, null, r);
    return r;
  };
  P.resumeSecurityStop = function (id) {
    manage(this);
    const r = this.s.securityStops?.find((x) => x.id === id && x.active);
    if (!r) throw Error("قرار التوقيف غير موجود أو ملغى");
    const before = M.clone(r);
    r.active = false;
    r.resumedAt = new Date().toISOString();
    this.log("إلغاء توقيف", id, before, r);
  };
  const check = P.checkOperation;
  P.checkOperation = function (id, key) {
    const active = rules(this.s, id, key);
    if (active.length) throw Error("موقوف: " + active[0].reason);
    return check.call(this, id, key);
  };
  const requireOld = P.require;
  P.require = function (...args) {
    requireOld.apply(this, args);
    const u = this.actor();
    if (u.role !== "owner" && u.staffAccount !== "@system") {
      const r = rules(this.s, u.pos || u.agent, "login");
      if (r.length) throw Error("موقوف: " + r[0].reason);
    }
  };
  const component = {
    data() {
      return {
        scope: "all",
        actions: [],
        selected: [],
        reason: "",
        query: "",
        filter: "",
        page: 1,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      allowed() {
        return (
          this.vm.can("security.policies") &&
          (this.vm.actor.role === "owner" ||
            this.vm.actor.staffAccount === "@system")
        );
      },
      scopes() {
        return scopes;
      },
      fields() {
        return fields;
      },
      accounts() {
        return accounts(this.vm.s);
      },
      choices() {
        return this.accounts.filter(
          (a) =>
            !this.query ||
            a.name.includes(this.query) ||
            a.id.includes(this.query),
        );
      },
      preview() {
        return this.accounts.filter((a) =>
          matches(
            this.vm.s,
            { scope: this.scope, accounts: this.selected },
            a.id,
          ),
        ).length;
      },
      active() {
        return (this.vm.s.securityStops || []).filter((r) => r.active);
      },
      disabledAccounts() {
        return this.accounts
          .map((a) => ({
            ...a,
            stops: fields.filter((f) => {
              try {
                this.vm.engine.checkOperation(a.id, f.key);
                return f.key === "app"
                  ? !this.vm.s.settings.app
                  : f.key === "import"
                    ? false
                    : this.vm.s.settings[f.key] === false;
              } catch {
                return true;
              }
            }),
          }))
          .filter(
            (a) =>
              a.stops.length && (!this.filter || a.name.includes(this.filter)),
          );
      },
      rows() {
        return this.disabledAccounts.slice(
          (this.page - 1) * 10,
          this.page * 10,
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.disabledAccounts.length / 10));
      },
    },
    watch: {
      filter() {
        this.page = 1;
      },
      pages(v) {
        if (this.page > v) this.page = v;
      },
    },
    methods: {
      label(k) {
        return scopes.find((x) => x.id === k)?.label || k;
      },
      action(k) {
        return fields.find((x) => x.key === k)?.label || k;
      },
      apply() {
        this.vm.run(() => {
          this.vm.engine.addSecurityStop({
            scope: this.scope,
            actions: this.actions,
            accounts: this.selected,
            reason: this.reason,
          });
          this.vm.persist();
          this.reason = "";
          this.actions = [];
        }, "تم تطبيق التوقيف");
      },
      resume(r) {
        this.vm.run(() => {
          this.vm.engine.resumeSecurityStop(r.id);
          this.vm.persist();
        }, "تم إلغاء قرار التوقيف");
      },
      count(r) {
        return this.accounts.filter((a) => matches(this.vm.s, r, a.id)).length;
      },
      legacy(a) {
        return Object.values(a.operationStops || {}).some(Boolean);
      },
      clearLegacy(a) {
        this.vm.run(() => {
          this.vm.engine.setOperationStops(
            a.kind === "pos" ? "pos" : "agent",
            a.id,
            {},
          );
          this.vm.persist();
        }, "أزيل التوقيف المباشر؛ قد تبقى قيود أخرى");
      },
      restoreGlobal() {
        this.vm.run(() => {
          manage(this.vm.engine);
          for (const key of ["app", "login", "sales", "printing"]) {
            if (this.vm.s.settings[key] === false) {
              this.vm.engine.requirePermission("security." + key);
              this.vm.s.settings[key] = true;
            }
          }
          this.vm.engine.log(
            "إلغاء مفاتيح التوقيف العام السابقة",
            "settings",
            null,
            { restored: true },
          );
          this.vm.persist();
        }, "تم إلغاء التوقيف العام السابق");
      },
    },
  };
  root.MasalSecurityControls = {
    install(o) {
      o.components["operation-control"] = component;
    },
  };
})(globalThis);

(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  const labels = {
    failedRetries: "عدد محاولات إعادة الطباعة بعد الفشل",
    maxCards: "الحد الأقصى لعدد البطاقات في الطلب الواحد",
    intervalSeconds: "مدة الانتظار بين عمليتي طباعة — بالثواني",
  };
  function manage(e) {
    e.requirePermission("security.policies");
    if (e.actor().role !== "owner")
      throw Error("إدارة ضوابط الطباعة لمدير النظام فقط");
  }
  function score(e, target, id) {
    if (target.kind === "pos") return target.id === id ? 10000 : -1;
    if (target.kind === "agent") return target.id === id ? 9000 : -1;
    const account = e.s.pos.find((p) => p.id === id)?.agent || id;
    let a = e.s.agents.find((a) => a.id === account),
      distance = 0,
      seen = new Set();
    while (a && !seen.has(a.id)) {
      if (a.id === target.id) return 8000 - distance;
      seen.add(a.id);
      a = e.s.agents.find((x) => x.id === a.parent);
      distance++;
    }
    return -1;
  }
  const base = P.printPolicy;
  P.printPolicy = function (id, product) {
    let result = base.call(this, id),
      best = -1;
    for (const r of this.s.printPolicyRules || []) {
      if (
        !r.active ||
        (r.policy.dailyProductMode === "selected" &&
          !r.policy.dailyProducts?.includes(product))
      )
        continue;
      const rank =
        Math.max(-1, ...r.targets.map((t) => score(this, t, id))) +
        (r.policy.dailyProductMode === "selected" ? 0.5 : 0);
      if (rank > best && rank >= 0) {
        best = rank;
        result = { ...result, ...r.policy };
      }
    }
    const direct = this.s.pos.find((p) => p.id === id)?.printPolicy;
    return direct ? { ...result, ...direct } : result;
  };
  P.savePrintPolicyRule = function (d) {
    manage(this);
    const targets = (d.targets || []).map((t) => ({ kind: t.kind, id: t.id }));
    if (!targets.length) throw Error("اختر نطاقًا واحدًا على الأقل");
    const keys = targets.map((t) => t.kind + ":" + t.id);
    if (new Set(keys).size !== keys.length) throw Error("النطاق مكرر");
    for (const t of targets)
      if (
        !["pos", "agent", "tree"].includes(t.kind) ||
        !(t.kind === "pos" ? this.s.pos : this.s.agents).some(
          (x) => x.id === t.id,
        )
      )
        throw Error("نطاق غير صالح");
    const name = String(d.name || "").trim();
    if (!name) throw Error("اكتب اسم القاعدة");
    const policy = {};
    for (const k of Object.keys(labels)) {
      const n = Number(d.policy?.[k]);
      if (
        d.policy?.[k] === "" ||
        d.policy?.[k] == null ||
        !Number.isSafeInteger(n) ||
        n < (k === "maxCards" ? 1 : 0) ||
        n > 100000
      )
        throw Error("قيمة غير صالحة لضوابط الطباعة");
      policy[k] = n;
    }
    Object.assign(policy, MasalDailyPrint.validate(d.policy));
    if (
      policy.dailyProducts.some(
        (id) => !this.s.products.some((p) => p.id === id),
      )
    )
      throw Error("فئة غير موجودة");
    const rules = this.s.printPolicyRules || [],
      legacy = String(d.id || "").startsWith("legacy:") ? d.id.slice(7) : null,
      old = rules.find((r) => r.id === d.id);
    if (
      d.id &&
      !old &&
      !this.s.pos.some((p) => p.id === legacy && p.printPolicy)
    )
      throw Error("القاعدة غير موجودة");
    if (
      targets.some(
        (t) =>
          t.kind === "pos" &&
          t.id !== legacy &&
          this.s.pos.some((p) => p.id === t.id && p.printPolicy),
      )
    )
      throw Error("يوجد إعداد محفوظ لهذه النقطة؛ عدّله من النطاقات المحفوظة");
    const active = d.active === true || old?.active !== false;
    if (
      active &&
      rules.some(
        (r) =>
          r.id !== d.id &&
          r.active &&
          r.targets.some((t) => keys.includes(t.kind + ":" + t.id)) &&
          (r.policy.dailyProductMode === "selected") ===
            (policy.dailyProductMode === "selected") &&
          (policy.dailyProductMode !== "selected" ||
            r.policy.dailyProducts.some((id) =>
              policy.dailyProducts.includes(id),
            )),
      )
    )
      throw Error("يوجد إعداد مخصص لهذا النطاق؛ عدّل القاعدة الحالية");
    const next = {
      id: old?.id || Masal.id("PRINT-RULE"),
      name,
      targets,
      policy,
      active,
    };
    const before = Masal.clone(old || null);
    if (old) Object.assign(old, next);
    else (this.s.printPolicyRules ??= []).push(next);
    if (legacy) delete this.s.pos.find((p) => p.id === legacy).printPolicy;
    this.log("حفظ نطاق ضوابط الطباعة", next.id, before, next);
    return next;
  };
  P.disablePrintPolicyRule = function (id) {
    manage(this);
    if (id.startsWith("legacy:")) {
      const p = this.s.pos.find((p) => p.id === id.slice(7));
      if (!p?.printPolicy) throw Error("القاعدة غير موجودة");
      const before = Masal.clone(p.printPolicy),
        r = {
          id: Masal.id("PRINT-RULE"),
          name: p.name,
          targets: [{ kind: "pos", id: p.id }],
          policy: {
            dailyCards: 0,
            dailyMode: "account",
            dailyProductMode: "all",
            dailyProducts: [],
            ...before,
          },
          active: false,
        };
      (this.s.printPolicyRules ??= []).push(r);
      delete p.printPolicy;
      this.log("تعطيل نطاق ضوابط الطباعة", r.id, before, r);
      return r;
    }
    const r = this.s.printPolicyRules?.find((r) => r.id === id);
    if (!r) throw Error("القاعدة غير موجودة");
    if (!r.active) return r;
    const before = Masal.clone(r);
    r.active = false;
    this.log("تعطيل نطاق ضوابط الطباعة", id, before, r);
    return r;
  };
  P.enablePrintPolicyRule = function (id) {
    manage(this);
    const r = this.s.printPolicyRules?.find((r) => r.id === id);
    if (!r) throw Error("القاعدة غير موجودة");
    if (r.active) return r;
    const before = Masal.clone(r),
      saved = this.savePrintPolicyRule({ ...Masal.clone(r), active: true });
    this.log("تفعيل نطاق ضوابط الطباعة", id, before, saved);
    return saved;
  };
  const panel = {
    data() {
      return {
        mode: "general",
        ruleId: "",
        name: "",
        selected: [],
        query: "",
        draft: {},
        labels,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      choices() {
        return [
          ...this.vm.visibleAgents.flatMap((a) => [
            {
              key: "tree:" + a.id,
              kind: "tree",
              id: a.id,
              name:
                a.name +
                " — " +
                (a.parent ? "الفرع وكل تابعيه" : "الوكيل وكل فروعه ونقاطه"),
            },
            {
              key: "agent:" + a.id,
              kind: "agent",
              id: a.id,
              name: a.name + " — الحساب فقط",
            },
          ]),
          ...this.vm.visiblePOS.map((p) => ({
            key: "pos:" + p.id,
            kind: "pos",
            id: p.id,
            name: p.name + " — نقطة بيع",
          })),
        ];
      },
      filtered() {
        return this.choices.filter(
          (c) => !this.query || c.name.includes(this.query),
        );
      },
      rules() {
        return [
          ...(this.vm.s.printPolicyRules || []),
          ...this.vm.s.pos
            .filter((p) => p.printPolicy)
            .map((p) => ({
              id: "legacy:" + p.id,
              name: p.name,
              active: true,
              targets: [{ kind: "pos", id: p.id }],
              policy: p.printPolicy,
            })),
        ];
      },
    },
    mounted() {
      this.loadGeneral();
    },
    watch: {
      mode(v) {
        if (v === "general") this.loadGeneral();
      },
      "vm.currentUser"() {
        this.mode = "general";
        this.loadGeneral();
      },
    },
    methods: {
      loadGeneral() {
        this.draft = { ...this.vm.engine.printPolicy("") };
      },
      fresh() {
        this.mode = "custom";
        this.ruleId = "";
        this.name = "";
        this.selected = [];
        this.query = "";
        this.loadGeneral();
      },
      targetName(t) {
        return (
          this.choices.find((x) => x.key === t.kind + ":" + t.id)?.name || t.id
        );
      },
      edit(r) {
        this.mode = "custom";
        this.ruleId = r.id;
        this.name = r.name;
        this.selected = r.targets.map((t) => t.kind + ":" + t.id);
        this.draft = {
          dailyCards: 0,
          dailyMode: "account",
          dailyProductMode: "all",
          dailyProducts: [],
          ...Masal.clone(r.policy),
        };
      },
      save() {
        this.vm.run(() => {
          if (this.mode === "general")
            this.vm.engine.savePrintPolicy("", this.draft);
          else {
            const r = this.vm.engine.savePrintPolicyRule({
              id: this.ruleId,
              name: this.name,
              targets: this.selected
                .map((k) => this.choices.find((c) => c.key === k))
                .filter(Boolean),
              policy: this.draft,
            });
            this.ruleId = r.id;
          }
          this.vm.persist();
        }, "تم حفظ ضوابط الطباعة");
      },
      disable(r) {
        this.vm.run(() => {
          const saved = this.vm.engine.disablePrintPolicyRule(r.id);
          if (this.ruleId === r.id) this.ruleId = saved.id;
          this.vm.persist();
        }, "تم تعطيل القاعدة");
      },
      enable(r) {
        this.vm.run(() => {
          this.vm.engine.enablePrintPolicyRule(r.id);
          this.vm.persist();
        }, "تم تفعيل القاعدة");
      },
    },
  };
  root.MasalPrintPolicyScopes = {
    install(o) {
      o.components["print-policy-settings"] = panel;
    },
  };
})(globalThis);

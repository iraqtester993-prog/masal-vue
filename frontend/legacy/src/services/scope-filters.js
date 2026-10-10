(function (root) {
  "use strict";
  function agents(e) {
    if (e.actor().role === "owner") return e.s.agents;
    return e.actor().role === "pos"
      ? []
      : e.s.agents.filter((a) => e.allowed(a.id));
  }
  function points(e) {
    if (e.actor().role === "owner") return e.s.pos;
    return e.s.pos.filter(
      (p) =>
        e.allowed(p.agent) &&
        (e.actor().role !== "pos" || p.id === e.actor().pos),
    );
  }
  function cities(e) {
    return e.actor().role === "owner"
      ? [
          ...new Set(
            [
              ...e.s.governorates.map((g) => g.name),
              ...e.s.agents.map((a) => a.city),
              ...e.s.pos.map((p) => p.city),
            ].filter(Boolean),
          ),
        ]
      : [
          ...new Set(
            [...agents(e), ...points(e)].map((a) => a.city).filter(Boolean),
          ),
        ];
  }
  function products(e) {
    if (e.actor().role === "owner") return e.s.products;
    const accounts = [
      ...agents(e).map((a) => ({ agent: a.id, city: a.city })),
      ...points(e),
    ];
    const ids = new Set(points(e).map((p) => p.id));
    for (const a of agents(e)) ids.add(a.id);
    return e.s.products.filter(
      (p) =>
        accounts.some((a) => e.productAvailable(a.agent, p.id, a.city)) ||
        e.s.sales.some((t) => ids.has(t.pos) && t.product === p.id),
    );
  }
  function validate(e, f) {
    const valid = (v, rows) => !v || rows.some((r) => r.id === v);
    if (
      !valid(f.agent, agents(e)) ||
      !valid(f.pos, points(e)) ||
      (f.city && !cities(e).includes(f.city)) ||
      !valid(f.product, products(e)) ||
      (f.provider &&
        !(e.actor().role === "owner"
          ? e.s.providers.some((p) => p.id === f.provider)
          : products(e).some((p) => p.provider === f.provider)))
    )
      throw Error("اختيار الفلترة خارج نطاق حسابك");
  }
  const build = MasalReports.build;
  MasalReports.build = function (e, f = {}) {
    validate(e, f);
    return build(e, f);
  };
  root.MasalScopeFilters = {
    install(o) {
      Object.assign(o.computed, {
        scopeFilterAgents() {
          return agents(this.engine);
        },
        scopeFilterCities() {
          return cities(this.engine);
        },
        scopeFilterProducts() {
          return products(this.engine);
        },
        scopeFilterProviders() {
          if (this.actor.role === "owner") return this.s.providers;
          const ids = new Set(this.scopeFilterProducts.map((p) => p.provider));
          return this.s.providers.filter((p) => ids.has(p.id));
        },
        scopeFilterKinds() {
          const kinds = new Set(
            this.accounts.map((a) =>
              this.s.pos.some((p) => p.id === a.id)
                ? "pos"
                : a.type === "رئيسي"
                  ? "main"
                  : this.s.agents.some(
                        (p) => p.id === a.parent && p.type === "فرعي",
                      )
                    ? "subsub"
                    : "sub",
            ),
          );
          return [
            { id: "main", label: "وكيل رئيسي" },
            { id: "sub", label: "فرع" },
            { id: "subsub", label: "فرع فرعي" },
            { id: "pos", label: "نقطة بيع" },
          ].filter((k) => this.actor.role === "owner" || kinds.has(k.id));
        },
      });
      const open = o.methods.openReportSettings;
      o.methods.openReportSettings = function () {
        open.call(this);
        const d = this.modal.draft,
          a = this.scopeFilterAgents.find((a) => a.id === d.reportAgent);
        d.reportBranch = a && a.type !== "رئيسي" ? a.id : "";
        const main = a ? this.engine.main(a.id) : "";
        d.reportMain = this.scopeFilterAgents.some(
          (a) => a.id === main && a.type === "رئيسي",
        )
          ? main
          : "";
      };
      const apply = o.methods.applyReportSettings;
      o.methods.applyReportSettings = function () {
        const d = this.modal.draft;
        try {
          validate(this.engine, {
            agent: d.reportAgent,
            pos: d.reportPOS,
            city: d.reportCity,
            product: d.reportProduct,
            provider: d.reportProvider,
          });
        } catch (e) {
          this.notify(e.message, true);
          return;
        }
        return apply.call(this);
      };
      const panel = o.components["operations-panel"];
      for (const key of ["requests", "transfers", "invoices", "ledger"]) {
        const base = panel.computed[key];
        panel.computed[key] = function () {
          let rows = base.call(this);
          if (this.vm.actor.role !== "pos") return rows;
          const id = this.vm.actor.pos;
          if (key === "transfers") {
            const f = this.vm.walletFilters;
            rows = this.s.fundingTransfers.filter(
              (r) =>
                (r.from === id || r.to === id) &&
                r.service === this.service &&
                this.walletAccounts.some((a) => a.id === id) &&
                (!f.from || Masal.businessDay(r.time) >= f.from) &&
                (!f.to || Masal.businessDay(r.time) <= f.to) &&
                (!f.reference ||
                  [r.id, r.reference, r.group, r.purpose].some((v) =>
                    String(v || "")
                      .toLowerCase()
                      .includes(f.reference.toLowerCase()),
                  )),
            );
          }
          return rows.filter((r) =>
            key === "ledger"
              ? r.account === id
              : key === "invoices"
                ? false
                : r.from === id || r.to === id,
          );
        };
      }
      // Never carry another account's selection into a switched session.
      const reset = panel.methods.reset;
      panel.methods.reset = function (...args) {
        const result = reset.apply(this, args);
        this.account = "";
        this.tab = "balances";
        this.fundingPreview = null;
        return result;
      };
    },
  };
})(window);

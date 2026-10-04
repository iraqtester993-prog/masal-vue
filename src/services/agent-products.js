(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  P.agentProductAllowed = function (agent, product) {
    initialize(this.s);
    const a = this.s.agents.find((a) => a.id === this.main(agent));
    return (
      !!a &&
      this.s.products.some((p) => p.id === product) &&
      Array.isArray(a.allowedProductIds) &&
      a.allowedProductIds.includes(product)
    );
  };
  P.catalogProductVisible = function (product) {
    const u = this.actor(),
      account =
        u.staffAccount && u.staffAccount !== "@system"
          ? u.staffAccount
          : u.agent;
    return !account || this.agentProductAllowed(account, product);
  };
  P.productAvailable = function (agent, product, city) {
    const p = this.s.products.find((p) => p.id === product);
    return (
      this.agentProductAllowed(agent, product) &&
      !!p?.active &&
      !!this.s.providers.find((v) => v.id === p.provider)?.active &&
      (!p.allowedCities?.length || p.allowedCities.includes(city))
    );
  };
  for (const key of ["validateImport", "importBatch"]) {
    const base = P[key];
    P[key] = function (meta, ...args) {
      if (!this.agentProductAllowed(meta.agent, meta.product))
        throw Error("الفئة غير مسموحة لهذا الوكيل");
      return base.call(this, meta, ...args);
    };
  }
  const policy = P.savePricePolicy;
  P.savePricePolicy = function (draft) {
    if (!this.agentProductAllowed(draft.agent, draft.product))
      throw Error("الفئة غير مسموحة لهذا الوكيل");
    return policy.call(this, draft);
  };
  const propose = P.proposePrices;
  P.proposePrices = function (changes) {
    if (changes.some((c) => !this.agentProductAllowed(c.agent, c.product)))
      throw Error("الفئة غير مسموحة لهذا الوكيل");
    return propose.call(this, changes);
  };
  const preview = P.previewPriceEdit;
  P.previewPriceEdit = function (agent, changes) {
    if (changes.some((c) => !this.agentProductAllowed(agent, c.product)))
      throw Error("الفئة غير مسموحة لهذا الوكيل");
    return preview.call(this, agent, changes);
  };
  function initialize(s) {
    for (const a of s.agents.filter((a) => a.type === "رئيسي"))
      if (!Array.isArray(a.allowedProductIds)) {
        const e = new Masal.Engine(s, s.users[0]?.id);
        a.allowedProductIds = s.products
          .filter(
            (p) =>
              !p.allowedAgents?.length ||
              p.allowedAgents.some((id) => e.main(id) === a.id),
          )
          .map((p) => p.id);
      }
  }
  const picker = {
    props: {
      readonly: Boolean,
      agent: Object,
      field: { type: String, default: "allowedProductIds" },
    },
    data: () => ({
      query: "",
      provider: "",
      selectedOnly: false,
      page: 1,
      draft: [],
      opened: false,
    }),
    computed: {
      vm() {
        return this.$root;
      },
      record() {
        return this.agent || this.vm.editForm;
      },
      saved() {
        return this.record[this.field] || [];
      },
      ids() {
        return this.readonly ? this.saved : this.draft;
      },
      selectedSet() {
        return new Set(this.ids);
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return this.vm.s.products.filter(
          (p) =>
            (!this.readonly || this.selectedSet.has(p.id)) &&
            (!this.selectedOnly || this.selectedSet.has(p.id)) &&
            (!this.provider || p.provider === this.provider) &&
            (!q ||
              [p.name, p.id, p.face, this.vm.nameOf("providers", p.provider)]
                .join(" ")
                .toLowerCase()
                .includes(q)),
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.rows.length / 25));
      },
      shown() {
        return this.rows.slice((this.page - 1) * 25, this.page * 25);
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      provider() {
        this.page = 1;
      },
      selectedOnly() {
        this.page = 1;
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
      "vm.currentUser"() {
        this.close();
      },
    },
    methods: {
      open() {
        this.draft = [...this.saved];
        this.query = "";
        this.provider = "";
        this.selectedOnly = false;
        this.page = 1;
        this.opened = true;
        this._focus = document.activeElement;
        this.$nextTick(() => {
          this.$refs.dialog.showModal();
          this.$refs.search.focus();
        });
      },
      close() {
        this.$refs.dialog?.close();
        this.opened = false;
        this._focus?.focus();
      },
      confirm() {
        if (this.vm.actor.role !== "owner") return;
        this.record[this.field] = [...this.draft];
        this.close();
      },
      toggle(id, checked) {
        const ids = new Set(this.draft);
        checked ? ids.add(id) : ids.delete(id);
        this.draft = [...ids];
      },
      selectResults() {
        this.draft = [
          ...new Set([...this.draft, ...this.rows.map((p) => p.id)]),
        ];
      },
      clearResults() {
        const ids = new Set(this.rows.map((p) => p.id));
        this.draft = this.draft.filter((id) => !ids.has(id));
      },
    },
  };
  function install(o) {
    o.components["agent-product-picker"] = picker;
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      initialize(d.s);
      return d;
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      this.$watch(
        () => this.s,
        (s) => initialize(s),
        { flush: "sync" },
      );
    };
    const open = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      const result = open.call(this, row);
      if (
        this.page === "agents" &&
        this.editForm.type === "رئيسي" &&
        this.modal?.kind === "edit" &&
        this.actor.role === "owner"
      )
        this.editForm.allowedProductIds = Array.isArray(row?.allowedProductIds)
          ? [...row.allowedProductIds]
          : row
            ? this.s.products.map((p) => p.id)
            : [];
      return result;
    };
    o.methods.previewAgentProducts = function (a) {
      this.run(() => {
        this.engine.requirePermission("agents.view");
        this.engine.require(a.id);
        this.modal = {
          kind: "agentProducts",
          title: "الفئات — " + a.name,
          agent: a,
        };
      });
    };
    const rows = o.computed.filteredRows;
    o.computed.filteredRows = function () {
      const result = rows.call(this);
      return this.page === "products"
        ? result.filter((p) => this.engine.catalogProductVisible(p.id))
        : result;
    };
    const options = o.methods.optionsFor;
    o.methods.optionsFor = function (f) {
      const result = options.call(this, f);
      return f.options === "products"
        ? result.filter((p) => this.engine.catalogProductVisible(p.value))
        : result;
    };
    for (const key of ["visibleCards", "visibleBatches"]) {
      const base = o.computed[key];
      o.computed[key] = function () {
        return base
          .call(this)
          .filter((r) => this.engine.catalogProductVisible(r.product));
      };
    }
    const price = o.components["price-editor"];
    const products = price.computed.products;
    price.computed.products = function () {
      return products
        .call(this)
        .filter((p) => this.e.agentProductAllowed(this.vm.priceAgent, p.id));
    };
    const targets = price.computed.targetIds;
    price.computed.targetIds = function () {
      return targets
        .call(this)
        .filter((id) => this.e.agentProductAllowed(this.vm.priceAgent, id));
    };
    const cash = o.components["cash-order-form"],
      cashProducts = cash.computed.products;
    cash.computed.products = function () {
      return cashProducts
        .call(this)
        .filter((p) =>
          this.vm.engine.agentProductAllowed(this.draft.agent, p.id),
        );
    };
  }
  root.MasalAgentProducts = { install, initialize };
})(globalThis);

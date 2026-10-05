(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  function agentPath(s, id) {
    const path = [],
      seen = new Set();
    while (id) {
      if (seen.has(id)) return null;
      seen.add(id);
      const a = s.agents.find((a) => a.id === id);
      if (!a) return null;
      path.push(a);
      id = a.parent;
    }
    return path;
  }
  function nodeAllows(node, product) {
    return (
      (!Array.isArray(node.allowedProductIds) ||
        node.allowedProductIds.includes(product)) &&
      Object.values(node.productRules || {}).every(
        (ids) => Array.isArray(ids) && ids.includes(product),
      )
    );
  }
  P.agentProductAllowed = function (agent, product) {
    initialize(this.s);
    const path = agentPath(this.s, agent);
    return (
      !!path?.length &&
      this.s.products.some((p) => p.id === product) &&
      path.every((a) => nodeAllows(a, product))
    );
  };
  P.posProductAllowed = function (pos, product) {
    const point = this.s.pos.find((p) => p.id === pos);
    return (
      !!point &&
      this.agentProductAllowed(point.agent, product) &&
      nodeAllows(point, product)
    );
  };
  P.catalogProductVisible = function (product) {
    const u = this.actor();
    if (u.role === "pos") return this.posProductAllowed(u.pos, product);
    const account =
      u.staffAccount && u.staffAccount !== "@system" ? u.staffAccount : u.agent;
    return !account || this.agentProductAllowed(account, product);
  };
  function categoryTarget(e, page, id) {
    e.requirePermission("agents.categories");
    if (
      !["agents", "pos"].includes(page) ||
      !["owner", "main", "sub"].includes(e.actor().role)
    )
      throw Error("إدارة الفئات متاحة للمسؤول عن الشبكة");
    const record = e.s[page].find((r) => r.id === id);
    if (!record || record.archivedAt) throw Error("الحساب غير متاح");
    const path = agentPath(e.s, page === "pos" ? record.agent : record.id);
    if (!path?.length) throw Error("تبعية غير صالحة");
    if (
      e.actor().role !== "owner" &&
      (!path.some((a) => a.id === e.actor().agent) ||
        (page === "agents" && record.id === e.actor().agent))
    )
      throw Error("يمكن تحديد فئات التابعين فقط");
    e.require(page === "pos" ? record.agent : record.id);
    return { record, path };
  }
  function categoryRules(e, target, ids) {
    const authority = e.actor().role === "owner" ? "@owner" : e.actor().agent,
      rules = Masal.clone(target.record.productRules || {});
    const rank = (id) =>
        id === "@owner" ? Infinity : target.path.findIndex((a) => a.id === id),
      level = rank(authority);
    for (const id of Object.keys(rules))
      if (rank(id) <= level) delete rules[id];
    rules[authority] = [...ids];
    return rules;
  }
  P.networkCategoryOptions = function (page, id) {
    const target = categoryTarget(this, page, id),
      authority = this.actor().role === "owner" ? "@owner" : this.actor().agent;
    const candidate = {
      ...target.record,
      productRules: categoryRules(
        this,
        target,
        this.s.products.map((p) => p.id),
      ),
    };
    // Existing main-agent assignments are editable by the owner; all upstream limits remain live.
    if (
      page === "agents" &&
      candidate.type === "رئيسي" &&
      authority === "@owner"
    )
      candidate.allowedProductIds = this.s.products.map((p) => p.id);
    const state = {
        ...this.s,
        [page]: this.s[page].map((r) => (r.id === id ? candidate : r)),
      },
      e = new Masal.Engine(state, this.user);
    return this.s.products
      .filter(
        (p) =>
          (page === "pos"
            ? e.posProductAllowed(id, p.id)
            : e.agentProductAllowed(id, p.id)) &&
          (authority === "@owner" || this.agentProductAllowed(authority, p.id)),
      )
      .map((p) => p.id);
  };
  P.saveNetworkCategories = function (page, id, ids) {
    const target = categoryTarget(this, page, id);
    if (!Array.isArray(ids) || new Set(ids).size !== ids.length)
      throw Error("اختيار فئات غير صالح");
    const allowed = new Set(this.networkCategoryOptions(page, id));
    if (ids.some((id) => !allowed.has(id)))
      throw Error("لا يمكنك منح فئة غير متاحة لك أو ممنوعة من الأعلى");
    const before = {
      allowedProductIds: Masal.clone(target.record.allowedProductIds || null),
      productRules: Masal.clone(target.record.productRules || {}),
    };
    if (
      page === "agents" &&
      target.record.type === "رئيسي" &&
      this.actor().role === "owner"
    ) {
      target.record.allowedProductIds = [...ids];
      target.record.productRules = {};
    } else target.record.productRules = categoryRules(this, target, ids);
    this.log("تحديد فئات تابع", id, before, {
      allowedProductIds: target.record.allowedProductIds,
      productRules: target.record.productRules,
    });
    return target.record;
  };
  for (const method of ["sell", "reserve"]) {
    const base = P[method];
    P[method] = function (id, product, ...args) {
      const point = this.s.pos.find((p) => p.id === id);
      if (point && !this.posProductAllowed(id, product))
        throw Error("الفئة غير مسموحة لنقطة البيع");
      if (!point && !this.agentProductAllowed(id, product))
        throw Error("الفئة غير مسموحة لهذا الفرع");
      return base.call(this, id, product, ...args);
    };
  }
  const issue = P.issueReservation;
  P.issueReservation = function (id, ...args) {
    const r = this.s.reservations.find((r) => r.id === id);
    if (r) {
      const point = this.s.pos.find((p) => p.id === r.pos);
      if (
        !(point
          ? this.posProductAllowed(r.pos, r.product)
          : this.agentProductAllowed(r.pos, r.product))
      )
        throw Error("الفئة لم تعد مسموحة لحساب البيع");
    }
    return issue.call(this, id, ...args);
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
      inline: Boolean,
      availableIds: Array,
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
        return this.readonly || this.inline ? this.saved : this.draft;
      },
      selectedSet() {
        return new Set(this.ids);
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return this.vm.s.products.filter(
          (p) =>
            (!this.availableIds || this.availableIds.includes(p.id)) &&
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
        this.$refs.dialog?.close?.();
        this.opened = false;
        this._focus?.focus();
      },
      confirm() {
        if (this.vm.actor.role !== "owner") return;
        this.record[this.field] = [...this.draft];
        this.close();
      },
      toggle(id, checked) {
        const ids = new Set(this.ids);
        checked ? ids.add(id) : ids.delete(id);
        if (this.inline) this.record[this.field] = [...ids];
        else this.draft = [...ids];
      },
      selectResults() {
        const ids = [...new Set([...this.ids, ...this.rows.map((p) => p.id)])];
        if (this.inline) this.record[this.field] = ids;
        else this.draft = ids;
      },
      clearResults() {
        const ids = new Set(this.rows.map((p) => p.id)),
          next = this.ids.filter((id) => !ids.has(id));
        if (this.inline) this.record[this.field] = next;
        else this.draft = next;
      },
    },
  };
  function install(o) {
    o.components["agent-product-picker"] = picker;
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      initialize(d.s);
      return { ...d, networkCategoryDraft: { allowedProductIds: [] } };
    };
    o.methods.canManageCategories = function (page, id) {
      try {
        categoryTarget(this.engine, page, id);
        return true;
      } catch {
        return false;
      }
    };
    o.methods.openNetworkCategories = function (page, id) {
      this.run(() => {
        const { record } = categoryTarget(this.engine, page, id);
        this.networkCategoryDraft = {
          allowedProductIds: this.s.products
            .filter((p) =>
              page === "pos"
                ? this.engine.posProductAllowed(id, p.id)
                : this.engine.agentProductAllowed(id, p.id),
            )
            .map((p) => p.id),
        };
        this.modal = {
          kind: "networkCategories",
          title: "الفئات — " + record.name,
          page,
          id,
        };
      });
    };
    o.computed.networkCategoryAvailableIds = function () {
      return this.modal?.kind === "networkCategories"
        ? this.engine.networkCategoryOptions(this.modal.page, this.modal.id)
        : [];
    };
    o.methods.saveNetworkCategories = function () {
      this.run(() => {
        this.engine.saveNetworkCategories(
          this.modal.page,
          this.modal.id,
          this.networkCategoryDraft.allowedProductIds,
        );
        this.persist();
        this.closeModal();
      }, "تم حفظ فئات التابع");
    };
    const saleProducts = o.computed.availableSaleProducts;
    o.computed.availableSaleProducts = function () {
      return saleProducts
        .call(this)
        .filter(
          (p) =>
            !this.selectedPOS ||
            !this.s.pos.some((point) => point.id === this.selectedPOS.id) ||
            this.engine.posProductAllowed(this.selectedPOS.id, p.id),
        );
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
          agent: {
            ...a,
            allowedProductIds: this.s.products
              .filter((p) => this.engine.agentProductAllowed(a.id, p.id))
              .map((p) => p.id),
          },
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
  root.MasalAgentProducts = { install, initialize, categoryTarget };
})(globalThis);

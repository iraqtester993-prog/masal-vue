(function (root) {
  "use strict";
  const P = Masal.Engine.prototype,
    round = (v) => Math.round(Number(v) * 100) / 100;
  function requireAgent(e, agent) {
    e.requirePermission("prices.propose");
    e.require(agent);
    if (
      !e.s.agents.some(
        (a) => a.id === agent && a.active && e.main(a.id) === a.id,
      )
    )
      throw Error("اختر وكيلًا رئيسيًا فعالًا ضمن نطاقك");
  }
  P.previewPriceEdit = function (agent, changes) {
    requireAgent(this, agent);
    if (!Array.isArray(changes) || !changes.length)
      throw Error("لا توجد فئات محددة للتعديل");
    const seen = new Set(),
      rows = changes.map((c) => {
        const p = this.s.products.find((p) => p.id === c.product);
        if (!p || seen.has(c.product)) throw Error("فئة غير موجودة أو مكررة");
        seen.add(c.product);
        const price = Number(c.price),
          old = this.policyPrice(agent, p.id);
        let error = "";
        if (!Number.isFinite(price) || price <= 0 || round(price) !== price)
          error = "أدخل سعرًا موجبًا بمنزلتين عشريتين كحد أقصى";
        else if (price < Number(p.min || 0))
          error = "السعر أقل من الحد المسموح";
        if (c.requiresCurrent && !(old > 0))
          error = "لا يوجد سعر حالي؛ حدده فرديًا أولًا";
        return {
          product: p.id,
          name: p.name,
          old,
          price,
          difference: round(price - old),
          error,
        };
      });
    return {
      agent,
      user: this.user,
      key: Masal.id("PRICE-EDIT"),
      rows,
      valid: rows.every((r) => !r.error) && rows.some((r) => r.price !== r.old),
    };
  };
  P.commitPriceEdit = function (preview) {
    if (!preview || preview.user !== this.user || !preview.key)
      throw Error("تغير المستخدم؛ أعد المعاينة");
    requireAgent(this, preview.agent);
    const rows = preview.rows.map((r) => ({
        product: r.product,
        price: r.price,
        old: r.old,
      })),
      payload = JSON.stringify({ agent: preview.agent, rows });
    const previous = this.s.priceRequests.find(
      (r) => r.priceEditKey === preview.key,
    );
    if (previous) {
      if (
        previous.creator !== this.user ||
        previous.priceEditPayload !== payload
      )
        throw Error("معرف المعاينة مستخدم لبيانات مختلفة");
      return previous;
    }
    const checked = this.previewPriceEdit(preview.agent, rows);
    if (!checked.valid)
      throw Error(
        checked.rows.find((r) => r.error)?.error || "لا يوجد تغيير في الأسعار",
      );
    if (checked.rows.some((r, i) => r.old !== rows[i].old))
      throw Error("تغير أحد الأسعار منذ المعاينة؛ أعد المعاينة");
    return MasalMeetingRules.atomic(this, () => {
      const r = this.proposePrices(
        checked.rows
          .filter((r) => r.old !== r.price)
          .map((r) => ({
            agent: preview.agent,
            product: r.product,
            price: r.price,
          })),
      );
      r.priceEditKey = preview.key;
      r.priceEditPayload = payload;
      return r;
    });
  };
  const component = {
    data: () => ({
      mode: "individual",
      previewMode: "individual",
      query: "",
      provider: "",
      priceState: "",
      scope: "filtered",
      direction: "add",
      amount: "",
      selected: [],
      preview: null,
      busy: false,
      error: "",
    }),
    computed: {
      vm() {
        return this.$root;
      },
      e() {
        return this.vm.engine;
      },
      agents() {
        return this.vm.visibleAgents.filter(
          (a) => a.active && this.e.main(a.id) === a.id,
        );
      },
      products() {
        const f = this.vm.catalogFilters.prices,
          q = f.query.trim().toLowerCase();
        return this.vm.s.products.filter((p) => {
          const price = this.vm.s.prices
              .filter(
                (x) => x.agent === this.vm.priceAgent && x.product === p.id,
              )
              .sort((a, b) =>
                String(b.effective || "").localeCompare(
                  String(a.effective || ""),
                ),
              )[0],
            effective = price?.effective || "";
          return (
            (!f.product || p.id === f.product) &&
            (!f.provider || p.provider === f.provider) &&
            (!f.state ||
              (f.state === "active"
                ? p.active
                : f.state === "inactive"
                  ? !p.active
                  : f.state === "priced"
                    ? this.e.policyPrice(this.vm.priceAgent, p.id) > 0
                    : !(this.e.policyPrice(this.vm.priceAgent, p.id) > 0))) &&
            (!f.from || (effective && effective >= f.from)) &&
            (!f.to || (effective && effective <= f.to)) &&
            (!q ||
              (p.name + " " + (p.face ?? "") + " " + p.id)
                .toLowerCase()
                .includes(q))
          );
        });
      },
      filtersActive() {
        return this.vm.catalogFiltersActive("prices");
      },
      canEdit() {
        return this.vm.can("prices.propose");
      },
      draftCount() {
        return Object.values(this.vm.priceDraft).filter(
          (v) => v !== "" && v != null,
        ).length;
      },
      targetIds() {
        return this.scope === "all"
          ? this.vm.s.products.map((p) => p.id)
          : this.scope === "selected"
            ? this.selected
            : this.products.map((p) => p.id);
      },
    },
    mounted() {
      this.ensureAgent();
    },
    watch: {
      "vm.currentUser"() {
        this.clear();
        this.vm.priceDraft = {};
        this.ensureAgent();
      },
      "vm.priceAgent"() {
        this.clear();
      },
      preview(value) {
        if (value)
          this.$nextTick(() => {
            const d = this.$refs.review;
            if (d && !d.open) {
              this._focus = document.activeElement;
              d.showModal();
              d.focus();
            }
          });
        else
          this.$nextTick(() => this._focus?.isConnected && this._focus.focus());
      },
    },
    methods: {
      ensureAgent() {
        if (!this.agents.some((a) => a.id === this.vm.priceAgent))
          this.vm.priceAgent = this.agents[0]?.id || "";
      },
      clear() {
        this.preview = null;
        this.selected = [];
        this.amount = "";
        this.error = "";
      },
      close() {
        if (!this.busy) this.preview = null;
      },
      async importFile(event) {
        const user = this.vm.currentUser;
        await this.vm.importPrices(event);
        if (this.vm.currentUser === user) this.mode = "individual";
      },
      switchMode(mode) {
        if (this.busy || this.vm.priceImportBusy) return;
        this.mode = mode;
        this.error = "";
      },
      clearFilters() {
        this.vm.clearCatalogFilters("prices");
      },
      prepare(mode) {
        if (this.vm.priceImportBusy || this.busy) return;
        this.error = "";
        try {
          let changes;
          if (mode === "bulk") {
            const delta = Number(this.amount);
            if (!Number.isFinite(delta) || delta <= 0 || round(delta) !== delta)
              throw Error(
                "أدخل مبلغ زيادة أو نقصان موجبًا بمنزلتين عشريتين كحد أقصى",
              );
            if (!["add", "subtract"].includes(this.direction))
              throw Error("اختر زيادة أو نقصان");
            changes = this.targetIds.map((product) => ({
              product,
              price: round(
                this.e.policyPrice(this.vm.priceAgent, product) +
                  (this.direction === "add" ? delta : -delta),
              ),
              requiresCurrent: true,
            }));
          } else
            changes = Object.entries(this.vm.priceDraft)
              .filter(([, v]) => v !== "" && v != null)
              .map(([product, price]) => ({ product, price: Number(price) }));
          this.previewMode = mode === "bulk" ? "bulk" : "individual";
          this.preview = this.e.previewPriceEdit(this.vm.priceAgent, changes);
        } catch (e) {
          this.error = e.message;
        }
      },
      async confirm() {
        if (this.busy || !this.preview?.valid) return;
        this.busy = true;
        this.error = "";
        try {
          if (this.preview.agent !== this.vm.priceAgent)
            throw Error("تغير الوكيل؛ أعد المعاينة");
          const result = this.e.commitPriceEdit(this.preview);
          if (this.previewMode === "individual") this.vm.priceDraft = {};
          this.clear();
          this.vm.notify(
            result.status === "معتمد"
              ? "تم تطبيق الأسعار"
              : "أُرسلت الأسعار للاعتماد",
          );
          this.vm.persist();
        } catch (e) {
          this.error = e.message;
        } finally {
          await this.$nextTick();
          this.busy = false;
        }
      },
    },
  };
  const history = {
    data: () => ({ query: "", state: "", from: "", to: "" }),
    computed: {
      vm() {
        return this.$root;
      },
      showActions() {
        return this.rows.some(
          (c) =>
            (c.request.status === "قيد المراجعة" &&
              this.vm.can("prices.approve")) ||
            (c.request.status === "معتمد" && this.vm.can("prices.reverse")),
        );
      },
      rows() {
        const q = this.query.trim().toLowerCase(),
          f = this.vm.catalogFilters.prices;
        return this.vm.visiblePriceRequests
          .flatMap((r) =>
            r.changes.map((c, i) => ({
              ...c,
              key: r.id + "-" + i,
              request: r,
            })),
          )
          .filter(
            (c) =>
              (!this.vm.priceAgent || c.agent === this.vm.priceAgent) &&
              (!f.product || c.product === f.product) &&
              (!f.provider ||
                this.vm.s.products.find((p) => p.id === c.product)?.provider ===
                  f.provider) &&
              (!this.state || c.request.status === this.state) &&
              (!this.from || Masal.businessDay(c.request.time) >= this.from) &&
              (!this.to || Masal.businessDay(c.request.time) <= this.to) &&
              (!f.from || Masal.businessDay(c.request.time) >= f.from) &&
              (!f.to || Masal.businessDay(c.request.time) <= f.to) &&
              (!q ||
                [
                  c.request.id,
                  this.vm.nameOf("products", c.product),
                  this.vm.nameOf("agents", c.agent),
                  this.vm.nameOf("users", c.request.creator),
                ]
                  .join(" ")
                  .toLowerCase()
                  .includes(q)),
          )
          .sort(
            (a, b) => Date.parse(b.request.time) - Date.parse(a.request.time),
          );
      },
    },
  };
  function install(o) {
    component.components = {
      ...component.components,
      "catalog-filter-bar": o.components["catalog-filter-bar"],
    };
    o.components["price-editor"] = component;
    o.components["price-history"] = history;
  }
  root.MasalPriceEditor = { install };
})(globalThis);

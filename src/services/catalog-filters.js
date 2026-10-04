(function (root) {
  "use strict";
  const empty = (page) =>
    page === "products"
      ? { query: "", provider: "", kind: "", state: "", from: "", to: "" }
      : page === "providers"
        ? {
            query: "",
            supplier: "",
            connection: "",
            state: "",
            from: "",
            to: "",
          }
        : page === "prices"
          ? {
              query: "",
              agent: "",
              product: "",
              provider: "",
              state: "",
              from: "",
              to: "",
            }
          : {
              query: "",
              agent: "",
              product: "",
              status: "",
              tab: "all",
              from: "",
              to: "",
            };
  const active = (f) => Object.values(f || {}).some(Boolean);
  const bar = {
    props: ["page"],
    data() {
      return { opened: false, draft: null };
    },
    computed: {
      vm() {
        return this.$root;
      },
      filtersActive() {
        return this.vm.catalogFiltersActive(this.page);
      },
      providers() {
        return this.vm.s.providers;
      },
      products() {
        return this.vm.s.products;
      },
      agents() {
        return this.vm.inventoryAgents;
      },
      priceAgents() {
        return this.vm.visibleAgents.filter(
          (a) => a.active && this.vm.engine.main(a.id) === a.id,
        );
      },
      kinds() {
        return [...new Set(this.products.map((p) => p.kind).filter(Boolean))];
      },
      suppliers() {
        return [
          ...new Set(this.providers.map((p) => p.supplier).filter(Boolean)),
        ];
      },
      connections() {
        return [
          ...new Set(this.providers.map((p) => p.connection).filter(Boolean)),
        ];
      },
      label() {
        return (
          {
            inventory: "المخزون",
            products: "الفئات",
            providers: "الشركات",
            prices: "الأسعار",
          }[this.page] || ""
        );
      },
      resultCount() {
        return this.page === "inventory"
          ? this.vm.batchRows.length
          : this.vm.filteredRows.length;
      },
    },
    created() {
      this.resetDraft();
    },
    watch: {
      "vm.currentUser"() {
        this.resetDraft();
      },
      "vm.catalogFilters": {
        deep: true,
        handler() {
          if (this.opened) this.resetDraft();
        },
      },
      "vm.inventoryAgent"() {
        if (this.opened && this.page === "inventory") this.resetDraft();
      },
    },
    methods: {
      resetDraft() {
        this.draft =
          this.page === "inventory"
            ? {
                query: this.vm.search,
                agent: this.vm.inventoryAgent,
                product: this.vm.inventoryProduct,
                status: this.vm.statusFilter,
                tab: this.vm.inventoryTab,
                from: this.vm.inventoryDateFrom || "",
                to: this.vm.inventoryDateTo || "",
              }
            : Masal.clone(
                this.vm.catalogFilters[this.page] || empty(this.page),
              );
        if (this.page === "prices" && !this.draft.agent)
          this.draft.agent = this.vm.priceAgent;
      },
      apply() {
        if (
          this.draft.from &&
          this.draft.to &&
          this.draft.from > this.draft.to
        ) {
          this.vm.notify("تاريخ البداية يجب أن يسبق تاريخ النهاية", true);
          return;
        }
        if (this.page === "inventory") {
          this.vm.search = this.draft.query;
          this.vm.inventoryAgent = this.draft.agent;
          this.vm.inventoryProduct = this.draft.product;
          this.vm.statusFilter = this.draft.status;
          this.vm.inventoryTab = this.draft.status ? "all" : this.draft.tab;
          this.vm.inventoryDateFrom = this.draft.from;
          this.vm.inventoryDateTo = this.draft.to;
          this.vm.dashboardFilter = null;
        } else {
          this.vm.catalogFilters[this.page] = Masal.clone(this.draft);
          if (this.page === "prices" && this.draft.agent)
            this.vm.priceAgent = this.draft.agent;
        }
        this.opened = false;
      },
      clear() {
        this.draft = empty(this.page);
        this.apply();
      },
      toggle() {
        if (!this.opened) this.resetDraft();
        this.opened = !this.opened;
      },
      clearActive() {
        if (this.page === "inventory") this.vm.clearInventoryFilters();
        else this.vm.clearCatalogFilters(this.page);
        this.resetDraft();
      },
    },
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      return {
        ...d,
        catalogFilters: {
          products: empty("products"),
          providers: empty("providers"),
          prices: empty("prices"),
        },
      };
    };
    o.components["catalog-filter-bar"] = bar;
    o.methods.emptyCatalogFilters = empty;
    for (const name of ["category-table", "provider-table"]) {
      const component = o.components[name];
      component.components = {
        ...component.components,
        "catalog-filter-bar": bar,
        "page-actions": o.components["page-actions"],
      };
    }
    o.methods.catalogFiltersActive = function (page) {
      if (page === "inventory")
        return !!(
          this.inventoryAgent ||
          this.inventoryProduct ||
          this.statusFilter ||
          this.search ||
          this.inventoryTab !== "all" ||
          this.inventoryDateFrom ||
          this.inventoryDateTo
        );
      const f = this.catalogFilters[page];
      return page === "prices"
        ? !!(
            f &&
            (f.query || f.product || f.provider || f.state || f.from || f.to)
          )
        : active(f);
    };
    o.methods.clearCatalogFilters = function (page) {
      if (page === "inventory") {
        this.clearInventoryFilters();
        return;
      }
      this.catalogFilters[page] = empty(page);
      if (["products", "providers"].includes(page)) this.search = "";
    };
    const filtered = o.computed.filteredRows;
    o.computed.filteredRows = function () {
      let rows = filtered.call(this),
        f = this.catalogFilters?.[this.page];
      if (!f || !["products", "providers"].includes(this.page)) return rows;
      const q = f.query.trim().toLowerCase(),
        recordDay = (r) => {
          const audit = this.s.audit.find(
              (a) => a.entity === r.id && a.action === "إنشاء سجل",
            ),
            time = r.createdAt || r.created || r.time || audit?.time;
          return time ? Masal.businessDay(time) : "";
        };
      return rows.filter((r) => {
        const day = recordDay(r),
          hasDate = !!(f.from || f.to);
        return (
          (!q ||
            Object.values(r).some((v) =>
              String(v ?? "")
                .toLowerCase()
                .includes(q),
            )) &&
          (this.page !== "products" ||
            ((!f.provider || r.provider === f.provider) &&
              (!f.kind || r.kind === f.kind))) &&
          (this.page !== "providers" ||
            ((!f.supplier || r.supplier === f.supplier) &&
              (!f.connection || r.connection === f.connection))) &&
          (!f.state || !!r.active === (f.state === "active")) &&
          (!hasDate || !!day) &&
          (!f.from || day >= f.from) &&
          (!f.to || day <= f.to)
        );
      });
    };
  }
  root.MasalCatalogFilters = { install, empty };
})(globalThis);

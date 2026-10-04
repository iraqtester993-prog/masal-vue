(function (root) {
  "use strict";
  const definitions = [
    {
      id: "sales",
      title: "المبيعات والأرباح",
      icon: "reports",
      prefixes: ["sales", "prices"],
      note: "المبيعات والطباعة والأسعار",
    },
    {
      id: "wallets",
      title: "المحافظ والتمويل",
      icon: "wallets",
      prefixes: ["wallets"],
      note: "الأرصدة والتمويل والتحصيل",
    },
    {
      id: "inventory",
      title: "البطاقات والمخزون",
      icon: "inventory",
      prefixes: ["inventory", "claims"],
      note: "الدفعات والبطاقات والمطالبات",
    },
    {
      id: "network",
      title: "الوكلاء ونقاط البيع",
      icon: "agents",
      prefixes: ["network"],
      note: "شبكة التوزيع وحالة الحسابات",
    },
    {
      id: "admin",
      title: "المتابعة الإدارية",
      icon: "audit",
      prefixes: ["users", "audit", "support", "operations"],
      note: "التغييرات والدعم والإشعارات",
    },
  ];
  const details = {
    data: () => ({ selected: null, page: 1, pageSize: 20 }),
    methods: {
      showRecord(row) {
        this.selected = row;
        this.$nextTick(() =>
          this.$el
            .querySelector(".report-record-panel")
            ?.scrollIntoView({ block: "start", behavior: "instant" }),
        );
      },
    },
    computed: {
      vm() {
        return this.$root;
      },
      pages() {
        return Math.max(
          1,
          Math.ceil(this.vm.reportFilteredRows.length / this.pageSize),
        );
      },
      shown() {
        return this.vm.reportFilteredRows.slice(
          (this.page - 1) * this.pageSize,
          this.page * this.pageSize,
        );
      },
      fields() {
        return this.selected
          ? (this.vm.reportActive?.columns || []).map((c) => ({
              key: c.key,
              label: c.label,
              value: this.vm.reportCell(this.selected[c.key], c),
            }))
          : [];
      },
      related() {
        if (!this.selected) return [];
        const current = this.vm.reportActive?.id,
          r = this.selected,
          links = {
            "inventory-orders": [
              ["inventory", "id", r.batch],
              ["inventory-invoices", "batch", r.batch],
            ],
            inventory: [
              ["inventory-cards", "batch", r.id],
              ["inventory-invoices", "batch", r.id],
              ["claims", "batch", r.id],
            ],
            sales: [
              ["sales-printing", "sale", r.id],
              ["inventory-cards", "sale", r.id],
            ],
            "prices-approvals": [["prices-changes", "id", r.id]],
            "wallets-requests": [
              ["wallets-transfers", "id", r.transfer],
              ["wallets-holds", "request", r.id],
              ["inventory-invoices", "batch", r.batch],
            ],
            "wallets-transfers": [["wallets-groups", "transfer", r.id]],
            support: [["support-replies", "ticket", r.id]],
            audit: [["audit-changes", "id", r.id]],
          };
        return (links[current] || [])
          .filter((x) => x[2] && x[2] !== "—")
          .map(([id, key, value]) => {
            const section = this.vm.reportBundle.sections.find(
              (s) => s.id === id,
            );
            return section
              ? {
                  ...section,
                  rows: section.rows.filter((row) => row[key] === value),
                }
              : null;
          })
          .filter((s) => s?.rows.length);
      },
    },
    watch: {
      "vm.reportSearch"() {
        this.page = 1;
        this.selected = null;
      },
      "vm.reportActive.id"() {
        this.page = 1;
        this.selected = null;
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
      pageSize() {
        this.page = 1;
      },
      "vm.currentUser"() {
        this.selected = null;
      },
    },
  };
  root.MasalReportGroups = {
    install(o) {
      const nav = o.computed.navGroups;
      o.computed.navGroups = function () {
        return nav
          .call(this)
          .map((g) => ({
            ...g,
            items: g.items
              .filter((n) => !["sales", "audit", "monitoring"].includes(n.id))
              .map((n) => ({
                ...n,
                label: n.id === "reports" ? "مركز التقارير والعمليات" : n.label,
              })),
          }))
          .filter((g) => g.items.length);
      };
      const go = o.methods.go;
      o.methods.go = function (page, ...args) {
        if (["sales", "audit", "monitoring"].includes(page)) {
          const group =
            page === "audit" ? "admin" : page === "sales" ? "sales" : "";
          const result = go.call(this, "reports", ...args);
          if (this.page === "reports") {
            this.reportOpenGroup = group;
            if (page === "sales" && this.statusFilter)
              this.reportStatus = this.statusFilter;
          }
          return result;
        }
        return go.call(this, page, ...args);
      };
      const data = o.data;
      o.data = function () {
        return {
          ...data.call(this),
          reportOpenGroup: "",
          reportDetailReturn: null,
        };
      };
      o.components["report-detail-dialog"] = details;
      o.computed.reportGroups = function () {
        return definitions
          .map((g) => ({
            ...g,
            sections: this.reportBundle.sections.filter((s) =>
              g.prefixes.includes(s.id.split("-")[0]),
            ),
          }))
          .filter((g) => g.sections.length);
      };
      o.computed.reportTypeScope = function () {
        const group = definitions.find((g) => g.id === this.reportOpenGroup);
        const sections = this.reportBundle.sections;
        return group
          ? sections.filter((s) => group.prefixes.includes(s.id.split("-")[0]))
          : sections;
      };
      o.computed.selectedReportGroup = function () {
        const group = this.reportGroups.find(
          (g) => g.id === this.reportOpenGroup,
        );
        if (!group) return;
        const cards = group.sections.map((s) => ({
          id: s.id,
          title:
            s.id === "sales"
              ? "سجل العمليات"
              : s.id === "audit"
                ? "سجل التغييرات"
                : s.title,
          count: s.rows.length,
          filter: "",
        }));
        const add = (id, title, filter) => {
          const section = group.sections.find((s) => s.id === id);
          if (section)
            cards.push({
              id,
              title,
              filter,
              count: section.rows.filter((r) =>
                this.reportDetailMatches(r, filter),
              ).length,
            });
        };
        if (group.id === "network") {
          add("network", "الوكلاء الرئيسيون", "main");
          add("network", "الفروع", "branch");
          add("network", "الفروع الفرعية", "nested");
          add("network-pos", "نقاط البيع النشطة", "active");
          add("network-pos", "نقاط البيع المعطّلة", "inactive");
        }
        if (group.id === "inventory") {
          add("inventory-cards", "البطاقات المتاحة", "available");
          add("inventory-cards", "البطاقات المباعة", "issued");
          add("inventory-cards", "البطاقات المحجورة والمصدّرة", "held");
        }
        return { ...group, cards };
      };
      o.methods.reportDetailMatches = function (r, key) {
        if (!key) return true;
        if (key === "main") return r.type === "رئيسي";
        if (key === "branch" || key === "nested") {
          const a = this.s.agents.find((a) => a.id === r.id),
            nested = this.s.agents.some(
              (p) => p.id === a?.parent && p.type === "فرعي",
            );
          return a?.type === "فرعي" && (key === "nested" ? nested : !nested);
        }
        if (key === "active") return r.active === "مفعل";
        if (key === "inactive") return r.active === "موقوف";
        return (
          {
            available: ["Available"],
            issued: ["Issued"],
            held: ["Quarantined", "Exported", "Awaiting Replacement"],
          }[key] || []
        ).includes(r.status);
      };
      const active = o.computed.reportActive;
      o.computed.reportActive = function () {
        const section = active.call(this);
        if (
          !section ||
          this.modal?.kind !== "reportDetails" ||
          !this.modal.filter
        )
          return section;
        return {
          ...section,
          title: this.modal.title,
          rows: section.rows.filter((r) =>
            this.reportDetailMatches(r, this.modal.filter),
          ),
        };
      };
      o.methods.toggleReportGroup = function (id) {
        const closing = this.reportOpenGroup === id;
        this.reportOpenGroup = closing ? "" : id;
        if (!closing) {
          this.reportKind = "all";
          this.reportSection = "sales";
        }
      };
      o.methods.openReportDetails = function (id, filter = "", title = "") {
        const section = this.reportBundle.sections.find((s) => s.id === id);
        if (!section) return;
        if (!this.reportSections.some((s) => s.id === id))
          this.reportKind = "all";
        this.selectReport(id);
        this.modal = {
          kind: "reportDetails",
          title: title || section.title,
          filter,
        };
      };
      o.computed.reportDestination = function () {
        if (this.modal?.kind !== "reportDetails") return null;
        const targets = {
          inventory: ["inventory", "المخزون"],
          "inventory-cards": ["inventory", "المخزون"],
          "inventory-purchases": ["import", "الطلبيات"],
          "inventory-invoices": ["wallets", "المحافظ والتحويلات"],
          "inventory-products": ["products", "الفئات"],
          "inventory-providers": ["providers", "الشركات"],
          wallets: ["wallets", "المحافظ والتحويلات"],
          "wallets-ledger": ["wallets", "المحافظ والتحويلات"],
          "wallets-legacy": ["wallets", "المحافظ والتحويلات"],
          network: ["agents", "الوكلاء"],
          "network-pos": ["pos", "نقاط البيع"],
          prices: ["prices", "الأسعار"],
          "prices-approvals": ["prices", "الأسعار"],
          claims: ["claims", "البطاقات التالفة"],
          "claims-exports": ["exports", "تصدير البطاقات"],
          support: ["support", "الدعم الفني"],
          "support-replies": ["support", "الدعم الفني"],
          users: ["users", "المستخدمين"],
          operations: ["security", "الحماية والأمان"],
          "operations-notifications": ["notifications", "الإشعارات"],
        };
        const target = targets[this.reportActive?.id];
        return target && this.can(target[0] + ".view")
          ? { page: target[0], label: "الانتقال إلى " + target[1] }
          : null;
      };
      o.methods.goToReportDestination = async function () {
        const target = this.reportDestination;
        if (!target || !this.can(target.page + ".view")) return;
        const section = this.reportActive.id,
          filter = this.modal.filter,
          context = {
            agent: this.reportAgent,
            pos: this.reportPOS,
            product: this.reportProduct,
            provider: this.reportProvider,
            city: this.reportCity,
            from: this.reportFrom,
            to: this.reportTo,
          };
        this.reportDetailReturn = null;
        this.closeModal();
        this.go(target.page);
        if (this.page !== target.page) return;
        this.search = "";
        this.statusFilter = "";
        await this.$nextTick();
        if (target.page === "wallets") {
          this.walletFilters = {
            ...this.emptyWalletFilters(),
            network: context.agent,
            account: context.pos,
            city: context.city,
            from: context.from,
            to: context.to,
          };
          const panel = this.$refs.operations;
          if (panel) {
            panel.tab =
              section === "inventory-invoices" ? "invoice" : "balances";
            panel.account = context.pos || "";
            panel.service = "voucher";
          }
        }
        if (target.page === "inventory") {
          this.inventoryAgent = this.inventoryAgents.some(
            (a) => a.id === context.agent,
          )
            ? context.agent
            : "";
          if (context.product)
            this.search = this.nameOf("products", context.product);
        }
        if (target.page === "agents") {
          this.networkKind =
            { main: "main", branch: "sub", nested: "subsub" }[filter] || "all";
          this.networkSearch = "";
          if (context.agent) {
            const name = this.nameOf("agents", context.agent);
            this.search = name;
            this.networkSearch = name;
          }
        }
        if (target.page === "pos" && context.pos)
          this.search = this.nameOf("pos", context.pos);
        if (target.page === "products" && context.product)
          this.search = this.nameOf("products", context.product);
        if (target.page === "providers" && context.provider)
          this.search = this.nameOf("providers", context.provider);
        if (
          target.page === "prices" &&
          this.scopeFilterAgents.some(
            (a) => a.id === context.agent && a.type === "رئيسي",
          )
        )
          this.priceAgent = context.agent;
      };
      o.methods.reportSale = function (row) {
        return this.visibleSales.find((t) => t.id === row.id);
      };
      o.methods.reportRecordAction = function (row, action) {
        const original = this.modal,
          record =
            this.reportActive?.id === "sales"
              ? this.reportSale(row)
              : this.auditRows.find((r) => r.id === row.id);
        if (!record) return;
        this.reportDetailReturn = { ...original };
        if (action === "receipt") this.viewReceipt(record);
        else if (action === "reprint") this.openReprint(record);
        else this.inspect(record);
        if (this.modal === original) this.reportDetailReturn = null;
      };
      const select = o.methods.selectReport;
      o.methods.selectReport = function (id) {
        select.call(this, id);
        const columns = this.reportActive?.columns || [];
        try {
          const stored = JSON.parse(
            localStorage.getItem(
              "masal-report-columns-" + this.currentUser + "-" + id,
            ) || "[]",
          );
          this.reportHidden = Array.isArray(stored)
            ? stored.filter((k) => columns.some((c) => c.key === k))
            : [];
        } catch {
          this.reportHidden = [];
        }
        if (columns.some((c) => c.key === "time"))
          this.reportSort = { key: "time", direction: -1 };
      };
      o.methods.toggleReportColumn = function (key) {
        this.reportHidden = this.reportHidden.includes(key)
          ? this.reportHidden.filter((k) => k !== key)
          : [...this.reportHidden, key];
        try {
          localStorage.setItem(
            "masal-report-columns-" +
              this.currentUser +
              "-" +
              this.reportSection,
            JSON.stringify(this.reportHidden),
          );
        } catch {
          this.notify("تعذر حفظ اختيار الأعمدة", true);
        }
      };
      const close = o.methods.closeModal;
      o.methods.closeModal = function (...args) {
        const back = this.reportDetailReturn;
        this.reportDetailReturn = null;
        const result = close.apply(this, args);
        if (back && this.page === "reports") this.modal = back;
        return result;
      };
      const exports = o.methods.reportExportSections;
      o.methods.reportExportSections = function (all) {
        if (this.modal?.kind === "reportDetails")
          return this.reportActive
            ? [
                {
                  ...this.reportActive,
                  columns: this.reportColumns,
                  rows: this.reportFilteredRows,
                },
              ]
            : [];
        return exports.call(this, all);
      };
      const change = o.methods.switchUser;
      o.methods.switchUser = function (...args) {
        this.reportOpenGroup = "";
        this.reportDetailReturn = null;
        return change.apply(this, args);
      };
    },
  };
})(window);

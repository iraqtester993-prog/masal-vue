(function (root) {
  "use strict";
  const Node = {
    name: "NetworkBranch",
    props: ["node", "tr", "money", "expanded", "searching", "canEdit"],
    emits: ["toggle", "edit"],
    methods: {
      canReset() {
        const u = MasalNetworkAccounts.linked(
          this.$root.s,
          "agent",
          this.node.record.id,
        );
        return !!u && this.$root.canResetUserPassword(u.id);
      },
      requestReset() {
        const u = MasalNetworkAccounts.linked(
          this.$root.s,
          "agent",
          this.node.record.id,
        );
        if (u) this.$root.requestPasswordReset(u.id);
      },
    },
  };
  const Compact = {
    name: "NetworkOutline",
    props: ["node", "tr", "expanded", "parentName"],
    emits: ["toggle"],
  };

  const Drill = {
    data() {
      return {
        rootId: "",
        parentId: "",
        mode: "branches",
        query: "",
        page: 1,
        size: 10,
        rootPage: 1,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      roots() {
        return this.vm.networkTree.map((n) => n.record);
      },
      rootPages() {
        return Math.max(1, Math.ceil(this.roots.length / 10));
      },
      shownRoots() {
        return this.roots.slice((this.rootPage - 1) * 10, this.rootPage * 10);
      },
      path() {
        const list = [],
          seen = new Set();
        let id = this.parentId;
        while (id && !seen.has(id)) {
          seen.add(id);
          const a = this.vm.visibleAgents.find((x) => x.id === id);
          if (!a) break;
          list.unshift(a);
          if (id === this.rootId) break;
          id = a.parent;
        }
        return list;
      },
      rows() {
        const q = this.query.trim().toLowerCase(),
          globalQ = this.vm.networkSearch.trim().toLowerCase(),
          ids = new Set(this.vm.engine.descendants(this.parentId));
        const rows =
          this.mode === "points"
            ? this.vm.visiblePOS.filter((p) => ids.has(p.agent))
            : this.vm.visibleAgents.filter((a) => a.parent === this.parentId);
        return rows.filter(
          (a) =>
            (!q ||
              [a.name, a.city, a.phone].some((v) =>
                String(v || "")
                  .toLowerCase()
                  .includes(q),
              )) &&
            (!globalQ ||
              this.mode !== "points" ||
              [a.name, a.city, a.phone].some((v) =>
                String(v || "")
                  .toLowerCase()
                  .includes(globalQ),
              )),
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.rows.length / this.size));
      },
      shown() {
        return this.rows.slice(
          (this.page - 1) * this.size,
          this.page * this.size,
        );
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      size() {
        this.page = 1;
      },
      "vm.networkSearch"() {
        this.reset();
      },
      "vm.networkKind"() {
        this.reset();
      },
      "vm.currentUser"() {
        this.reset();
      },
    },
    methods: {
      reset() {
        this.rootId = "";
        this.parentId = "";
        this.rootPage = 1;
        this.query = "";
        this.page = 1;
      },
      branches(id) {
        return this.vm.visibleAgents.filter((a) => a.parent === id).length;
      },
      points(id) {
        const ids = new Set(this.vm.engine.descendants(id));
        return this.vm.visiblePOS.filter((a) => ids.has(a.agent)).length;
      },
      open(root, parent, mode) {
        this.rootId = root;
        this.parentId = parent;
        this.mode = mode;
        this.query = "";
        this.page = 1;
      },
      back() {
        const index = this.path.length - 2;
        if (index >= 0) this.open(this.rootId, this.path[index].id, "branches");
        else this.rootId = "";
      },
      details(a) {
        const page = this.vm.s.pos.some((p) => p.id === a.id)
          ? "pos"
          : "agents";
        this.vm.modal = {
          kind: "inspect",
          title: a.name,
          data: {
            الاسم: a.name,
            المحافظة: a.city || "—",
            الهاتف: a.phone || "—",
            "بريد تسجيل الدخول": this.vm.networkLoginEmail(page, a.id),
            "رصيد البطاقات":
              this.vm.money(this.vm.engine.serviceBalance(a.id, "voucher")) +
              " د.ع",
            الحالة: a.active ? "مفعّل" : "موقوف",
          },
        };
      },
    },
  };

  function install(o) {
    o.components ??= {};
    o.components["network-drill"] = Drill;
    o.directives = {
      ...o.directives,
      "network-stem": {
        mounted(el) {
          const draw = () => {
            const origin = el.getBoundingClientRect().top,
              anchors = [
                ...el.querySelectorAll(
                  ":scope > .network-child-section > .network-branches > .network-node",
                ),
              ].map((n) => n.getBoundingClientRect().top - origin + 30),
              points = el.querySelector(":scope > .network-point-section");
            if (points)
              anchors.push(points.getBoundingClientRect().top - origin + 50);
            el.style.setProperty("--stem-top", (anchors[0] || 0) + "px");
            el.style.setProperty(
              "--stem-height",
              (anchors.length > 1 ? anchors.at(-1) - anchors[0] : 0) + "px",
            );
          };
          el._stemDraw = draw;
          el._stemObserver = new ResizeObserver(draw);
          el._stemObserver.observe(el);
          draw();
        },
        updated(el) {
          requestAnimationFrame(() => {
            if (el.isConnected) el._stemDraw?.();
          });
        },
        unmounted(el) {
          el._stemObserver?.disconnect();
        },
      },
    };
    o.components = {
      ...o.components,
      NetworkBranch: Node,
      NetworkOutline: Compact,
    };
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        treeView: true,
        networkKind: "all",
        networkSearch: "",
        networkExpanded: {},
        outlineExpanded: {},
      };
    };
    o.computed.agentToolbarSearch = {
      get() {
        return this.networkSearch;
      },
      set(v) {
        this.networkSearch = v;
        this.search = v;
      },
    };
    Object.assign(o.computed, {
      networkTree() {
        const agents = this.visibleAgents,
          points = this.visiblePOS,
          ids = new Set(agents.map((a) => a.id)),
          seen = new Set();
        const build = (a, top = false) => {
          if (seen.has(a.id)) return null;
          seen.add(a.id);
          const children = agents
              .filter((x) => x.parent === a.id)
              .map((x) => build(x))
              .filter(Boolean),
            pos = points
              .filter((p) => p.agent === a.id)
              .map((p) => ({
                ...p,
                balance: this.engine.serviceBalance(p.id, "voucher"),
              }));
          return {
            record: a,
            nestedSub:
              a.type === "فرعي" &&
              this.s.agents.some((p) => p.id === a.parent && p.type === "فرعي"),
            root: top,
            children,
            points: pos,
            balance: this.engine.serviceBalance(a.id, "voucher"),
            directBranches: children.length,
            directPoints: pos.length,
            totalPoints:
              pos.length + children.reduce((sum, n) => sum + n.totalPoints, 0),
          };
        };
        const roots = agents
          .filter((a) => !ids.has(a.parent))
          .map((a) => build(a, true));
        for (const a of agents) if (!seen.has(a.id)) roots.push(build(a, true));
        const colors = [
          "#168baf",
          "#8661bc",
          "#249275",
          "#bc7e32",
          "#bb5c7e",
          "#527bc4",
        ];
        const colorBranch = (n, color) => {
          n.color = color;
          n.children.forEach((c) => colorBranch(c, color));
        };
        for (const n of roots) {
          const id = this.engine.main(n.record.id) || n.record.id;
          let hash = 0;
          for (const c of id) hash = (hash * 31 + c.charCodeAt(0)) >>> 0;
          colorBranch(n, colors[hash % colors.length]);
        }
        const q = this.networkSearch.trim().toLocaleLowerCase();
        const match = (r) =>
          ["id", "name", "city", "phone", "owner", "serial", "model"].some(
            (k) =>
              String(r[k] || "")
                .toLocaleLowerCase()
                .includes(q),
          );
        const filter = (n) => {
          if (match(n.record)) return n;
          const children = n.children.map(filter).filter(Boolean),
            pos = n.points.filter(match);
          return children.length || pos.length
            ? { ...n, children, points: pos, context: true }
            : null;
        };
        const searched = q ? roots.map(filter).filter(Boolean) : roots;
        if (this.networkKind === "all") return searched;
        const byKind = (n) => {
          const children = n.children.map(byKind).filter(Boolean),
            pos = this.networkKind === "pos" ? n.points : [],
            matches =
              this.networkKind === "main"
                ? n.record.type === "رئيسي"
                : this.networkKind === "sub"
                  ? n.record.type !== "رئيسي"
                  : this.networkKind === "subsub"
                    ? n.nestedSub
                    : false;
          return matches || children.length || pos.length
            ? { ...n, children, points: pos, context: n.context || !matches }
            : null;
        };
        return searched.map(byKind).filter(Boolean);
      },
    });
    Object.assign(o.methods, {
      toggleOutline(id) {
        this.outlineExpanded[id] = this.outlineExpanded[id] === false;
      },
      expandOutline(value) {
        this.outlineExpanded = Object.fromEntries(
          this.visibleAgents.map((a) => [a.id, value]),
        );
      },
      toggleNetwork(id) {
        this.networkExpanded[id] = this.networkExpanded[id] === false;
      },
      expandNetwork(value) {
        this.networkExpanded = Object.fromEntries(
          this.visibleAgents.map((a) => [a.id, value]),
        );
      },
    });

    o.methods.agentExportRows = function () {
      let entries = [];
      if (!this.treeView) {
        if (this.networkKind === "pos") {
          const q = this.search.trim().toLowerCase();
          entries = this.visiblePOS
            .filter(
              (p) =>
                !q ||
                [p.name, p.city, this.nameOf("agents", p.agent)].some((v) =>
                  String(v || "")
                    .toLowerCase()
                    .includes(q),
                ),
            )
            .map((record) => ({ record, point: true }));
        } else
          entries = this.filteredRows.map((record) => ({
            record,
            point: false,
          }));
      } else {
        const walk = (n) => {
          if (!n.context) entries.push({ record: n.record, point: false });
          n.points.forEach((record) => entries.push({ record, point: true }));
          n.children.forEach(walk);
        };
        this.networkTree.forEach(walk);
      }
      const allowed = new Set(
        [...this.visibleAgents, ...this.visiblePOS].map((a) => a.id),
      );
      return entries
        .filter((x) => allowed.has(x.record.id))
        .map(({ record: a, point }) => ({
          الاسم: a.name,
          النوع: point
            ? "نقطة بيع"
            : a.type === "رئيسي"
              ? "وكيل رئيسي"
              : this.s.agents.some(
                    (p) => p.id === a.parent && p.type === "فرعي",
                  )
                ? "فرع فرعي"
                : "فرع",
          "تابع إلى":
            this.visibleAgents.find(
              (p) => p.id === (point ? a.agent : a.parent),
            )?.name || "—",
          المحافظة: a.city || "—",
          الهاتف: String(a.phone || ""),
          الحالة: a.active ? "مفعّل" : "موقوف",
        }));
    };
    o.methods.exportAgentNetwork = function (format) {
      this.run(() => {
        this.engine.requirePermission("agents.export");
        const rows = this.agentExportRows();
        if (!rows.length) throw Error("لا توجد نتائج للتصدير");
        const title = "الوكلاء والفروع",
          headers = Object.keys(rows[0]);
        if (format === "excel") {
          download(
            "masal-agents.xlsx",
            MasalExcel.workbook(rows),
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
          );
        } else {
          const esc = (v) =>
            String(v ?? "").replace(
              /[&<>"']/g,
              (c) =>
                ({
                  "&": "&amp;",
                  "<": "&lt;",
                  ">": "&gt;",
                  '"': "&quot;",
                  "'": "&#39;",
                })[c],
            );
          const w = window.open("", "_blank");
          if (!w) throw Error("اسمح بفتح نافذة التقرير");
          w.document.write(
            '<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>' +
              title +
              "</title><style>body{font:14px Arial;padding:24px;color:#14374b}h1{color:#078fa9}table{width:100%;border-collapse:collapse}th,td{padding:10px;border:1px solid #ccdce4;text-align:right}th{background:#e4f3f6}tr{break-inside:avoid}thead{display:table-header-group}@media print{button{display:none}body{padding:0}}@page{size:A4 landscape;margin:14mm}</style><h1>" +
              title +
              "</h1><p>عدد النتائج: " +
              rows.length +
              " · " +
              esc(new Date().toLocaleDateString("ar-IQ")) +
              '</p><button onclick="window.print()">حفظ PDF / طباعة</button><table><thead><tr>' +
              headers.map((h) => "<th>" + esc(h) + "</th>").join("") +
              "</tr></thead><tbody>" +
              rows
                .map(
                  (r) =>
                    "<tr>" +
                    headers.map((h) => "<td>" + esc(r[h]) + "</td>").join("") +
                    "</tr>",
                )
                .join("") +
              "</tbody></table></html>",
          );
          w.document.close();
          w.focus();
          w.print();
        }
        this.engine.log("تصدير الوكلاء والفروع", "agents", null, {
          format,
          count: rows.length,
        });
      });
    };
    const change = o.methods.switchUser;
    o.methods.switchUser = function () {
      change.call(this);
      this.networkKind = "all";
      this.networkSearch = "";
      this.networkExpanded = {};
      this.outlineExpanded = {};
    };
  }
  root.MasalNetworkTree = { install };
})(globalThis);

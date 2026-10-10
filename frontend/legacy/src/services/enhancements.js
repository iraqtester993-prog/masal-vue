(function (root) {
  "use strict";
  const A = root.MasalAccess;
  const esc = (x) =>
    String(x ?? "").replace(
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
  function install(o) {
    if (typeof schemas !== "undefined") {
      const field = schemas.users.fields.find((f) => f.key === "assignedText");
      field.type = "agentScope";
      field.label = "الوكلاء المكلف بهم الموظف أو المشرف";
    }
    const baseData = o.data;
    o.data = function () {
      return {
        ...baseData.call(this),
        permissionUser: "",
        permissionSearch: "",
        permissionMode: "all",
        permissionDraft: { overrides: {} },
        permissionReason: "",
        permissionTemplate: "reader",
        reportKind: "all",
        reportAgent: "",
        reportPOS: "",
        reportProduct: "",
        reportProvider: "",
        reportCity: "",
        reportStatus: "",
        reportSearch: "",
        reportSection: "sales",
        reportPage: 1,
        reportPageSize: 25,
        reportHidden: [],
        reportSort: { key: "", direction: 1 },
      };
    };
    o.directives = {
      permit: {
        mounted(el, b) {
          applyPermit(el, b.value);
        },
        updated(el, b) {
          applyPermit(el, b.value);
        },
      },
    };
    function applyPermit(el, value) {
      const nodes = el.matches("button,input,select,textarea")
        ? [el]
        : [...el.querySelectorAll("button[type=submit],button:not([type])")];
      for (const n of nodes) {
        n.disabled = !value;
        n.setAttribute("aria-disabled", String(!value));
        if (!value) n.title = "ليست لديك صلاحية تنفيذ هذا الإجراء";
        else n.removeAttribute("title");
      }
    }
    Object.assign(o.computed, {
      permissionUsers() {
        return this.s.users.filter(
          (u) =>
            this.actor.role === "owner" ||
            u.id === this.currentUser ||
            (u.role !== "owner" &&
              A.scope(this.s, u).length &&
              A.scope(this.s, u).every((a) => this.engine.allowed(a))),
        );
      },
      permissionTarget() {
        return this.permissionUsers.find((u) => u.id === this.permissionUser);
      },
      permissionEditable() {
        return (
          !!this.permissionTarget &&
          this.permissionTarget.role !== "owner" &&
          this.permissionTarget.id !== this.currentUser &&
          this.can("permissions.manage")
        );
      },
      permissionGroups() {
        const q = this.permissionSearch.trim().toLowerCase();
        return A.groups
          .map(([module, title]) => ({
            module,
            title,
            items: A.catalog.filter(
              (p) =>
                p.module === module &&
                (!q ||
                  [p.key, this.tr(p.label), this.tr(p.group)]
                    .join(" ")
                    .toLowerCase()
                    .includes(q)) &&
                (this.permissionMode === "all" ||
                  (this.permissionMode === "sensitive" && p.sensitive) ||
                  (this.permissionMode === "changed" &&
                    this.permissionDraft.overrides[p.key])),
            ),
          }))
          .filter((g) => g.items.length);
      },
      permissionStats() {
        const u = this.permissionTarget;
        if (!u) return { allowed: 0, denied: 0, changes: 0 };
        const preview = { ...u, active: true, access: this.permissionDraft };
        return {
          allowed: A.catalog.filter((p) => A.can(preview, p.key)).length,
          denied: A.catalog.filter((p) => !A.can(preview, p.key)).length,
          changes:
            A.catalog.filter(
              (p) =>
                (u.access?.overrides?.[p.key] || "inherit") !==
                (this.permissionDraft.overrides[p.key] || "inherit"),
            ).length +
            (JSON.stringify(u.access?.scope || null) !==
            JSON.stringify(this.permissionDraft.scope || null)
              ? 1
              : 0),
        };
      },
      reportBundle() {
        try {
          return MasalReports.build(this.engine, {
            from: this.reportFrom,
            to: this.reportTo,
            agent: this.reportAgent,
            pos: this.reportPOS,
            product: this.reportProduct,
            provider: this.reportProvider,
            city: this.reportCity,
            status: this.reportStatus,
          });
        } catch (e) {
          return { sections: [], sales: null, error: e.message };
        }
      },
      reportSections() {
        return this.reportBundle.sections.filter(
          (s) =>
            this.reportKind === "all" || s.id.split("-")[0] === this.reportKind,
        );
      },
      reportActive() {
        return (
          this.reportSections.find((s) => s.id === this.reportSection) ||
          this.reportSections[0]
        );
      },
      reportColumns() {
        return (
          this.reportActive?.columns.filter(
            (c) => !this.reportHidden.includes(c.key),
          ) || []
        );
      },
      reportFilteredRows() {
        const section = this.reportActive;
        if (!section) return [];
        const q = this.reportSearch.trim().toLowerCase();
        let rows = section.rows.filter(
          (r) =>
            !q ||
            section.columns.some((c) =>
              String(this.reportCell(r[c.key], c)).toLowerCase().includes(q),
            ),
        );
        const sort = this.reportSort;
        if (sort.key)
          rows = rows.slice().sort((a, b) => {
            const av = a[sort.key],
              bv = b[sort.key];
            return (
              sort.direction *
              (typeof av === "number" && typeof bv === "number"
                ? av - bv
                : String(av).localeCompare(
                    String(bv),
                    this.lang === "en" ? "en" : "ar",
                    { numeric: true },
                  ))
            );
          });
        return rows;
      },
      reportPageCount() {
        return Math.max(
          1,
          Math.ceil(this.reportFilteredRows.length / this.reportPageSize),
        );
      },
      reportVisibleRows() {
        return this.reportFilteredRows.slice(
          (Math.min(this.reportPage, this.reportPageCount) - 1) *
            this.reportPageSize,
          Math.min(this.reportPage, this.reportPageCount) * this.reportPageSize,
        );
      },
      reportKpis() {
        const r = this.reportBundle.sales;
        if (!r) return [];
        return [
          { label: "العمليات", value: r.count },
          { label: "البطاقات المباعة", value: r.quantity },
          { label: "إجمالي المبيعات", value: r.total },
          ...(r.cost !== null
            ? [{ label: "تكلفة المباع", value: r.cost }]
            : []),
          ...(r.profit !== null
            ? [
                { label: "الربح الإجمالي", value: r.profit },
                { label: "نسبة الربح %", value: r.margin || 0 },
              ]
            : []),
        ];
      },
      reportTypes() {
        const sections = this.reportTypeScope || this.reportBundle.sections;
        return [
          ...new Map(sections.map((s) => [s.id.split("-")[0], s])).values(),
        ].map((s) => ({
          id: s.id.split("-")[0],
          label:
            A.catalog.find((p) => p.key === "reports." + s.id.split("-")[0])
              ?.label || s.title,
        }));
      },
    });
    Object.assign(o.methods, {
      toggleAssignedAgent(id, checked) {
        let ids = (this.editForm.assignedText || "")
          .split(",")
          .map((s) => s.trim())
          .filter(Boolean);
        ids = checked
          ? [...new Set([...ids, id])]
          : ids.filter((x) => x !== id);
        this.editForm.assignedText = ids.join(",");
      },
      can(key) {
        if (key === "integrations.view") return false;
        return this.engine.can(key);
      },
      hasPermissionKey(key) {
        return A.catalog.some((p) => p.key === key);
      },
      editPermissions(user) {
        this.go("permissions");
        if (this.page !== "permissions") return;
        this.permissionUser = user.id;
        this.loadPermissions();
      },
      loadPermissions() {
        const u = this.permissionTarget;
        this.permissionDraft = Masal.clone(u?.access || { overrides: {} });
        this.permissionDraft.overrides ||= {};
        this.permissionReason = "";
        this.permissionSearch = "";
      },
      permissionEffective(key) {
        return A.can(
          {
            ...this.permissionTarget,
            active: true,
            access: this.permissionDraft,
          },
          key,
        );
      },
      permissionGrantable(p) {
        return (
          p.module !== "backup" &&
          this.can(p.key) &&
          (p.module === "data" || this.can(p.module + ".view"))
        );
      },
      permissionCheckDisabled(p) {
        return (
          !this.permissionEditable ||
          p.module === "backup" ||
          (!this.permissionGrantable(p) && !this.permissionEffective(p.key))
        );
      },
      permissionSelection(module) {
        const items = A.catalog.filter(
          (p) =>
            (!module || p.module === module) && this.permissionGrantable(p),
        );
        const selected = items.filter((p) =>
          this.permissionEffective(p.key),
        ).length;
        return {
          total: items.length,
          selected,
          all: items.length > 0 && selected === items.length,
          mixed: selected > 0 && selected < items.length,
        };
      },
      togglePermissionCheck(p, checked) {
        if (this.permissionCheckDisabled(p)) return;
        if (checked) {
          if (!this.permissionGrantable(p)) return;
          if (p.module !== "data" && !p.key.endsWith(".view"))
            this.setPermission(p.module + ".view", "allow");
          this.setPermission(p.key, "allow");
          if (
            [
              "permissions.create",
              "permissions.edit",
              "permissions.toggle",
              "permissions.delete",
            ].includes(p.key)
          )
            this.setPermission("permissions.manage", "allow");
        } else {
          this.setPermission(p.key, "deny");
          if (p.key === "permissions.manage")
            for (const k of [
              "permissions.create",
              "permissions.edit",
              "permissions.toggle",
              "permissions.delete",
            ])
              this.setPermission(k, "deny");
          if (p.key.endsWith(".view"))
            for (const item of A.catalog.filter(
              (item) => item.module === p.module,
            ))
              this.setPermission(item.key, "deny");
        }
      },
      togglePermissionSelection(module, checked) {
        if (!this.permissionEditable) return;
        for (const p of A.catalog.filter(
          (p) => (!module || p.module === module) && p.module !== "backup",
        )) {
          if (checked) {
            if (this.permissionGrantable(p)) this.setPermission(p.key, "allow");
          } else this.setPermission(p.key, "deny");
        }
      },
      resetPermissionSelection() {
        if (this.permissionEditable) this.permissionDraft.overrides = {};
      },
      permissionDefault(key) {
        return A.defaults(this.permissionTarget?.role, key);
      },
      setPermission(key, value) {
        if (!this.permissionEditable) return;
        if (value === "inherit") delete this.permissionDraft.overrides[key];
        else this.permissionDraft.overrides[key] = value;
      },
      setPermissionGroup(group, value) {
        for (const p of group.items)
          if (value !== "allow" || this.can(p.key))
            this.setPermission(p.key, value);
      },
      setCustomScope(checked) {
        if (checked)
          this.permissionDraft.scope = {
            roots: A.scope(this.s, this.permissionTarget),
            descendants: false,
          };
        else delete this.permissionDraft.scope;
      },
      applyPermissionTemplate() {
        if (!this.permissionEditable) return;
        const templates = {
          reader: (p) =>
            p.key === "dashboard.view" ||
            p.key === "reports.view" ||
            p.key === "reports.sales" ||
            p.key === "sales.view" ||
            p.key === "landing.view",
          cashier: (p) =>
            [
              "dashboard.view",
              "sell.view",
              "sell.create",
              "sell.receipt",
              "sell.print",
              "sell.result",
              "sell.reprint",
              "sales.view",
              "exceptions.view",
              "data.pin",
              "support.view",
              "support.create",
            ].includes(p.key),
          accountant: (p) =>
            (["dashboard", "reports", "wallets", "prices", "audit"].includes(
              p.module,
            ) &&
              ![
                "deposit",
                "approve",
                "reverse",
                "transfer",
                "propose",
                "import",
              ].includes(p.key.split(".")[1])) ||
            ["data.cost", "data.profit"].includes(p.key),
          support: (p) =>
            [
              "dashboard.view",
              "pos.view",
              "exceptions.view",
              "support.view",
              "support.create",
              "support.reply",
              "support.close",
              "support.escalate",
            ].includes(p.key),
        };
        const test = templates[this.permissionTemplate];
        for (const p of A.catalog)
          this.permissionDraft.overrides[p.key] =
            test(p) && this.can(p.key) ? "allow" : "deny";
      },
      reviewPermissions() {
        this.run(() => {
          if (!this.permissionEditable)
            throw Error("ليست لديك صلاحية تعديل هذا الحساب");
          if (!this.permissionReason.trim())
            throw Error("سبب تغيير الصلاحيات مطلوب");
          const engine = new Masal.Engine(
            Masal.clone(this.s),
            this.currentUser,
          );
          A.save(
            engine,
            this.permissionUser,
            this.permissionDraft,
            this.permissionReason,
          );
          const target = this.permissionTarget,
            changes = A.catalog
              .filter(
                (p) =>
                  (target.access?.overrides?.[p.key] || "inherit") !==
                  (this.permissionDraft.overrides[p.key] || "inherit"),
              )
              .map((p) => ({
                label: p.group + " • " + p.label,
                before: target.access?.overrides?.[p.key] || "inherit",
                after: this.permissionDraft.overrides[p.key] || "inherit",
              }));
          this.modal = {
            kind: "permissionReview",
            title: "مراجعة صلاحيات الموظف",
            user: target.id,
            changes,
            draft: Masal.clone(this.permissionDraft),
            reason: this.permissionReason,
            scopeChanged:
              JSON.stringify(target.access?.scope || null) !==
              JSON.stringify(this.permissionDraft.scope || null),
          };
        });
      },
      savePermissions() {
        this.run(() => {
          const m = this.modal;
          A.save(this.engine, m.user, m.draft, m.reason);
          this.closeModal();
          this.loadPermissions();
        }, "تم حفظ صلاحيات الموظف وتسجيل التغيير");
      },
      permissionValue(v) {
        return { allow: "سماح", deny: "منع", inherit: "حسب الدور" }[v] || v;
      },
      exportPermissions() {
        this.run(() => {
          this.engine.requirePermission("permissions.export");
          const u = this.permissionTarget;
          if (!u) throw Error("اختر الموظف");
          download(
            "masal-permissions-" + u.id + ".csv",
            csv(
              A.catalog.map((p) => ({
                module: this.tr(p.group),
                permission: this.tr(p.label),
                key: p.key,
                effective: A.can(u, p.key) ? "allow" : "deny",
                override: u.access?.overrides?.[p.key] || "inherit",
              })),
            ),
            "text/csv;charset=utf-8",
          );
          this.engine.log("تصدير صلاحيات موظف", u.id, null, {
            count: A.catalog.length,
          });
        });
      },
      reportCell(value, col) {
        if (value === null || value === undefined || value === "—") return "—";
        if (col.key === "role")
          return this.tr(this.displayField({ role: value }, { key: "role" }));
        if (
          col.type === "money" ||
          col.type === "number" ||
          col.type === "percent"
        )
          return this.money(value) + (col.type === "percent" ? "%" : "");
        if (col.type === "date")
          return value && Number.isFinite(Date.parse(value))
            ? this.formatTime(value)
            : "—";
        const labels = {
          true: "مفعّل",
          false: "معطّل",
          all: "جميع المستخدمين",
          agents: "الوكلاء",
          branches: "الفروع",
          points: "نقاط البيع",
          custom: "مخصص",
          support: "الدعم الفني",
          production: "تشغيل فعلي",
          sandbox: "بيئة اختبار",
          test: "اختبار",
          success: "ناجح",
          failed: "فاشل",
          pending: "بانتظار المعالجة",
          cancelled: "ملغى",
          approved: "معتمد",
          rejected: "مرفوض",
          voucher: "البطاقات",
          topup: "التعبئة",
          cash: "نقدي",
          low: "منخفضة",
          normal: "عادية",
          high: "عالية",
          urgent: "عاجلة",
        };
        return this.tr(labels[String(value)] || this.status(String(value)));
      },
      selectReport(id) {
        this.reportSection = id;
        this.reportPage = 1;
        this.reportHidden = [];
        this.reportSort = { key: "", direction: 1 };
        this.reportSearch = "";
      },
      sortReport(key) {
        this.reportSort = {
          key,
          direction:
            this.reportSort.key === key ? -this.reportSort.direction : 1,
        };
      },
      reportPreset(mode) {
        const now = Masal.day();
        this.reportTo = now;
        if (mode === "all") {
          this.reportFrom = "";
          this.reportTo = "";
        } else if (mode === "today") this.reportFrom = now;
        else if (mode === "month") this.reportFrom = now.slice(0, 8) + "01";
        else {
          const date = new Date(now + "T12:00:00Z");
          date.setUTCDate(date.getUTCDate() - 6);
          this.reportFrom = date.toISOString().slice(0, 10);
        }
      },
      openReportSettings() {
        const keys = [
          "reportFrom",
          "reportTo",
          "reportKind",
          "reportAgent",
          "reportPOS",
          "reportProduct",
          "reportProvider",
          "reportCity",
          "reportStatus",
        ];
        const types = this.reportTypes,
          kind = this.reportOpenGroup
            ? types.some((t) => t.id === this.reportKind) &&
              this.reportKind !== "all"
              ? this.reportKind
              : types[0]?.id || "all"
            : this.reportKind;
        this.modal = {
          kind: "reportSettings",
          title: "إعداد التقرير",
          draft: Object.fromEntries(
            keys.map((k) => [k, k === "reportKind" ? kind : this[k]]),
          ),
        };
      },
      resetReportDraft() {
        const kind = this.reportOpenGroup
          ? this.reportTypes[0]?.id || "all"
          : "all";
        for (const k of Object.keys(this.modal.draft))
          this.modal.draft[k] = k === "reportKind" ? kind : "";
      },
      reportDraftPreset(mode) {
        this.$options.methods.reportPreset.call(this.modal.draft, mode);
      },
      applyReportSettings() {
        const d = this.modal.draft;
        if (d.reportFrom && d.reportTo && d.reportFrom > d.reportTo) {
          this.notify("تاريخ البداية يجب أن يسبق تاريخ النهاية", true);
          return;
        }
        if (
          d.reportPOS &&
          !this.visiblePOS.some(
            (p) =>
              p.id === d.reportPOS &&
              (!d.reportAgent ||
                this.engine.descendants(d.reportAgent).includes(p.agent)),
          )
        )
          d.reportPOS = "";
        Object.assign(this, d);
        this.reportPage = 1;
        this.reportSearch = "";
        this.closeModal();
      },
      resetReport() {
        for (const k of [
          "reportFrom",
          "reportTo",
          "reportAgent",
          "reportPOS",
          "reportProduct",
          "reportProvider",
          "reportCity",
          "reportStatus",
          "reportSearch",
        ])
          this[k] = "";
        this.reportKind = "all";
        this.reportPage = 1;
      },
      reportMetadata() {
        return [
          {
            label: "الفترة",
            value:
              (this.reportFrom || this.tr("البداية")) +
              " → " +
              (this.reportTo || this.tr("الآن")),
          },
          { label: "أعد بواسطة", value: this.actor.name },
          {
            label: "وقت الإنشاء",
            value: this.formatTime(new Date().toISOString()),
          },
          {
            label: "الوكيل",
            value: this.reportAgent
              ? this.nameOf("agents", this.reportAgent)
              : this.tr("كل النطاق المسموح"),
          },
          {
            label: "نقطة البيع",
            value: this.reportPOS
              ? this.nameOf("pos", this.reportPOS)
              : this.tr("الكل"),
          },
          {
            label: "المنتج",
            value: this.reportProduct
              ? this.nameOf("products", this.reportProduct)
              : this.tr("الكل"),
          },
          {
            label: "المزود",
            value: this.reportProvider
              ? this.nameOf("providers", this.reportProvider)
              : this.tr("الكل"),
          },
          { label: "المحافظة", value: this.reportCity || this.tr("الكل") },
          {
            label: "حالة البيع",
            value: this.reportStatus
              ? this.tr(this.status(this.reportStatus))
              : this.tr("الكل"),
          },
        ];
      },
      reportExportSections(all) {
        return all
          ? this.reportSections
          : this.reportActive
            ? [
                {
                  ...this.reportActive,
                  columns: this.reportColumns,
                  rows: this.reportFilteredRows,
                },
              ]
            : [];
      },
      exportReport(all = false) {
        this.run(() => {
          this.engine.requirePermission("reports.export");
          if (this.reportBundle.error) throw Error(this.reportBundle.error);
          const sections = this.reportExportSections(all);
          if (!sections.length) throw Error("لا توجد بيانات للتصدير");
          const blocks = [
            csv(
              this.reportMetadata().map((m) => ({
                field: this.tr(m.label),
                value: m.value,
              })),
            ),
          ];
          for (const section of sections) {
            blocks.push(
              csv([
                {
                  section: this.tr(section.title),
                  note: this.tr(section.note),
                },
              ]),
            );
            const rows = section.rows.map((r) =>
              Object.fromEntries(
                section.columns.map((c) => [
                  this.tr(c.label),
                  this.reportCell(r[c.key], c),
                ]),
              ),
            );
            blocks.push(
              rows.length
                ? csv(rows)
                : csv([{ status: this.tr("لا توجد سجلات") }]),
            );
          }
          download(
            "masal-report-" + Masal.day() + ".csv",
            blocks.join("\r\n\r\n"),
            "text/csv;charset=utf-8",
          );
          this.engine.log("تصدير تقرير تفصيلي", "reports", null, {
            sections: sections.map((s) => s.id),
            rows: sections.reduce((n, s) => n + s.rows.length, 0),
          });
          this.notify("تم تنزيل التقرير");
        });
      },
      reportSnapshot() {
        return {
          metadata: this.reportMetadata(),
          note: this.tr(
            "الفلاتر الزمنية تخص الحركات؛ المخزون والشبكة والإعدادات تعرض الوضع الحالي. لا يتضمن التقرير رموز البطاقات.",
          ),
          empty: this.tr("لا توجد سجلات"),
          sections: this.reportExportSections(true).map((section) => ({
            ...section,
            title: this.tr(section.title),
            note: this.tr(section.note || ""),
            headers: section.columns.map((c) => this.tr(c.label)),
            formats: section.columns.map((c) =>
              c.type === "percent"
                ? "percent"
                : ["money", "number"].includes(c.type)
                  ? "number"
                  : "",
            ),
            displayRows: section.rows.map((row) =>
              section.columns.map((c) => this.reportCell(row[c.key], c)),
            ),
            values: section.rows.map((row) =>
              section.columns.map((c) =>
                ["money", "number", "percent"].includes(c.type) &&
                typeof row[c.key] === "number" &&
                Number.isFinite(row[c.key])
                  ? row[c.key]
                  : this.reportCell(row[c.key], c),
              ),
            ),
          })),
        };
      },
      reportDocument() {
        const snapshot = this.reportSnapshot(),
          sections = snapshot.sections,
          meta = snapshot.metadata,
          dir = this.lang === "en" ? "ltr" : "rtl";
        return (
          '<!doctype html><html lang="' +
          this.lang +
          '" dir="' +
          dir +
          '"><meta charset="utf-8"><title>' +
          esc(this.tr("تقرير النظام الشامل")) +
          '</title><style>body{font:15px Arial,sans-serif;color:#16344a;margin:32px}header{border-bottom:4px solid #00a8ac;padding-bottom:20px}h1{font-size:28px}h2{margin-top:35px;color:#007f88}p{line-height:1.8}.meta{display:flex;flex-wrap:wrap;gap:12px 28px}.meta span{background:#f0f7fa;padding:8px}table{width:100%;border-collapse:collapse;font-size:12px;margin:14px 0}th{background:#e8f4f6}td,th{border:1px solid #dbe5eb;padding:8px;text-align:start;overflow-wrap:anywhere}tr{break-inside:avoid}thead{display:table-header-group}.note{color:#536976}button{padding:12px;background:#007f88;color:white;border:0;border-radius:8px;cursor:pointer}@media print{button{display:none}body{margin:0}header{break-after:avoid}@page{size:A4 landscape;margin:12mm}} </style><body><button onclick="window.print()">' +
          esc(this.tr("طباعة / حفظ PDF")) +
          "</button><header><p>MASAL CARDS · " +
          esc(this.tr("مركز التقارير")) +
          "</p><h1>" +
          esc(this.tr("تقرير النظام الشامل")) +
          '</h1><div class="meta">' +
          meta
            .map(
              (m) =>
                "<span><b>" +
                esc(this.tr(m.label)) +
                ": </b>" +
                esc(m.value) +
                "</span>",
            )
            .join("") +
          '</div></header><p class="note">' +
          esc(
            this.tr(
              "الفلاتر الزمنية تخص الحركات؛ المخزون والشبكة والإعدادات تعرض الوضع الحالي. لا يتضمن التقرير رموز البطاقات.",
            ),
          ) +
          "</p>" +
          sections
            .map(
              (s) =>
                "<section><h2>" +
                esc(this.tr(s.title)) +
                " · " +
                s.rows.length +
                '</h2><p class="note">' +
                esc(this.tr(s.note)) +
                "</p><table><thead><tr>" +
                s.columns
                  .map((c) => "<th>" + esc(this.tr(c.label)) + "</th>")
                  .join("") +
                "</tr></thead><tbody>" +
                s.displayRows
                  .map(
                    (r) =>
                      "<tr>" +
                      r.map((value) => "<td>" + esc(value) + "</td>").join("") +
                      "</tr>",
                  )
                  .join("") +
                "</tbody></table>" +
                (!s.rows.length
                  ? "<p>" + esc(this.tr("لا توجد سجلات")) + "</p>"
                  : "") +
                "</section>",
            )
            .join("") +
          "<footer><p>MASAL · " +
          esc(this.tr("نسخة محلية")) +
          "</p></footer></body></html>"
        );
      },
      printReport() {
        this.run(() => {
          this.engine.requirePermission("reports.print");
          if (this.reportBundle.error) throw Error(this.reportBundle.error);
          const w = window.open("", "_blank");
          if (!w) throw Error("اسمح بفتح نافذة التقرير");
          w.document.write(this.reportDocument());
          w.document.close();
          this.engine.log("فتح تقرير للطباعة", "reports", null, {
            sections: this.reportSections.length,
          });
          w.focus();
        });
      },
      downloadReportDocument() {
        this.run(() => {
          this.engine.requirePermission("reports.export");
          if (this.reportBundle.error) throw Error(this.reportBundle.error);
          const snapshot = this.reportSnapshot();
          const sheets = [
            {
              name: this.tr("تقرير النظام الشامل"),
              note: snapshot.note,
              headers: [this.tr("الحقل"), this.tr("القيمة")],
              rows: snapshot.metadata.map((m) => [this.tr(m.label), m.value]),
            },
            ...snapshot.sections.map((section) => ({
              name: section.title,
              title: section.title + " · " + section.rows.length,
              note: section.note,
              headers: section.headers,
              formats: section.formats,
              rows: section.values,
              emptyMessage: snapshot.empty,
            })),
          ];
          download(
            "masal-report-" + Masal.day() + ".xlsx",
            MasalExcel.reportWorkbook(sheets, { rtl: this.lang !== "en" }),
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
          );
          this.notify("تم تنزيل التقرير بصيغة Excel");
          this.engine.log("تنزيل تقرير شامل", "reports", null, {
            sections: this.reportSections.length,
          });
        });
      },
    });
    const guards = {
      openEdit: function (row) {
        return this.page + "." + (row ? "edit" : "create");
      },
      saveEntity: function () {
        return this.page + "." + (this.editForm.id ? "edit" : "create");
      },
      toggleEntity: function () {
        return this.page + ".toggle";
      },
      logoutPOS: "pos.logout",
      logoutAll: "security.logoutAll",
      downloadImportTemplate: "import.template",
      readImport: "import.preview",
      nextImport: function () {
        return this.importStep === 2 ? "import.approve" : "import.preview";
      },
      inspectBatch: "inventory.details",
      doBatch: function (b, action) {
        return action === "cancel"
          ? "inventory.cancel"
          : "inventory.quarantine";
      },
      askCancelBatch: "inventory.cancel",
      reviewTransfer: "wallets.transfer",
      confirmTransfer: "wallets.transfer",
      transfer: "wallets.transfer",
      openDeposit: "wallets.deposit",
      deposit: "wallets.deposit",
      downloadPrices: "prices.template",
      importPrices: "prices.import",
      submitPrices: "prices.propose",
      approvePrices: "prices.approve",
      reversePrices: "prices.reverse",
      sell: "sell.create",
      viewReceipt: "sell.receipt",
      printReceipt: "sell.print",
      printResult: "sell.result",
      openReprint: "sell.reprint",
      submitReprint: "sell.reprint",
      createClaim: "claims.create",
      settle: "claims.settle",
      exportEncrypted: "exports.encrypt",
      saveIntegration: "integrations.edit",
      sendNotification: "notifications.send",
      openTicket: "support.create",
      saveTicket: "support.create",
      showTicket: "support.view",
      replyTicket: "support.reply",
      ticketStatus: function (s) {
        return s === "مغلقة" ? "support.close" : "support.escalate";
      },
      saveSettings: "security.policies",
      saveBrand: "branding.edit",
      submitContact: "landing.contact",
      backup: "backup.download",
      prepareRestore: "backup.restore",
      inspect: "audit.details",
    };
    for (const [name, key] of Object.entries(guards)) {
      const original = o.methods[name];
      o.methods[name] = function (...args) {
        try {
          this.engine.requirePermission(
            typeof key === "function" ? key.apply(this, args) : key,
          );
          if (
            [
              "sell",
              "viewReceipt",
              "printReceipt",
              "openReprint",
              "submitReprint",
              "exportEncrypted",
            ].includes(name)
          )
            this.engine.requirePermission("data.pin");
          if (name === "saveEntity") validateEntity(this);
          validateAction(this, name, args);
          return original.apply(this, args);
        } catch (e) {
          this.notify(e.message, true);
        }
      };
    }
    const baseExport = o.methods.exportCurrent;
    o.methods.exportCurrent = function () {
      if (this.page === "reports") return this.exportReport(true);
      if (this.page === "permissions") return this.exportPermissions();
      return this.run(() => {
        this.engine.requirePermission(this.page + ".export");
        if (
          [
            "inventory",
            "import",
            "batches",
            "claims",
            "exports",
            "products",
          ].includes(this.page) &&
          !this.can("data.cost")
        )
          throw Error("تصدير هذا السجل يتطلب صلاحية التكلفة");
        baseExport.call(this);
      });
    };
    const originalDisplay = o.methods.displayField;
    o.methods.displayField = function (row, col) {
      if (
        ["min", "cost", "expenses"].includes(col.key) &&
        !this.can("data.cost")
      )
        return "••••";
      return originalDisplay.call(this, row, col);
    };
    const openEdit = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      openEdit.call(this, row);
      if (!row && this.page === "users" && this.modal?.kind === "edit") {
        this.editForm.role = "employee";
        this.editForm.assignedText = "";
      }
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted.call(this);
      this.permissionUser =
        this.permissionUsers.find((u) => u.role === "employee")?.id ||
        this.permissionUsers.find((u) => u.role !== "owner")?.id ||
        this.currentUser;
      this.loadPermissions();
    };
    const switchUser = o.methods.switchUser;
    o.methods.switchUser = function () {
      switchUser.call(this);
      this.permissionUser =
        this.permissionUsers.find((u) => u.id === this.currentUser)?.id || "";
      this.loadPermissions();
      this.resetReport();
    };
    for (const key of [
      "reportFrom",
      "reportTo",
      "reportAgent",
      "reportPOS",
      "reportProduct",
      "reportProvider",
      "reportCity",
      "reportStatus",
      "reportKind",
      "reportSearch",
      "reportPageSize",
    ])
      o.watch[key] = function () {
        this.reportPage = 1;
      };
    o.watch.reportAgent = function () {
      this.reportPOS = "";
      this.reportPage = 1;
    };
  }
  function validateEntity(vm) {
    const v = vm.editForm,
      key = vm.schema.key,
      old = vm.s[key].find((r) => r.id === v.id);
    if (key === "pos" && !old && v.serialBinding)
      vm.engine.requirePermission("pos.device");
    if (old && key === "pos")
      for (const [permission, fields] of [
        ["device", ["serial", "model", "version", "serialBinding"]],
        ["location", ["lat", "lng", "city", "address"]],
        ["reprintLimit", ["reprint"]],
      ])
        if (fields.some((f) => String(v[f] ?? "") !== String(old[f] ?? "")))
          vm.engine.requirePermission("pos." + permission);
    if (old && key === "products")
      for (const [permission, fields] of [
        ["priceFloor", ["min"]],
        ["limits", ["limit", "dailyQty", "dailyAmount"]],
      ])
        if (fields.some((f) => String(v[f]) !== String(old[f])))
          vm.engine.requirePermission("products." + permission);
    if (key === "pos")
      for (const [permission, fields] of [
        ["documents", ["documents", "personalImage"]],
        ["representatives", ["representativeIds"]],
        ["type", ["posTypeId"]],
      ])
        if (
          fields.some(
            (f) =>
              JSON.stringify(v[f] ?? null) !==
                JSON.stringify(old?.[f] ?? null) &&
              (old || (v[f] && (!Array.isArray(v[f]) || v[f].length))),
          )
        )
          vm.engine.requirePermission("pos." + permission);
    if (key !== "users") return;
    if (
      !["owner", "supervisor", "main", "sub", "pos", "employee"].includes(
        v.role,
      )
    )
      throw Error("دور غير صالح");
    if (old) {
      if (v.role !== old.role) vm.engine.requirePermission("users.role");
      if (
        v.agent !== old.agent ||
        v.pos !== old.pos ||
        JSON.stringify(
          (v.assignedText || "")
            .split(",")
            .map((x) => x.trim())
            .filter(Boolean),
        ) !== JSON.stringify(old.assigned || [])
      )
        vm.engine.requirePermission("users.scope");
      if (old.role === "owner" && vm.actor.role !== "owner")
        throw Error("حساب الإدارة محمي");
      if (
        vm.actor.role !== "owner" &&
        !A.scope(vm.s, old).every((a) => vm.engine.allowed(a))
      )
        throw Error("الموظف خارج نطاقك");
      v.access = Masal.clone(old.access || { overrides: {} });
      v.active = old.active;
    } else {
      delete v.access;
      if (
        vm.actor.role !== "owner" &&
        A.catalog.some((p) => A.defaults(v.role, p.key) && !vm.can(p.key))
      )
        throw Error("الدور يتضمن صلاحيات لا تملكها؛ استخدم دور موظف النظام");
    }
    if (
      old &&
      v.role !== old.role &&
      vm.actor.role !== "owner" &&
      A.catalog.some(
        (p) =>
          A.can({ ...old, role: v.role, active: true }, p.key) &&
          !A.can({ ...old, active: true }, p.key) &&
          !vm.can(p.key),
      )
    )
      throw Error("الدور يتضمن صلاحيات لا تملكها");
    const roots = ["supervisor", "employee"].includes(v.role)
      ? (v.assignedText || "")
          .split(",")
          .map((x) => x.trim())
          .filter(Boolean)
      : [v.agent];
    if (
      v.role !== "owner" &&
      roots.some(
        (a) => !vm.s.agents.some((x) => x.id === a) || !vm.engine.allowed(a),
      )
    )
      throw Error("نطاق الموظف غير صالح");
  }
  function validateAction(vm, name, args) {
    if (["inspectBatch", "showTicket", "openReprint"].includes(name))
      vm.engine.require(args[0]?.agent);
    if (["downloadPrices", "importPrices"].includes(name))
      vm.engine.require(vm.priceAgent);
    if (name === "openEdit" && args[0]) {
      const row = args[0];
      if (vm.page === "agents") vm.engine.require(row.id);
      if (vm.page === "pos") vm.engine.require(row.agent);
      if (
        vm.page === "users" &&
        vm.actor.role !== "owner" &&
        (row.role === "owner" ||
          !A.scope(vm.s, row).length ||
          !A.scope(vm.s, row).every((a) => vm.engine.allowed(a)))
      )
        throw Error("الموظف خارج نطاق صلاحياتك");
    }
    if (
      ["backup", "prepareRestore"].includes(name) &&
      vm.actor.role !== "owner"
    )
      throw Error("النسخ الكاملة والاستعادة متاحة لمدير النظام فقط");
    if (name === "saveSettings")
      for (const key of [
        "velocity",
        "expiryDays",
        "providerDaily",
        "reprint",
        "minVersion",
        "idle",
        "blockedIPs",
      ])
        if (String(vm.settingsDraft[key]) !== String(vm.s.settings[key]))
          vm.engine.requirePermission("security." + key);
    if (name === "saveBrand") {
      const old = vm.s.agents.find((a) => a.id === vm.brandAgent) || {};
      for (const [permission, fields] of [
        ["receipt", ["header", "footer", "support", "width", "reprint"]],
        ["identity", ["color", "logo"]],
      ])
        if (
          fields.some(
            (f) => String(vm.brandDraft[f] ?? "") !== String(old[f] ?? ""),
          )
        )
          vm.engine.requirePermission("branding." + permission);
    }
    if (name === "toggleEntity" && vm.page === "users") {
      const u = args[0];
      if (u.role === "owner" && vm.actor.role !== "owner")
        throw Error("حساب الإدارة محمي");
      if (
        vm.actor.role !== "owner" &&
        (!A.scope(vm.s, u).length ||
          !A.scope(vm.s, u).every((a) => vm.engine.allowed(a)))
      )
        throw Error("الموظف خارج نطاق صلاحياتك");
      if (
        u.active &&
        u.role === "owner" &&
        vm.s.users.filter((x) => x.active && x.role === "owner").length <= 1
      )
        throw Error("يجب إبقاء مدير نظام فعال");
    }
    if (name === "inspect") {
      const scrub = (value) => {
        if (Array.isArray(value)) return value.map(scrub);
        if (value && typeof value === "object")
          return Object.fromEntries(
            Object.entries(value).map(([k, v]) => [
              k,
              [
                "pin",
                "cvc",
                "password",
                "credentials",
                "hash",
                "salt",
              ].includes(k) ||
              (!vm.can("data.cost") &&
                ["cost", "expenses", "min", "value"].includes(k)) ||
              (!vm.can("data.profit") && k === "profit")
                ? "••••"
                : scrub(v),
            ]),
          );
        return value;
      };
      args[0] = scrub(args[0]);
    }
  }
  root.MasalEnhancements = { install };
})(globalThis);

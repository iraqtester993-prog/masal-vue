(function (root) {
  "use strict";
  function batchActionAllowed(s, b, action) {
    if (action === "restore")
      return !!b && b.status === "Cancelled by Reversal";
    if (!b || ["Exported", "Cancelled by Reversal"].includes(b.status))
      return false;
    const cards = s.cards.filter((c) => c.batch === b.id);
    if (action === "resume")
      return (
        b.status === "Quarantined" &&
        !s.claims.some((c) => c.batch === b.id && c.status === "معلقة") &&
        !s.exports.some((e) => e.batch === b.id) &&
        !(s.exportRequests || []).some(
          (r) =>
            r.batch === b.id &&
            ["بانتظار الاعتماد", "معتمد", "تم التنزيل"].includes(r.status),
        ) &&
        cards.some(
          (c) => c.status === "Quarantined" && c.expiry > root.Masal.day(),
        )
      );
    if (action === "quarantine")
      return cards.some((c) => c.status === "Available");
    if (action === "cancel")
      return (
        cards.length > 0 &&
        cards.every((c) => ["Available", "Quarantined"].includes(c.status)) &&
        !s.claims.some((c) => c.batch === b.id)
      );
    return false;
  }
  const originalBatchAction = root.Masal.Engine.prototype.batchAction;
  root.Masal.Engine.prototype.batchAction = function (id, action) {
    const b = this.s.batches.find((b) => b.id === id);
    if (b && ["Exported", "Cancelled by Reversal"].includes(b.status))
      throw Error("الدفعة مصدّرة أو ملغاة؛ لا يمكن حجرها أو إلغاؤها");
    return originalBatchAction.call(this, id, action);
  };
  root.Masal.Engine.prototype.inventoryAction = function (id, action, reason) {
    const b = this.s.batches.find((b) => b.id === id);
    if (action === "restore" && this.actor().role !== "owner")
      throw Error("استرجاع الطلبية الملغاة لمدير النظام فقط");
    this.requirePermission(
      action === "cancel" ? "inventory.cancel" : "inventory.quarantine",
    );
    this.require(b?.agent);
    reason = String(reason || "").trim();
    if (!reason) throw Error("سبب الإجراء مطلوب");
    if (!batchActionAllowed(this.s, b, action))
      throw Error(
        "حالة الطلبية لا تسمح بهذا الإجراء؛ راجع المطالبة أو طلب التصدير إن وجد",
      );
    return root.MasalMeetingRules.atomic(this, () => {
      if (action === "restore") {
        const cards = this.s.cards.filter((c) => c.batch === id),
          invoice = this.s.batchInvoices.find((i) => i.batch === id);
        if (!invoice || invoice.status !== "معكوسة")
          throw Error("لا توجد فاتورة معكوسة قابلة للاسترجاع");
        if (
          !cards.length ||
          cards.some(
            (c) =>
              c.status !== "Cancelled by Reversal" || c.sale || !c.creditHeld,
          )
        )
          throw Error("حالة بطاقات الطلبية لا تسمح بالاسترجاع");
        if (cards.some((c) => !c.expiry || c.expiry <= root.Masal.day()))
          throw Error("تحتوي الطلبية بطاقات منتهية؛ تعذر استرجاع الطلبية");
        if (
          this.s.claims.some((c) => c.batch === id) ||
          this.s.exports.some((e) => e.batch === id) ||
          (this.s.exportRequests || []).some(
            (r) =>
              r.batch === id &&
              ["بانتظار الاعتماد", "معتمد", "تم التنزيل"].includes(r.status),
          )
        )
          throw Error("الطلبية مرتبطة بمطالبة أو تصدير");
        const serials = new Set(),
          pins = new Set();
        for (const c of cards) {
          if (
            (c.serial && serials.has(c.serial)) ||
            (c.pin && pins.has(c.pin)) ||
            this.s.cards.some(
              (x) =>
                x.batch !== id &&
                x.status !== "Cancelled by Reversal" &&
                ((c.serial && x.serial === c.serial) ||
                  (c.pin && x.pin === c.pin)),
            )
          )
            throw Error("توجد بيانات بطاقات مكررة؛ تعذر استرجاع الطلبية");
          if (c.serial) serials.add(c.serial);
          if (c.pin) pins.add(c.pin);
        }
        const credit = cards.reduce((n, c) => n + (c.credit ?? c.cost), 0);
        cards.forEach((c) => {
          c.status = "Available";
          delete c.creditHeld;
        });
        this.serviceEntry(
          b.agent,
          "voucher",
          credit,
          "استرجاع طلبية ملغاة",
          id,
        );
        invoice.status = "مرحلة";
        b.status = "Loaded";
        this.refreshBatch(id);
        b.restoredAt = new Date().toISOString();
        b.restoredBy = this.user;
        this.log("استرجاع طلبية ملغاة", id, null, {
          reason,
          invoice: invoice.id,
          amount: invoice.amount,
          credit,
          quantity: cards.length,
        });
      } else if (action === "resume") {
        const cards = this.s.cards.filter(
          (c) =>
            c.batch === id &&
            c.status === "Quarantined" &&
            c.expiry > root.Masal.day(),
        );
        const credit = cards
          .filter((c) => c.creditHeld)
          .reduce((n, c) => n + (c.credit ?? c.cost), 0);
        cards.forEach((c) => {
          c.status = "Available";
          delete c.creditHeld;
        });
        if (credit)
          this.serviceEntry(
            b.agent,
            "voucher",
            credit,
            "إعادة تفعيل بطاقات",
            id,
          );
        b.status = "Loaded";
        this.refreshBatch(id);
      } else this.batchAction(id, action);
      b.lastActionReason = reason;
      if (action !== "restore")
        this.log(
          {
            resume: "إعادة تفعيل طلبية",
            cancel: "سبب إلغاء الطلبية",
            quarantine: "سبب إيقاف البيع",
          }[action],
          id,
          null,
          { reason },
        );
      return b;
    });
  };
  function install(o) {
    o.methods.openInventoryAction = function (b, action) {
      this.reason = "";
      const titles = {
        restore: "استرجاع الطلبية",
        quarantine: "إيقاف البيع",
        resume: "إعادة تفعيل",
        cancel: "إلغاء الطلبية",
        claim: "فتح مطالبة",
      };
      this.modal = {
        kind: "inventoryAction",
        title: titles[action],
        batch: b,
        action,
        message:
          action === "restore"
            ? "سيتم فحص جميع البطاقات وإعادة الطلبية ورصيدها والفاتورة الأصلية دون تسجيل تحصيل جديد. لن يتم الاسترجاع إذا وجدت بطاقات منتهية أو مكررة."
            : action === "cancel"
              ? "سيتم إلغاء البطاقات وعكس الفاتورة والرصيد التشغيلي. لا يتم إرجاع أي مبلغ نقدي تلقائيًا."
              : action === "resume"
                ? "ستعود البطاقات غير المنتهية للبيع ويستعاد رصيدها المعلّق."
                : action === "claim"
                  ? "سيتم إيقاف البطاقات المتبقية وفتح مطالبة لمتابعة الاستبدال أو التعويض."
                  : "سيتم إيقاف بيع البطاقات المتبقية وتعليق رصيدها التشغيلي.",
      };
    };
    o.methods.confirmInventoryAction = function () {
      this.run(() => {
        const m = this.modal;
        if (m.action === "claim") this.engine.claim(m.batch.id, this.reason);
        else this.engine.inventoryAction(m.batch.id, m.action, this.reason);
        this.closeModal();
      }, "تم تنفيذ الإجراء وتسجيل السبب");
    };
    o.methods.canOpenInventoryClaim = function (b) {
      return (
        this.can("claims.create") &&
        !this.s.claims.some((c) => c.batch === b.id && c.status === "معلقة") &&
        this.s.cards.some(
          (c) =>
            c.batch === b.id && ["Available", "Quarantined"].includes(c.status),
        )
      );
    };
    o.methods.canBatchAction = function (b, action) {
      return (
        (action !== "restore" || this.actor.role === "owner") &&
        this.can(
          action === "cancel" ? "inventory.cancel" : "inventory.quarantine",
        ) &&
        batchActionAllowed(this.s, b, action)
      );
    };
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        inventoryAgent: "",
        inventoryProduct: "",
        inventoryTab: "all",
        inventoryDateFrom: "",
        inventoryDateTo: "",
      };
    };
    o.computed.inventoryAgents = function () {
      return this.visibleAgents
        .filter((a) => a.type === "رئيسي")
        .slice()
        .sort((a, b) => a.name.localeCompare(b.name, "ar"));
    };
    const batches = o.computed.batchRows;
    o.computed.batchRows = function () {
      const rows = batches.call(this);
      if (!["inventory", "batches"].includes(this.page)) return rows;
      return rows
        .filter((b) => {
          const day = b.created ? Masal.businessDay(b.created) : "";
          return (
            (!this.inventoryAgent || b.agent === this.inventoryAgent) &&
            (!this.inventoryProduct || b.product === this.inventoryProduct) &&
            (!this.inventoryDateFrom ||
              (day && day >= this.inventoryDateFrom)) &&
            (!this.inventoryDateTo || (day && day <= this.inventoryDateTo)) &&
            (this.inventoryTab === "all" ||
              (this.inventoryTab === "active"
                ? ["Loaded", "Partially Used"].includes(b.status)
                : b.status ===
                  (this.inventoryTab === "stopped"
                    ? "Quarantined"
                    : "Cancelled by Reversal")))
          );
        })
        .slice()
        .sort((a, b) =>
          this.nameOf("agents", a.agent).localeCompare(
            this.nameOf("agents", b.agent),
            "ar",
          ),
        );
    };
    o.computed.inventoryCards = function () {
      const ids = new Set(this.batchRows.map((b) => b.id));
      return this.visibleCards.filter(
        (c) =>
          ids.has(c.batch) &&
          (!this.inventoryAgent || c.agent === this.inventoryAgent),
      );
    };
    o.computed.inventoryAvailableCards = function () {
      return this.inventoryCards.filter(
        (c) => c.status === "Available" && c.expiry > Masal.day(),
      );
    };
    o.computed.inventoryFiltersActive = function () {
      return !!(
        this.inventoryAgent ||
        this.inventoryProduct ||
        this.statusFilter ||
        this.search ||
        this.inventoryTab !== "all" ||
        this.inventoryDateFrom ||
        this.inventoryDateTo
      );
    };
    const change = o.methods.switchUser;
    o.methods.switchUser = function (...args) {
      this.inventoryAgent = "";
      this.inventoryProduct = "";
      return change.apply(this, args);
    };
    o.methods.selectInventoryAgent = function () {
      this.dashboardFilter = null;
    };
    o.methods.clearInventoryFilters = function () {
      this.inventoryAgent = "";
      this.inventoryProduct = "";
      this.inventoryDateFrom = "";
      this.inventoryDateTo = "";
      this.statusFilter = "";
      this.search = "";
      this.inventoryTab = "all";
      this.dashboardFilter = null;
    };
    const panel = o.components["operations-panel"],
      invoices = panel.computed.invoices;
    panel.computed.invoices = function () {
      const rows = invoices.call(this);
      if (!["inventory", "batches"].includes(this.page)) return rows;
      const ids = new Set(this.vm.batchRows.map((b) => b.id));
      return rows.filter(
        (i) =>
          ids.has(i.batch) &&
          (!this.vm.inventoryAgent || i.agent === this.vm.inventoryAgent),
      );
    };
  }
  root.MasalInventoryUI = { install };
})(globalThis);

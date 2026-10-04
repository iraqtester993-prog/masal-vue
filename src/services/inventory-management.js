(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    now = () => new Date().toISOString(),
    round = (n) => Math.round(n * 100) / 100;
  const atomic = (e, fn) => root.MasalMeetingRules.atomic(e, fn);
  function batch(e, id) {
    const b = e.s.batches.find((x) => x.id === id);
    if (!b) throw Error("الدفعة غير موجودة");
    e.require(b.agent);
    return b;
  }
  function selected(e, b, ids) {
    if (!Array.isArray(ids) || !ids.length || new Set(ids).size !== ids.length)
      throw Error("حدد البطاقات أولًا");
    const cards = ids.map((id) =>
      e.s.cards.find((c) => c.id === id && c.batch === b.id),
    );
    if (cards.some((c) => !c)) throw Error("بطاقة خارج الدفعة");
    return cards;
  }
  function cleanReason(reason) {
    reason = String(reason || "").trim();
    if (!reason) throw Error("سبب الإجراء مطلوب");
    return reason;
  }
  function linked(s, b, ids) {
    return (
      s.claims.some(
        (c) =>
          c.batch === b.id &&
          c.status === "معلقة" &&
          (!c.cardIds || c.cardIds.some((id) => ids.includes(id))),
      ) ||
      (s.exportRequests || []).some(
        (r) =>
          r.batch === b.id && ["بانتظار الاعتماد", "معتمد"].includes(r.status),
      )
    );
  }
  function summarize(e, b) {
    const cards = e.s.cards.filter((c) => c.batch === b.id);
    b.status = cards.every((c) => c.status === "Cancelled by Reversal")
      ? "Cancelled by Reversal"
      : cards.some((c) => c.status === "Available")
        ? cards.every((c) => c.status === "Available")
          ? "Loaded"
          : "Partially Used"
        : cards.some((c) => c.status === "Quarantined")
          ? "Quarantined"
          : cards.every((c) => c.status === "Exported")
            ? "Exported"
            : "Completed";
  }
  P.markInventoryDamaged = function (id, ids, reason) {
    this.requirePermission("claims.create");
    const b = batch(this, id),
      cards = selected(this, b, ids);
    reason = cleanReason(reason);
    if (
      cards.some(
        (c) => c.sale || !["Available", "Quarantined"].includes(c.status),
      ) ||
      linked(this.s, b, ids)
    )
      throw Error("حدد بطاقات متاحة أو موقوفة غير مرتبطة بمطالبة أو سحب قائم");
    const credit = round(cards.reduce((n, c) => n + (c.credit ?? c.cost), 0)),
      debit = round(
        cards
          .filter((c) => !c.creditHeld)
          .reduce((n, c) => n + (c.credit ?? c.cost), 0),
      );
    if (this.serviceAvailable(b.agent) < debit)
      throw Error("استرجع الرصيد الموزع قبل تعليق هذه البطاقات");
    return atomic(this, () => {
      const c = {
        id: M.id("CL"),
        batch: id,
        agent: b.agent,
        product: b.product,
        reason,
        status: "معلقة",
        quantity: cards.length,
        cardIds: [...ids],
        value: round(cards.reduce((n, c) => n + c.cost, 0)),
        credit,
        time: now(),
        user: this.user,
        inventoryDamage: true,
      };
      cards.forEach((x) => {
        x.status = "Quarantined";
        x.creditHeld = true;
        x.damageClaim = c.id;
      });
      if (debit)
        this.serviceEntry(
          b.agent,
          "voucher",
          -debit,
          "تعليق رصيد بطاقات تالفة",
          c.id,
        );
      this.s.claims.unshift(c);
      this.s.claimLedger ??= [];
      this.s.claimLedger.push({
        id: M.id("CLL"),
        claim: c.id,
        agent: c.agent,
        kind: "تعليق قيمة بطاقات",
        amount: c.value,
        time: c.time,
      });
      summarize(this, b);
      this.log("تعليم بطاقات كتالف", c.id, null, {
        batch: id,
        quantity: c.quantity,
        reason,
        credit,
      });
      return c;
    });
  };
  P.cancelInventoryRemainder = function (id, reason) {
    this.requirePermission("inventory.cancel");
    const b = batch(this, id);
    reason = cleanReason(reason);
    const cards = this.s.cards.filter(
      (c) =>
        c.batch === id &&
        !c.sale &&
        ["Available", "Quarantined"].includes(c.status),
    );
    if (!cards.length) throw Error("لا توجد بطاقات متبقية قابلة للإلغاء");
    if (
      linked(
        this.s,
        b,
        cards.map((c) => c.id),
      )
    )
      throw Error("عالج المطالبة أو طلب السحب قبل الإلغاء");
    const invoice = this.s.batchInvoices.find(
      (i) => i.batch === id && !i.inventoryAdjustment,
    );
    if (!invoice || invoice.status === "معكوسة")
      throw Error("لا توجد فاتورة أصلية قابلة للتسوية");
    const debit = round(
      cards
        .filter((c) => !c.creditHeld)
        .reduce((n, c) => n + (c.credit ?? c.cost), 0),
    );
    if (this.serviceAvailable(b.agent) < debit)
      throw Error("استرجع الرصيد الموزع قبل إلغاء البطاقات");
    return atomic(this, () => {
      const r = {
        id: M.id("IC"),
        batch: id,
        agent: b.agent,
        cardIds: cards.map((c) => c.id),
        quantity: cards.length,
        reason,
        time: now(),
        user: this.user,
        status: "ملغاة",
        credit: round(cards.reduce((n, c) => n + (c.credit ?? c.cost), 0)),
      };
      const fraction = cards.length / invoice.quantity,
        amount = round(invoice.amount * fraction),
        cost = round(invoice.cost * fraction),
        profit = round(invoice.profit * fraction);
      this.s.inventoryCancellations ??= [];
      this.s.inventoryCancellations.unshift(r);
      cards.forEach((c) => {
        c.status = "Cancelled by Reversal";
        c.creditHeld = true;
        c.inventoryCancellation = r.id;
      });
      if (debit)
        this.serviceEntry(
          b.agent,
          "voucher",
          -debit,
          "إلغاء المتبقي من الطلبية",
          r.id,
        );
      this.s.batchInvoices.push({
        ...M.clone(invoice),
        id: M.id("INV"),
        key: r.id,
        inventoryAdjustment: r.id,
        quantity: -cards.length,
        amount: -amount,
        cost: -cost,
        profit: -profit,
        time: r.time,
        status: "تسوية إلغاء المتبقي",
      });
      summarize(this, b);
      this.log("إلغاء المتبقي من الطلبية", r.id, null, {
        batch: id,
        quantity: r.quantity,
        reason,
        amount,
        credit: r.credit,
      });
      return r;
    });
  };
  P.restoreInventoryCancellation = function (id, reason) {
    if (this.actor().role !== "owner")
      throw Error("استرجاع الملغي لمدير النظام فقط");
    reason = cleanReason(reason);
    const r = (this.s.inventoryCancellations || []).find((x) => x.id === id);
    if (!r || r.status !== "ملغاة")
      throw Error("عملية الإلغاء غير متاحة للاسترجاع");
    const b = batch(this, r.batch),
      cards = selected(this, b, r.cardIds);
    if (
      cards.some(
        (c) =>
          c.status !== "Cancelled by Reversal" ||
          c.sale ||
          !c.creditHeld ||
          c.expiry <= M.day() ||
          !c.expiry,
      )
    )
      throw Error("بطاقات منتهية أو مستخدمة؛ تعذر الاسترجاع");
    if (linked(this.s, b, r.cardIds))
      throw Error("توجد مطالبة أو عملية سحب معلقة");
    const serials = new Set(),
      pins = new Set();
    for (const c of cards) {
      if (
        (c.serial && serials.has(c.serial)) ||
        (c.pin && pins.has(c.pin)) ||
        this.s.cards.some(
          (x) =>
            !r.cardIds.includes(x.id) &&
            x.status !== "Cancelled by Reversal" &&
            ((c.serial && c.serial === x.serial) || (c.pin && c.pin === x.pin)),
        )
      )
        throw Error("توجد رموز مكررة");
      if (c.serial) serials.add(c.serial);
      if (c.pin) pins.add(c.pin);
    }
    const adjustment = this.s.batchInvoices.find(
      (i) => i.inventoryAdjustment === r.id,
    );
    if (!adjustment) throw Error("تسوية الإلغاء غير موجودة");
    return atomic(this, () => {
      cards.forEach((c) => {
        c.status = "Available";
        delete c.creditHeld;
        delete c.inventoryCancellation;
      });
      this.serviceEntry(
        b.agent,
        "voucher",
        r.credit,
        "استرجاع بطاقات ملغاة",
        r.id,
      );
      this.s.batchInvoices.push({
        ...M.clone(adjustment),
        id: M.id("INV"),
        key: r.id + ":restore",
        quantity: -adjustment.quantity,
        amount: -adjustment.amount,
        cost: -adjustment.cost,
        profit: -adjustment.profit,
        status: "تسوية استرجاع",
        time: now(),
      });
      r.status = "مسترجعة";
      r.restoredBy = this.user;
      r.restoreReason = reason;
      r.restoredAt = now();
      summarize(this, b);
      this.log("استرجاع المتبقي الملغي", r.id, null, {
        batch: b.id,
        quantity: cards.length,
        reason,
      });
      return r;
    });
  };
  P.editInventoryMetadata = function (id, data, reason) {
    this.requirePermission("inventory.edit");
    const b = batch(this, id);
    reason = cleanReason(reason);
    const before = { city: b.city, supplier: b.supplier, notes: b.notes };
    const after = {
      city: String(data.city || "").trim(),
      supplier: String(data.supplier || "").trim(),
      notes: String(data.notes || "").trim(),
    };
    if (!after.city || !after.supplier) throw Error("المحافظة والمصدر مطلوبان");
    Object.assign(b, after);
    this.log("تعديل بيانات الطلبية", id, before, { ...after, reason });
    return b;
  };
  P.inventoryCopyPayload = function (id, ids, secrets) {
    this.requirePermission("exports.encrypt");
    if (secrets) this.requirePermission("data.pin");
    const b = batch(this, id),
      cards = selected(this, b, ids);
    return {
      batch: id,
      agent: b.agent,
      product: b.product,
      purpose: "inventory-copy",
      cards: cards.map((c) => ({
        serial: c.serial,
        expiry: c.expiry,
        status: c.status,
        ...(secrets
          ? { pin: c.pin, cvc: c.cvc || "", reference: c.reference || "" }
          : {}),
      })),
    };
  };
  const requestExport = P.requestExport;
  P.requestExport = function (id, reason) {
    if (
      this.s.claims.some(
        (c) => c.batch === id && c.inventoryDamage && c.status === "معلقة",
      )
    )
      throw Error("سوّ مطالبة البطاقات المحددة أولًا قبل سحب الدفعة كاملة");
    return requestExport.call(this, id, reason);
  };
  // Selected-card claims must not change the remaining cards or the batch's usable state.
  const settle = P.settle;
  P.settle = function (id, outcome, details) {
    const c = this.s.claims.find((x) => x.id === id);
    if (
      c?.inventoryDamage &&
      outcome === "إعادة تفعيل" &&
      this.s.cards.some(
        (x) => c.cardIds.includes(x.id) && (!x.expiry || x.expiry <= M.day()),
      )
    )
      throw Error("لا يمكن إعادة بطاقة منتهية للبيع");
    const r = settle.call(this, id, outcome, details);
    if (c?.inventoryDamage) {
      const b = this.s.batches.find((x) => x.id === c.batch);
      summarize(this, b);
    }
    return r;
  };
  const manager = {
    props: ["batchId"],
    data() {
      return {
        action: "",
        reason: "",
        chosen: [],
        search: "",
        state: "",
        page: 1,
        busy: false,
        error: "",
        password: "",
        format: "encrypted",
        preview: null,
        metadata: {},
        restoreId: "",
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      b() {
        return this.vm.visibleBatches.find((b) => b.id === this.batchId);
      },
      cards() {
        return this.vm.visibleCards.filter((c) => c.batch === this.batchId);
      },
      filtered() {
        return this.cards.filter(
          (c) =>
            (!this.search ||
              String(c.serial || c.id)
                .toLowerCase()
                .includes(this.search.toLowerCase())) &&
            (!this.state || c.status === this.state),
        );
      },
      rows() {
        return this.filtered.slice((this.page - 1) * 20, this.page * 20);
      },
      remaining() {
        return this.cards.filter(
          (c) => !c.sale && ["Available", "Quarantined"].includes(c.status),
        );
      },
      restores() {
        return (this.vm.s.inventoryCancellations || []).filter(
          (r) => r.batch === this.batchId && r.status === "ملغاة",
        );
      },
      choices() {
        return this.cards.filter((c) => this.chosen.includes(c.id));
      },
      canRestore() {
        return (
          this.vm.actor.role === "owner" &&
          (this.restores.length || this.vm.canBatchAction(this.b, "restore"))
        );
      },
    },
    watch: {
      search() {
        this.page = 1;
      },
      state() {
        this.page = 1;
      },
      action() {
        this.preview = null;
        this.error = "";
        this.reason = "";
        this.password = "";
        this.metadata = {
          city: this.b.city || "",
          supplier: this.b.supplier || "",
          notes: this.b.notes || "",
        };
        this.restoreId = this.restores[0]?.id || "";
        if (this.action === "copy" && !this.vm.can("data.pin"))
          this.format = "csv";
      },
      "vm.currentUser"() {
        this.vm.closeModal();
      },
    },
    methods: {
      selectVisible() {
        const ids = this.filtered.map((c) => c.id);
        this.chosen = ids.every((id) => this.chosen.includes(id))
          ? this.chosen.filter((id) => !ids.includes(id))
          : [...new Set([...this.chosen, ...ids])];
      },
      review() {
        this.error = "";
        try {
          const e = this.vm.engine;
          if (!this.action) throw Error("اختر الإجراء");
          cleanReason(this.reason);
          const clone = new M.Engine(M.clone(this.vm.s), this.vm.currentUser);
          if (this.action === "damage")
            clone.markInventoryDamaged(this.batchId, this.chosen, this.reason);
          if (this.action === "cancel")
            clone.cancelInventoryRemainder(this.batchId, this.reason);
          if (this.action === "edit")
            clone.editInventoryMetadata(
              this.batchId,
              this.metadata,
              this.reason,
            );
          if (this.action === "restore") {
            if (this.restoreId)
              clone.restoreInventoryCancellation(this.restoreId, this.reason);
            else clone.inventoryAction(this.batchId, "restore", this.reason);
          }
          if (["quarantine", "resume"].includes(this.action))
            clone.inventoryAction(this.batchId, this.action, this.reason);
          if (this.action === "copy") {
            e.inventoryCopyPayload(
              this.batchId,
              this.chosen,
              this.format === "encrypted",
            );
            if (this.format === "encrypted" && this.password.length < 12)
              throw Error("كلمة تشفير الملف 12 حرفًا على الأقل");
          }
          const cards =
            this.action === "cancel"
              ? this.remaining
              : this.action === "damage" || this.action === "copy"
                ? this.choices
                : this.action === "restore"
                  ? this.cards.filter(
                      (c) => c.status === "Cancelled by Reversal",
                    )
                  : this.cards;
          const debit =
            this.action === "cancel" || this.action === "damage"
              ? round(
                  cards
                    .filter((c) => !c.creditHeld)
                    .reduce((n, c) => n + (c.credit ?? c.cost), 0),
                )
              : 0;
          this.preview = {
            quantity: cards.length,
            debit,
            signature: JSON.stringify(
              cards.map((c) => [c.id, c.status, c.creditHeld]),
            ),
            action: this.action,
          };
        } catch (e) {
          this.error = e.message;
        }
      },
      async confirm() {
        if (this.busy || !this.preview) return;
        this.busy = true;
        this.error = "";
        const actor = this.vm.currentUser;
        try {
          const e = this.vm.engine,
            id = this.batchId;
          if (this.action === "copy") {
            const ids = [...this.chosen],
              secret = this.format === "encrypted",
              payload = e.inventoryCopyPayload(id, ids, secret);
            let content;
            if (secret)
              content = JSON.stringify(
                await MasalVault.encrypt(
                  JSON.stringify(payload),
                  this.password,
                ),
              );
            else {
              const esc = (v) =>
                '"' +
                String(v ?? "")
                  .replace(/^[=+@\-]/, "'$&")
                  .replaceAll('"', '""') +
                '"';
              content =
                "\uFEFF" +
                [
                  ["Serial", "Expiry", "Status"],
                  ...payload.cards.map((c) => [
                    c.serial,
                    c.expiry,
                    this.vm.status(c.status),
                  ]),
                ]
                  .map((row) => row.map(esc).join(","))
                  .join("\r\n");
            }
            if (actor !== this.vm.currentUser || e.s !== this.vm.s)
              throw Error("تغير المستخدم أو بيانات النظام");
            if (
              JSON.stringify(e.inventoryCopyPayload(id, ids, secret)) !==
              JSON.stringify(payload)
            )
              throw Error("تغيرت البطاقات أثناء التشفير؛ أعد المعاينة");
            download(
              "masal-copy-" + id + (secret ? ".encrypted.json" : ".csv"),
              content,
              secret ? "application/json" : "text/csv;charset=utf-8",
            );
            this.vm.s.inventoryCopies ??= [];
            const record = {
              id: M.id("COPY"),
              batch: id,
              agent: this.b.agent,
              quantity: ids.length,
              user: actor,
              time: now(),
              reason: this.reason,
              format: secret ? "نسخة مشفرة" : "CSV دون رموز",
              status: "نسخة فقط",
            };
            this.vm.s.inventoryCopies.unshift(record);
            e.log("تصدير نسخة بطاقات دون سحب", record.id, null, record);
          } else if (this.action === "damage")
            e.markInventoryDamaged(id, this.chosen, this.reason);
          else if (this.action === "cancel")
            e.cancelInventoryRemainder(id, this.reason);
          else if (this.action === "edit")
            e.editInventoryMetadata(id, this.metadata, this.reason);
          else if (this.action === "restore") {
            if (this.restoreId)
              e.restoreInventoryCancellation(this.restoreId, this.reason);
            else e.inventoryAction(id, "restore", this.reason);
          } else e.inventoryAction(id, this.action, this.reason);
          this.preview = null;
          this.password = "";
          this.chosen = [];
          this.action = "";
          this.vm.notify("تم تنفيذ الإجراء وحفظ السجل");
        } catch (e) {
          this.error = e.message;
          this.preview = null;
        } finally {
          this.busy = false;
        }
      },
      withdraw() {
        this.vm.closeModal();
        this.vm.go("exports");
        this.vm.inventoryWorkspaceTab = "withdraw";
        this.vm.claimForm.batch = this.batchId;
        this.vm.$nextTick(() => {
          const w = this.vm.$refs.workflow;
          if (w) w.batch = this.batchId;
        });
      },
    },
  };
  const workspace = {
    data() {
      return { search: "", agent: "", product: "", state: "", page: 1 };
    },
    computed: {
      vm() {
        return this.$root;
      },
      rows() {
        return this.vm.visibleBatches.filter(
          (b) =>
            (!this.agent || b.agent === this.agent) &&
            (!this.product || b.product === this.product) &&
            (!this.state || b.status === this.state) &&
            (!this.search ||
              (
                b.id +
                " " +
                (b.parentOrder || "") +
                " " +
                this.vm.nameOf("products", b.product)
              )
                .toLowerCase()
                .includes(this.search.toLowerCase())),
        );
      },
      shown() {
        return this.rows.slice((this.page - 1) * 20, this.page * 20);
      },
      copies() {
        return (this.vm.s.inventoryCopies || []).filter((r) =>
          this.vm.engine.allowed(r.agent),
        );
      },
    },
    watch: {
      search() {
        this.page = 1;
      },
      agent() {
        this.page = 1;
      },
      product() {
        this.page = 1;
      },
      state() {
        this.page = 1;
      },
    },
    methods: {
      tab(id) {
        if (
          (id === "claims" && !this.vm.can("claims.view")) ||
          (id === "withdraw" && !this.vm.can("exports.view"))
        )
          return;
        const page =
          id === "claims"
            ? "claims"
            : id === "withdraw"
              ? "exports"
              : this.vm.page;
        this.vm.go(page);
        this.vm.inventoryWorkspaceTab = id;
      },
      count(b, type) {
        const cards = this.vm.visibleCards.filter((c) => c.batch === b.id);
        return cards.filter((c) =>
          type === "sold"
            ? !!c.sale
            : type === "damage"
              ? !!c.damageClaim && c.status === "Quarantined"
              : c.status === type,
        ).length;
      },
    },
  };
  manager.methods.stamp = function () {
    return JSON.stringify({
      actor: this.vm.currentUser,
      action: this.action,
      reason: this.reason,
      chosen: this.chosen,
      metadata: this.metadata,
      restoreId: this.restoreId,
      format: this.format,
      cards: this.cards.map((c) => [
        c.id,
        c.status,
        c.sale,
        c.expiry,
        c.credit,
        c.creditHeld,
      ]),
    });
  };
  const reviewManager = manager.methods.review;
  manager.methods.review = function () {
    reviewManager.call(this);
    if (this.preview) {
      this.preview.stamp = this.stamp();
      const inv = this.vm.s.batchInvoices.find(
        (i) => i.batch === this.batchId && !i.inventoryAdjustment,
      );
      this.preview.invoiceAmount = inv
        ? round((inv.amount * this.remaining.length) / inv.quantity)
        : 0;
      const restore = this.restores.find((r) => r.id === this.restoreId);
      this.preview.restoreCredit =
        restore?.credit ??
        this.cards
          .filter((c) => c.status === "Cancelled by Reversal")
          .reduce((n, c) => n + (c.credit ?? c.cost), 0);
      if (this.action === "restore" && restore)
        this.preview.quantity = restore.quantity;
    }
  };
  const confirmManager = manager.methods.confirm;
  manager.methods.confirm = async function () {
    if (this.preview && this.preview.stamp !== this.stamp()) {
      this.preview = null;
      this.error = "تغيرت البطاقات أو بيانات الإجراء؛ أعد المعاينة قبل التأكيد";
      return;
    }
    return confirmManager.call(this);
  };
  function install(o) {
    o.components["inventory-manager"] = manager;
    o.components["inventory-workspace"] = workspace;
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        inventoryWorkspaceTab: "stock",
        inventoryExportBusy: false,
      };
    };
    o.methods.openInventoryManager = function (b) {
      this.run(() => {
        this.engine.require(b.agent);
        if (
          !["inventory.view", "claims.view", "exports.view"].some((p) =>
            this.can(p),
          )
        )
          throw Error("لا توجد صلاحية");
        this.modal = {
          kind: "inventoryManager",
          title: "إدارة بطاقات المخزون",
          batchId: b.id,
        };
      });
    };
    const nav = o.computed.navGroups;
    o.computed.navGroups = function () {
      let used = false;
      return nav
        .call(this)
        .map((g) => ({
          ...g,
          items: g.items.flatMap((n) => {
            if (!["claims", "exports"].includes(n.id)) return [n];
            if (used) return [];
            used = true;
            return [
              {
                ...n,
                id:
                  ["claims", "exports"].includes(this.page) &&
                  this.can(this.page + ".view")
                    ? this.page
                    : n.id,
                label: "إدارة بطاقات المخزون",
                subtitle: "",
              },
            ];
          }),
        }))
        .filter((g) => g.items.length);
    };
    const title = o.computed.title;
    o.computed.title = function () {
      return ["claims", "exports"].includes(this.page)
        ? "إدارة بطاقات المخزون"
        : title.call(this);
    };
    const go = o.methods.go;
    o.methods.go = function (page, ...args) {
      if (["claims", "exports"].includes(page))
        this.inventoryWorkspaceTab = "stock";
      return go.call(this, page, ...args);
    };
    const operations = o.components["operations-panel"],
      visible = operations.computed.visible;
    operations.computed.visible = function () {
      if (this.page === "claims")
        return (
          this.vm.inventoryWorkspaceTab === "claims" &&
          this.vm.can("claims.view")
        );
      return visible.call(this);
    };
    const workflow = o.components["workflow-panel"],
      workflowVisible = workflow.computed.visible;
    workflow.computed.visible = function () {
      if (this.page === "exports")
        return (
          this.vm.inventoryWorkspaceTab === "withdraw" &&
          this.vm.can("exports.view")
        );
      return workflowVisible.call(this);
    };
    workflow.watch["vm.inventoryWorkspaceTab"] = function (tab) {
      if (tab === "withdraw")
        this.batch = this.vm.claimForm.batch || this.batch;
    };
  }
  function simplifyReturns(o) {
    for (const [key, label] of [
      ["exports.request", "طلب إرجاع للمزود"],
      ["exports.approve", "اعتماد الإرجاع"],
      ["exports.encrypt", "تنزيل ملف البطاقات"],
    ]) {
      const permission = MasalAccess.catalog.find((p) => p.key === key);
      if (permission) permission.label = label;
    }
    const oldReject = P.rejectExport;
    P.requestSupplierReturn = function (id, ids, reason) {
      this.operations();
      this.requirePermission("exports.request");
      const b = batch(this, id),
        cards = selected(this, b, ids);
      reason = cleanReason(reason);
      if (
        cards.some(
          (c) =>
            c.sale ||
            !["Available", "Quarantined"].includes(c.status) ||
            c.supplierReturn,
        ) ||
        linked(this.s, b, ids)
      )
        throw Error("حدد بطاقات غير مباعة وغير مرتبطة بمطالبة أو طلب إرجاع");
      const debit = round(
        cards
          .filter((c) => !c.creditHeld)
          .reduce((n, c) => n + (c.credit ?? c.cost), 0),
      );
      if (this.serviceAvailable(b.agent) < debit)
        throw Error("استرجع الرصيد الموزع قبل إرجاع هذه البطاقات");
      return atomic(this, () => {
        const r = {
          id: M.id("ER"),
          batch: id,
          agent: b.agent,
          reason,
          user: this.user,
          time: now(),
          status: "بانتظار الاعتماد",
          supplierReturn: true,
          cardIds: [...ids],
          quantity: cards.length,
          debit,
          original: cards.map((c) => ({
            id: c.id,
            status: c.status,
            creditHeld: !!c.creditHeld,
          })),
        };
        this.s.exportRequests.push(r);
        cards.forEach((c) => {
          c.status = "Quarantined";
          c.creditHeld = true;
          c.supplierReturn = r.id;
        });
        if (debit)
          this.serviceEntry(
            b.agent,
            "voucher",
            -debit,
            "تعليق رصيد إرجاع للمزود",
            r.id,
          );
        summarize(this, b);
        this.log("طلب إرجاع للمزود", r.id, null, {
          batch: id,
          quantity: r.quantity,
          reason,
          debit,
        });
        return r;
      });
    };
    P.rejectExport = function (id, reason) {
      const r = this.s.exportRequests?.find((r) => r.id === id);
      if (!r?.supplierReturn) return oldReject.call(this, id, reason);
      return atomic(this, () => {
        oldReject.call(this, id, reason);
        const b = batch(this, r.batch);
        for (const old of r.original) {
          const c = this.s.cards.find((c) => c.id === old.id);
          if (
            !c ||
            c.sale ||
            c.supplierReturn !== r.id ||
            c.status !== "Quarantined"
          )
            throw Error("تغيرت البطاقات؛ تعذر رفض الطلب");
          c.status = old.status;
          if (old.creditHeld) c.creditHeld = true;
          else delete c.creditHeld;
          delete c.supplierReturn;
        }
        if (r.debit)
          this.serviceEntry(
            b.agent,
            "voucher",
            r.debit,
            "إعادة رصيد طلب إرجاع مرفوض",
            r.id,
          );
        summarize(this, b);
        return r;
      });
    };
    P.supplierReturnCards = function (r) {
      this.requirePermission("exports.encrypt");
      this.requirePermission("data.pin");
      this.require(r.agent);
      const b = batch(this, r.batch),
        cards = r.cardIds
          ? selected(this, b, r.cardIds)
          : this.s.cards.filter(
              (c) =>
                c.batch === r.batch && !c.sale && c.status === "Quarantined",
            );
      if (
        !cards.length ||
        cards.some(
          (c) =>
            c.sale ||
            c.status !== "Quarantined" ||
            (r.supplierReturn && c.supplierReturn !== r.id),
        )
      )
        throw Error("تغيرت البطاقات؛ راجع طلب الإرجاع");
      return cards;
    };
    const book = (cards) =>
      MasalExcel.workbook(
        cards.map((c) => ({
          Serial: String(c.serial || ""),
          PIN: String(c.pin || ""),
          Expiry: String(c.expiry || ""),
          ...(cards.some((x) => x.cvc) ? { CVC: String(c.cvc || "") } : {}),
          ...(cards.some((x) => x.reference)
            ? { Reference: String(c.reference || "") }
            : {}),
        })),
      );
    const save = (vm) => {
      MasalStateStorage.write(vm.s);
      vm.saveState = "محفوظ محليًا";
    };
    o.methods.completeSupplierReturn = function (id) {
      const e = this.engine,
        r = this.s.exportRequests.find((r) => r.id === id);
      if (!r || r.status !== "معتمد") throw Error("طلب الإرجاع غير معتمد");
      const cards = e.supplierReturnCards(r),
        output = book(cards);
      atomic(e, () => {
        const b = batch(e, r.batch),
          record = {
            id: M.id("EXP"),
            batch: b.id,
            agent: b.agent,
            product: b.product,
            provider: b.provider,
            quantity: cards.length,
            value: round(cards.reduce((n, c) => n + c.cost, 0)),
            cardIds: cards.map((c) => c.id),
            reason: r.reason,
            time: now(),
            exportRequest: r.id,
            status: "تم الإرجاع",
            requestedBy: r.user,
            approver: r.approver,
          };
        cards.forEach((c) => (c.status = "Exported"));
        summarize(e, b);
        this.s.exports.unshift(record);
        r.status = "تم التنزيل";
        r.downloadedAt = record.time;
        e.log("إرجاع بطاقات للمزود", record.id, null, {
          batch: b.id,
          quantity: record.quantity,
          reason: r.reason,
        });
        save(this);
      });
      download(
        "supplier-return-" + r.id + ".xlsx",
        output,
        "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      );
      this.notify("تم إرجاع البطاقات وتنزيل ملف Excel");
    };
    o.methods.downloadSupplierReturn = function (record) {
      this.run(() => {
        this.engine.requirePermission("exports.encrypt");
        this.engine.requirePermission("data.pin");
        const r = this.s.exports.find((x) => x.id === record.id);
        if (!r?.cardIds?.length)
          throw Error(
            "السجل القديم لا يحدد بطاقات الملف؛ لا يمكن إعادة تنزيله",
          );
        this.engine.require(r.agent);
        const cards = r.cardIds.map((id) =>
          this.s.cards.find(
            (c) =>
              c.id === id && c.batch === r.batch && c.status === "Exported",
          ),
        );
        if (cards.some((c) => !c)) throw Error("بطاقات السجل غير متاحة");
        download(
          "supplier-return-" + r.exportRequest + ".xlsx",
          book(cards),
          "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        );
        this.engine.log("إعادة تنزيل ملف إرجاع", r.id, null, {
          quantity: cards.length,
        });
      });
    };
    manager.methods.withdraw = function () {
      this.action = "return";
      this.preview = null;
      this.reason = "";
      this.error = "";
    };
    manager.methods.selectRemaining = function () {
      this.chosen = this.remaining
        .filter((c) => !c.supplierReturn)
        .map((c) => c.id);
    };
    const review = manager.methods.review;
    manager.methods.review = function () {
      if (this.action !== "return") return review.call(this);
      this.error = "";
      try {
        const clone = new M.Engine(M.clone(this.vm.s), this.vm.currentUser),
          r = clone.requestSupplierReturn(
            this.batchId,
            this.chosen,
            this.reason,
          );
        this.preview = {
          quantity: r.quantity,
          debit: r.debit,
          stamp: this.stamp(),
        };
      } catch (e) {
        this.error = e.message;
      }
    };
    const confirm = manager.methods.confirm;
    manager.methods.confirm = async function () {
      if (this.action === "copy") this.format = "csv";
      if (this.action !== "return") return confirm.call(this);
      if (this.busy || !this.preview) return;
      this.busy = true;
      this.error = "";
      try {
        if (this.preview.stamp !== this.stamp())
          throw Error("تغيرت البطاقات؛ أعد المعاينة");
        let r;
        atomic(this.vm.engine, () => {
          r = this.vm.engine.requestSupplierReturn(
            this.batchId,
            [...this.chosen],
            this.reason,
          );
          if (this.vm.actor.role === "owner") {
            this.vm.engine.approveExport(r.id);
            this.vm.completeSupplierReturn(r.id);
          } else save(this.vm);
        });
        if (this.vm.actor.role !== "owner")
          this.vm.notify("أُرسل طلب الإرجاع للاعتماد");
        this.action = "";
        this.preview = null;
        this.chosen = [];
      } catch (e) {
        this.error = e.message;
        this.preview = null;
      } finally {
        this.busy = false;
      }
    };
    const copyStart = "",
      copyEnd = "";
    manager.data = (() => {
      const original = manager.data;
      return function () {
        return { ...original.call(this), format: "csv" };
      };
    })();
    workspace.computed.copies = function () {
      return [
        ...this.vm.visibleExports,
        ...this.vm.s.exportRequests.filter(
          (r) => r.status === "مرفوض" && this.vm.engine.allowed(r.agent),
        ),
      ].sort((a, b) => b.time.localeCompare(a.time));
    };
    const workflow = o.components["workflow-panel"];
    workflow.computed.exportRequests = function () {
      return this.s.exportRequests.filter(
        (r) =>
          this.e.allowed(r.agent) &&
          ["بانتظار الاعتماد", "معتمد"].includes(r.status),
      );
    };
    workflow.methods.approveReturn = function (r) {
      this.vm.run(() => {
        atomic(this.e, () => {
          this.e.approveExport(r.id);
          this.vm.completeSupplierReturn(r.id);
        });
      });
    };
    workflow.methods.selectExport = function (r) {
      this.vm.run(() => this.vm.completeSupplierReturn(r.id));
    };
    o.methods.exportEncrypted = async function () {
      this.run(() => {
        const r = this.s.exportRequests.find(
          (r) => r.batch === this.claimForm.batch && r.status === "معتمد",
        );
        if (!r) throw Error("طلب الإرجاع غير معتمد");
        this.completeSupplierReturn(r.id);
      });
    };
  }

  root.MasalInventoryManagement = {
    install(o) {
      install(o);
      simplifyReturns(o);
    },
  };
})(globalThis);

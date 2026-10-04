(function (root) {
  "use strict";
  const M = Masal,
    P = M.Engine.prototype;
  P.approveCashOrder = function (meta, rows) {
    this.requirePermission("import.approve");
    this.require(meta.agent);
    if (
      this.actor().role !== "owner" &&
      this.actor().staffAccount !== "@system"
    )
      throw Error("اعتماد الطلبية لإدارة النظام فقط");
    if (!meta.postingKey) throw Error("معرف الطلبية مطلوب");
    this.operations();
    const existing = this.s.batchInvoices.find(
      (i) => i.key === meta.postingKey,
    );
    if (existing) {
      if (
        existing.agent !== meta.agent ||
        (existing.product && existing.product !== meta.product)
      )
        throw Error("مفتاح الطلبية مستخدم");
      const b = this.s.batches.find((b) => b.id === existing.batch);
      if (b.product !== meta.product) throw Error("مفتاح الطلبية مستخدم");
      return b;
    }
    return MasalMeetingRules.atomic(this, () => {
      const price = this.policyPrice(meta.agent, meta.product);
      if (!(price > 0) || price !== meta.loadPrice)
        throw Error("تغير سعر البيع؛ ارجع وأعد معاينة الطلبية");
      const checked = this.validateImport(meta, rows);
      if (!checked.length || !checked.some((r) => !r.error))
        throw Error("لا توجد بطاقات صالحة للاعتماد");
      const b = this.importBatch(
          { ...meta, paymentMethod: "آجل", cashConfirmed: false },
          rows,
        ),
        invoice = this.s.batchInvoices.find((i) => i.batch === b.id),
        rejected = checked.filter((r) => r.error).map((r) => ({ ...r }));
      b.rejectedCards = rejected;
      invoice.rejectedCards = rejected;
      invoice.rejectedCount = rejected.length;
      Object.assign(invoice, {
        paymentMethod: "آجل",
        paymentStatus: "مستحقة",
        paidAmount: 0,
      });
      this.log("اعتماد طلبية بمبلغ مستحق", b.id, null, {
        invoice: invoice.id,
        amount: invoice.amount,
      });
      return b;
    });
  };
  const form = {
    data() {
      return {
        step: 0,
        draft: {
          agent: "",
          product: "",
          cost: "",
          expenses: 0,
          expiry: "",
          supplier: "",
        },
        fileName: "",
        headers: [],
        raw: [],
        generatedSerials: {},
        mapping: {},
        checked: [],
        snapshot: null,
        key: M.id("CASH-ORDER"),
        confirmed: false,
        busy: false,
        error: "",
        done: null,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      agents() {
        return this.vm.visibleAgents.filter((a) => !a.parent && a.active);
      },
      products() {
        return this.vm.s.products.filter((p) => p.active);
      },
      price() {
        return this.draft.agent && this.draft.product
          ? this.vm.engine.policyPrice(this.draft.agent, this.draft.product)
          : 0;
      },
      fields() {
        const p = this.vm.s.products.find((p) => p.id === this.draft.product);
        if (!p) return [];
        const cfg = MasalCardFields.policy(p);
        return MasalCardFields.catalog.filter((f) => cfg[f.key] !== "unused");
      },
      needsMapping() {
        return this.fields.some(
          (f) =>
            MasalCardFields.policy(
              this.vm.s.products.find((p) => p.id === this.draft.product),
            )[f.key] === "required" &&
            (this.mapping[f.key] ?? -1) < 0 &&
            f.key !== "serial" &&
            !(f.key === "expiry" && this.draft.expiry),
        );
      },
      total() {
        return (
          this.checked.filter((r) => !r.error).length *
          (this.snapshot?.loadPrice || 0)
        );
      },
    },
    methods: {
      syncOrderAgent() {
        if (!this.isAdmin)
          this.draft.agent =
            this.vm.actor.agent || this.vm.actor.staffAccount || "";
      },
      next() {
        try {
          this.syncOrderAgent();
          this.error = "";
          if (this.step === 0) {
            if (!this.agents.some((a) => a.id === this.draft.agent))
              throw Error("اختر الوكيل الرئيسي");
            if (!this.products.some((p) => p.id === this.draft.product))
              throw Error("اختر الفئة");
            if (
              !(Number(this.draft.cost) > 0) ||
              !Number.isFinite(Number(this.draft.cost))
            )
              throw Error("أدخل تكلفة البطاقة أكبر من صفر");
            if (
              !Number.isFinite(Number(this.draft.expenses)) ||
              Number(this.draft.expenses) < 0
            )
              throw Error("مصاريف الدفعة يجب أن تكون صفرًا أو أكثر");
            if (!this.draft.supplier.trim())
              throw Error("اختر المجهز أو أدخل اسمه");
            if (!(this.price > 0))
              throw Error("حدد سعر بيع الفئة للوكيل من قسم الأسعار أولًا");
            this.step = 1;
            return;
          }
          if (!this.raw.length) throw Error("ارفع ملف البطاقات");
          if (this.needsMapping) throw Error("حدد الأعمدة المطلوبة للبطاقات");
          this.snapshot = {
            ...this.draft,
            cost: Number(this.draft.cost),
            expenses: Number(this.draft.expenses),
            loadPrice: this.price,
            city: this.agents.find((a) => a.id === this.draft.agent).city,
            supplier: this.draft.supplier.trim(),
            postingKey: this.key,
          };
          this.checked = this.vm.engine.validateImport(
            this.snapshot,
            this.rows(),
          );
          this.confirmed = false;
          this.step = 2;
        } catch (e) {
          this.error = e.message;
        }
      },
      rows() {
        return this.raw.map((row, index) => {
          const r = Object.fromEntries(
            this.fields.map((f) => [
              f.key,
              this.mapping[f.key] >= 0 ? row[this.mapping[f.key]] || "" : "",
            ]),
          );
          r.serial = String(
            (this.mapping.serial >= 0 ? row[this.mapping.serial] : "") ?? "",
          ).trim();
          if (!r.serial) {
            this.generatedSerials[index] ??= "AUTO-" + crypto.randomUUID();
            r.serial = this.generatedSerials[index];
          }
          for (const f of this.vm.s.products.find(
            (p) => p.id === this.draft.product,
          )?.extraFields || [])
            r[f.key] = row[this.headers.indexOf(f.key)] || "";
          return r;
        });
      },
      async read(event) {
        const file = event.target.files[0];
        event.target.value = "";
        if (!file) return;
        this.busy = true;
        this.error = "";
        this.raw = [];
        this.generatedSerials = {};
        this.checked = [];
        const user = this.vm.currentUser;
        try {
          const result = await MasalImportReader.read(file);
          if (user !== this.vm.currentUser) return;
          if (result.length !== 1)
            throw Error("ارفع ملفًا بورقة واحدة وفئة واحدة لكل طلبية");
          const data = result[0].rows;
          if (data.length < 2) throw Error("الملف لا يحتوي بطاقات");
          this.fileName = file.name;
          this.headers = data[0];
          this.raw = data.slice(1);
          const aliases = {
            pin: ["pin", "code", "رمز", "رمز البطاقة"],
            serial: [
              "serial",
              "serialnumber",
              "serial number",
              "serial_number",
              "سيريال",
              "الرقم التسلسلي",
            ],
            expiry: ["expiry", "expiration", "تاريخ الانتهاء"],
            cvc: ["cvc", "cvv"],
            reference: ["reference", "مرجع"],
          };
          for (const f of MasalCardFields.catalog)
            this.mapping[f.key] = this.headers.findIndex((h) =>
              (aliases[f.key] || [f.key]).includes(
                String(h).trim().toLowerCase(),
              ),
            );
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      approve() {
        this.error = "";
        this.busy = true;
        try {
          this.done = this.vm.engine.approveCashOrder(
            { ...this.snapshot, cashConfirmed: true },
            this.rows(),
          );
          this.vm.notify(
            "تم اعتماد " +
              this.done.quantity +
              " بطاقة صالحة؛ تم استبعاد البطاقات المرفوضة",
          );
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      downloadRejected(batch) {
        try {
          this.vm.engine.requirePermission("data.pin");
          const rows = (batch?.rejectedCards || []).map((r) => ({
            row: r.row,
            serial: r.serial || "",
            pin: r.pin || "",
            expiry: r.expiry || "",
            cvc: r.cvc || "",
            reference: r.reference || "",
            reason: r.error || "",
          }));
          if (!rows.length) throw Error("لا توجد بطاقات مرفوضة محفوظة");
          download(
            "masal-rejected-" + batch.id + ".csv",
            csv(rows),
            "text/csv;charset=utf-8",
          );
          this.vm.notify("تم تنزيل ملف البطاقات المرفوضة");
        } catch (e) {
          this.error = e.message;
        }
      },
      restart() {
        Object.assign(this.$data, form.data.call(this));
        this.syncOrderAgent();
      },
    },
    mounted() {
      this.syncOrderAgent();
    },
  };
  root.MasalCashOrders = {
    install(o) {
      o.components["cash-order-form"] = form;
    },
  };
})(globalThis);

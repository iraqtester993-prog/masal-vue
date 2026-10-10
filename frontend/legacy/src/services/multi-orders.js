(function (root) {
  "use strict";
  const M = Masal,
    P = M.Engine.prototype,
    parser = MasalOrderParser,
    copy = M.clone;
  const admin = (e) =>
    e.actor()?.role === "owner" || e.actor()?.staffAccount === "@system";
  const summary = (r) => ({
    id: r.id,
    agent: r.agent,
    quantity: r.quantity,
    rejected: r.rejected,
    status: r.status,
    files: r.lines.length,
  });
  function persist(vm) {
    try {
      MasalStateStorage.write(vm.s);
      vm.saveState = "محفوظ محليًا";
    } catch {
      throw Error(
        "تعذر حفظ الطلبية في مساحة المتصفح؛ لم تُنفّذ العملية. قلّل حجم الطلبية أو نزّل نسخة احتياطية وراجع مساحة التخزين",
      );
    }
  }
  function draftOf(r) {
    return {
      key: r.key,
      agent: r.agent,
      provider: r.provider,
      sourceId: r.sourceId,
      city: r.city,
      ...(r.product !== undefined ? { product: r.product } : {}),
      lines: r.lines.map((line) => {
        const { meta, checked, accepted, rejected, batch, results, ...raw } =
          line;
        return copy(raw);
      }),
      ...(r.categoryCount !== undefined
        ? { categoryCount: r.categoryCount }
        : {}),
    };
  }
  const signature = (p) =>
    JSON.stringify(
      p.lines.map((l) => [
        l.id,
        l.meta.loadPrice,
        l.checked.map((r) => r.error),
      ]),
    );
  function rejectedRows(lines) {
    return (lines || []).flatMap((line) =>
      (line.rows || []).flatMap((row, i) => {
        const error = line.checked?.[i]?.error ?? line.results?.[i];
        return error
          ? [
              {
                الملف: line.name || "",
                "سطر الملف": row.sourceRow || i + 1,
                "رمز الفئة": line.categoryCode || "",
                Serial: String(row.serial ?? ""),
                PIN: String(row.pin ?? ""),
                Expiry: String(row.expiry ?? ""),
                CVC: String(row.cvc ?? ""),
                Reference: String(row.reference ?? ""),
                "سبب الرفض": String(error),
              },
            ]
          : [];
      }),
    );
  }
  const rejectedExport = {
    props: ["lines"],
    computed: {
      rows() {
        return rejectedRows(this.lines);
      },
      allowed() {
        return this.$root.can("import.preview") && this.$root.can("data.pin");
      },
    },
    methods: {
      download() {
        try {
          const vm = this.$root,
            e = vm.engine;
          e.requirePermission("import.preview");
          e.requirePermission("data.pin");
          for (const line of this.lines || []) e.require(line.meta?.agent);
          const rows = rejectedRows(this.lines);
          if (!rows.length) return;
          const url = URL.createObjectURL(MasalExcel.workbook(rows)),
            a = document.createElement("a");
          a.href = url;
          a.download =
            "rejected-cards-" +
            new Date().toISOString().replace(/[:.]/g, "-") +
            ".xlsx";
          document.body.appendChild(a);
          a.click();
          a.remove();
          setTimeout(() => URL.revokeObjectURL(url), 30000);
        } catch (error) {
          this.$root.notify(error.message, true);
        }
      },
    },
  };
  function initializeReferenceCatalog(s) {
    const revision = "asiacell-reference-28-v1";
    if (s.importReferenceCatalog?.revision === revision) return false;
    const companyName = (v) =>
      String(v || "")
        .toLowerCase()
        .replace(/[أإآ]/g, "ا")
        .replace(/[\s._-]/g, "");
    const providers = s.providers.filter(
      (p) =>
        ["اسياسيل", "asiacell"].includes(companyName(p.name)) ||
        p.referenceCatalog === revision,
    );
    if (providers.length > 1)
      throw Error(
        "توجد أكثر من شركة آسياسيل؛ وحّد الشركة قبل إضافة فئات المرجع",
      );
    const provider = providers[0] || {
      id: M.id("PROVIDER"),
      name: "آسياسيل",
      supplier: "",
      connection: "ملفات",
      active: true,
      referenceCatalog: revision,
    };
    if (!providers.length) s.providers.push(provider);
    const bindings = {},
      created = [];
    let order = Math.max(0, ...s.products.map((p) => Number(p.order) || 0));
    for (const entry of parser.reference) {
      const products = s.products.filter((p) => p.provider === provider.id),
        explicit = products.filter((p) =>
          parser.codes(p.importCodes).some((c) => entry.codes.includes(c)),
        ),
        matches = explicit.length
          ? explicit
          : products.filter((p) => parser.referenceMatches(p, entry));
      if (matches.length > 1)
        throw Error(
          "يوجد أكثر من تطابق لفئة " +
            entry.label +
            " ضمن آسياسيل؛ لم تُضف فئات المرجع",
        );
      let product = matches[0];
      if (!product) {
        product = {
          id: M.id("CATEGORY"),
          provider: provider.id,
          name: entry.face ? entry.label + " دينار" : entry.label,
          face: entry.face ?? null,
          currency: "IQD",
          kind: "محلية",
          active: true,
          min: null,
          dailyQty: null,
          dailyAmount: null,
          fields: "serial,pin,expiry",
          fieldPolicy: {
            serial: "required",
            pin: "required",
            expiry: "required",
            cvc: "unused",
            reference: "unused",
          },
          order: ++order,
          allowedCities: [],
          extraFields: [],
          receiptWidth: 80,
          referenceCatalog: revision,
        };
        s.products.push(product);
        created.push(product.id);
      }
      product.importCodes = [
        ...new Set([...parser.codes(product.importCodes), ...entry.codes]),
      ].join(", ");
      for (const code of entry.codes) bindings[code] = product.id;
    }
    s.importReferenceCatalog = {
      revision,
      provider: provider.id,
      bindings,
      created,
      time: new Date().toISOString(),
    };
    const owner = s.users.find((u) => u.role === "owner");
    if (owner)
      new M.Engine(s, owner.id).log(
        "إضافة وربط فئات ملف المرجع",
        provider.id,
        null,
        { categories: 28, codes: 37, created: created.length },
      );
    return true;
  }
  function notify(e, r, title, toAdmin) {
    const users = e.s.users
      .filter(
        (u) =>
          u.active &&
          u.id !== e.user &&
          (toAdmin
            ? admin(new M.Engine(e.s, u.id)) &&
              new M.Engine(e.s, u.id).can("import.approve")
            : u.id === r.user ||
              u.agent === r.agent ||
              u.staffAccount === r.agent) &&
          new M.Engine(e.s, u.id).can("import.view"),
      )
      .map((u) => u.id);
    if (users.length)
      e.createNotice(title, r.id, users, {
        page: "import",
        entity: r.id,
        audience: "workflow",
      });
  }
  P.resolveOrderProduct = function (provider, code) {
    if (!code) return [];
    const identifier = parser.code(code),
      identified = this.s.products.filter(
        (p) =>
          p.provider === provider &&
          p.orderIdentifier &&
          parser.code(p.orderIdentifier) === identifier,
      );
    if (identified.length) return identified;
    const normalized = parser.code(code),
      catalog = this.s.importReferenceCatalog;
    if (catalog?.provider === provider && catalog.bindings[normalized])
      return this.s.products.filter(
        (p) => p.id === catalog.bindings[normalized] && p.provider === provider,
      );
    const entry = parser.referenceOf(code),
      aliases = entry?.codes || [normalized],
      products = this.s.products.filter((p) => p.provider === provider);
    const explicit = products.filter((p) =>
      parser.codes(p.importCodes).some((c) => aliases.includes(c)),
    );
    return explicit.length
      ? explicit
      : products.filter((p) => parser.referenceMatches(p, entry));
  };
  P.checkMultiOrder = function (draft) {
    this.requirePermission("import.preview");
    this.require(draft.agent);
    const agent = this.s.agents.find(
        (a) => a.id === draft.agent && a.active && !a.parent,
      ),
      provider = this.s.providers.find(
        (p) => p.id === draft.provider && p.active,
      ),
      source = this.s.orderSources?.find(
        (x) =>
          x.id === draft.sourceId && x.active && x.provider === draft.provider,
      );
    if (!agent) throw Error("اختر وكيلًا رئيسيًا مفعّلًا");
    if (
      !admin(this) &&
      draft.agent !== (this.actor().agent || this.actor().staffAccount)
    )
      throw Error("الطلبية يجب أن تكون لحسابك");
    if (!provider || !source)
      throw Error("اختر الشركة والمصدر المفعّل التابع لها");
    if (!draft.city || !MasalRegions.isActive(this.s, draft.city))
      throw Error("اختر محافظة مفعلة");
    if (!draft.lines?.length) throw Error("أضف ملف بطاقات");
    if (draft.lines.reduce((n, l) => n + (l.rows?.length || 0), 0) > 50000)
      throw Error("الحد الأقصى للطلبية 50,000 بطاقة");
    if (
      draft.product &&
      !this.s.products.some(
        (p) =>
          p.id === draft.product &&
          p.provider === provider.id &&
          this.productAvailable(agent.id, p.id, draft.city),
      )
    )
      throw Error("الفئة المختارة للطلبية غير متاحة");
    const pins = new Set(),
      serials = new Set(),
      ids = new Set();
    const lines = draft.lines.map((line) => {
      if (!line.id || ids.has(line.id)) throw Error("معرف ملف مكرر");
      ids.add(line.id);
      if (!line.rows?.length) throw Error("الملف فارغ");
      if (line.declared != null && line.declared !== line.rows.length)
        throw Error("عدد البطاقات لا يطابق رأس الملف");
      const match = this.resolveOrderProduct(provider.id, line.categoryCode);
      if (draft.product && match.length === 1 && match[0].id !== draft.product)
        throw Error(
          "معرّف الملف يتعارض مع الفئة المختارة للطلبية: " + line.name,
        );
      line = {
        ...line,
        product: line.product || draft.product || match[0]?.id || "",
      };
      if (!match.length && !line.product)
        throw Error("اختر فئة الملف من القائمة: " + line.name);
      if (match.length > 1)
        throw Error("رمز الاستيراد مربوط بأكثر من فئة؛ صحح إعدادات الفئات");
      const p =
        match[0] ||
        this.s.products.find(
          (p) => p.id === line.product && p.provider === provider.id,
        );
      if (!p) throw Error("الفئة المختارة لا تتبع شركة الطلبية");
      if (line.product !== p.id)
        throw Error(
          "فئة المخزون لا تطابق الربط التلقائي؛ أعد رفع الملف: " + line.name,
        );
      if (!this.productAvailable(agent.id, p.id, draft.city))
        throw Error(
          "الفئة " + p.name + " غير مفعلة أو غير مسموحة للوكيل أو المحافظة",
        );
      if (
        !Number.isFinite(+line.cost) ||
        +line.cost <= 0 ||
        !Number.isFinite(+line.expenses) ||
        +line.expenses < 0
      )
        throw Error("أدخل تكلفة موجبة ومصاريف صحيحة لكل ملف");
      const loadPrice = this.policyPrice(agent.id, p.id);
      if (!(loadPrice > 0))
        throw Error("حدد سعر الفئة للوكيل أولًا: " + p.name);
      const meta = {
        agent: agent.id,
        provider: provider.id,
        product: p.id,
        sourceId: source.id,
        supplier: source.name,
        sourceCompany: provider.name,
        city: draft.city,
        cost: +line.cost,
        expenses: +line.expenses,
        expiry: line.expiry || "",
        loadPrice,
      };
      const checked = this.validateImport(
        meta,
        line.rows.map((r) => (r.parseError ? { ...r, pin: "" } : r)),
      ).map((r, i) => {
        const raw = line.rows[i],
          key = p.id + "|" + r.serial;
        let error = raw.parseError || r.error || "";
        if (!raw.serial) error = "أعد قراءة الملف لتوليد السيريال";
        if (!error && (pins.has(r.pin) || serials.has(key)))
          error = "بطاقة مكررة بين ملفات الطلبية";
        if (!error) {
          pins.add(r.pin);
          serials.add(key);
        }
        return {
          ...raw,
          ...r,
          pin: raw.pin,
          sourceRow: raw.sourceRow || i + 1,
          error,
        };
      });
      return {
        ...copy(line),
        categorySelection: match.length ? "automatic" : "manual",
        meta,
        checked,
        accepted: checked.filter((r) => !r.error).length,
        rejected: checked.filter((r) => r.error).length,
      };
    });
    if (draft.categoryCount !== undefined) {
      if (!Number.isInteger(+draft.categoryCount) || +draft.categoryCount < 1)
        throw Error("أدخل عدد الفئات كعدد صحيح أكبر من صفر");
      const actual = new Set(lines.map((l) => l.product)).size;
      if (actual !== +draft.categoryCount)
        throw Error(
          "عدد الفئات المحدد " +
            draft.categoryCount +
            "، بينما ملفات الطلبية مرتبطة بـ " +
            actual +
            " فئة؛ أكمل الملفات أو عدّل عدد الفئات",
        );
    }
    return {
      agent: agent.id,
      provider: provider.id,
      sourceId: source.id,
      city: draft.city,
      ...(draft.product !== undefined ? { product: draft.product } : {}),
      lines,
      quantity: lines.reduce((n, l) => n + l.accepted, 0),
      rejected: lines.reduce((n, l) => n + l.rejected, 0),
      amount: lines.reduce((n, l) => n + l.accepted * l.meta.loadPrice, 0),
      ...(draft.categoryCount !== undefined
        ? { categoryCount: +draft.categoryCount }
        : {}),
    };
  };
  P.submitMultiOrder = function (draft, excludeRejected = false, editId = "") {
    this.s.pendingOrders ??= [];
    this.requirePermission("import.preview");
    this.require(draft.agent);
    if (!draft.key) throw Error("معرف الطلبية مفقود");
    const existing = this.s.pendingOrders.find(
      (r) => r.key === draft.key && r.version === 2,
    );
    if (existing && !editId) {
      if (
        existing.user !== this.user ||
        JSON.stringify(draftOf(existing)) !== JSON.stringify(draft)
      )
        throw Error("معرف الطلبية مستخدم");
      return existing;
    }
    const old =
      editId &&
      this.s.pendingOrders.find((r) => r.id === editId && r.version === 2);
    if (
      editId &&
      (!old ||
        old.user !== this.user ||
        old.status !== "معادة للتصحيح" ||
        old.key !== draft.key ||
        old.agent !== draft.agent)
    )
      throw Error("لا يمكن تعديل هذه الطلبية");
    const checked = this.checkMultiOrder(draft);
    if (checked.lines.some((l) => !l.accepted))
      throw Error("يوجد ملف بلا بطاقات صالحة؛ صححه أو احذفه");
    if (checked.rejected && !excludeRejected)
      throw Error("أكد استبعاد البطاقات المرفوضة بعد مراجعتها");
    return MasalMeetingRules.atomic(this, () => {
      const time = new Date().toISOString(),
        r = {
          ...checked,
          lines: checked.lines.map(({ checked, ...line }) => ({
            ...line,
            results: checked.map((r) => r.error),
          })),
          id: old?.id || M.id("ORDER"),
          key: draft.key,
          version: 2,
          meta: {
            supplier: checked.lines[0].meta.supplier,
            provider: checked.provider,
            city: checked.city,
          },
          user: this.user,
          time,
          status: "بانتظار الاعتماد",
          excludedConfirmed: !!excludeRejected,
          events: [
            ...(old?.events || []),
            { action: old ? "إعادة إرسال" : "إرسال", user: this.user, time },
          ],
        };
      if (old) Object.assign(old, r);
      else this.s.pendingOrders.unshift(r);
      const result = old || r;
      this.log("إرسال طلبية متعددة", r.id, null, summary(r));
      notify(this, r, "طلبية بانتظار الاعتماد", true);
      return result;
    });
  };
  P.reviewMultiOrder = function (id, action, reason = "") {
    this.requirePermission("import.approve");
    if (!admin(this)) throw Error("مراجعة الطلبيات لإدارة النظام فقط");
    const r = this.s.pendingOrders?.find((r) => r.id === id && r.version === 2);
    if (!r) throw Error("الطلبية غير موجودة");
    this.require(r.agent);
    if (r.status === "معتمدة" && action === "approve") return r;
    if (r.status !== "بانتظار الاعتماد")
      throw Error("الطلبية ليست بانتظار الاعتماد");
    if (!["approve", "reject", "return"].includes(action))
      throw Error("إجراء غير صحيح");
    if (action !== "approve" && !reason.trim())
      throw Error("أدخل سبب الرفض أو الإعادة");
    return MasalMeetingRules.atomic(this, () => {
      if (action === "approve") {
        const checked = this.checkMultiOrder(draftOf(r));
        for (let i = 0; i < checked.lines.length; i++) {
          const old = r.lines[i],
            line = checked.lines[i];
          if (
            old.meta.loadPrice !== line.meta.loadPrice ||
            JSON.stringify(old.results.map((x) => !x)) !==
              JSON.stringify(line.checked.map((x) => !x.error))
          )
            throw Error(
              "تغير السعر أو صلاحية البطاقات؛ أعد الطلبية للتصحيح والمعاينة",
            );
        }
        const batches = [];
        for (const line of checked.lines) {
          const good = line.checked.filter((x) => !x.error),
            postingKey = r.key + ":" + line.id;
          if (this.s.batchInvoices?.some((i) => i.key === postingKey))
            throw Error("معرف ملف مستخدم في دفعة معتمدة؛ راجع سجل الطلبية");
          const b = this.approveCashOrder(
            {
              ...line.meta,
              postingKey,
              parentOrder: r.id,
              fileName: line.name,
            },
            good,
          );
          if (b.quantity !== good.length)
            throw Error("تغيرت البطاقات أثناء الاعتماد");
          b.rejectedCards = copy(line.checked.filter((x) => x.error));
          b.rejected = b.rejectedCards.length;
          const inv = this.s.batchInvoices.find((x) => x.batch === b.id);
          if (inv) {
            inv.rejectedCards = copy(b.rejectedCards);
            inv.rejectedCount = b.rejected;
          }
          batches.push(b.id);
          r.lines.find((x) => x.id === line.id).batch = b.id;
        }
        r.batches = batches;
      }
      const time = new Date().toISOString();
      r.status = {
        approve: "معتمدة",
        reject: "مرفوضة",
        return: "معادة للتصحيح",
      }[action];
      r.reason = reason.trim();
      r.reviewed = time;
      r.reviewer = this.user;
      r.events.push({
        action: r.status,
        reason: r.reason,
        user: this.user,
        time,
      });
      this.log("مراجعة طلبية متعددة", r.id, null, summary(r));
      notify(this, r, "طلبية " + r.status, false);
      return r;
    });
  };
  const form = {
    data() {
      return {
        draft: {
          key: M.id("MULTI"),
          agent: "",
          provider: "",
          sourceId: "",
          city: "",
          product: "",
          lines: [],
          categoryCount: 1,
        },
        step: 0,
        mode: "files",
        text: "",
        busy: false,
        error: "",
        preview: null,
        exclude: false,
        done: null,
        editId: "",
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      isAdmin() {
        return admin(this.vm.engine);
      },
      agents() {
        return this.vm.visibleAgents.filter((a) => a.active && !a.parent);
      },
      sources() {
        return (this.vm.s.orderSources || []).filter(
          (x) => x.active && x.provider === this.draft.provider,
        );
      },
      products() {
        return this.vm.s.products.filter(
          (p) =>
            p.provider === this.draft.provider &&
            this.vm.engine.productAvailable(
              this.draft.agent,
              p.id,
              this.draft.city,
            ),
        );
      },
    },
    mounted() {
      if (!this.isAdmin)
        this.draft.agent =
          this.vm.actor.agent || this.vm.actor.staffAccount || "";
    },
    methods: {
      changed() {
        this.preview = null;
        this.exclude = false;
      },
      needsCategory(l) {
        return !this.vm.engine.resolveOrderProduct(
          this.draft.provider,
          l.categoryCode,
        ).length;
      },
      mapColumns(l) {
        this.changed();
        l.rows = l.rawRows.map((r, i) => ({
          sourceRow: r.sourceRow,
          serial: l.autoSerials[i],
          parseError: "حدد أعمدة بيانات البطاقات بشكل صحيح",
        }));
        const cols = Object.values(l.columnMap).filter((v) => v !== "");
        if (!cols.length || new Set(cols).size !== cols.length) {
          this.error = "لا يمكن استخدام العمود نفسه لأكثر من حقل";
          return;
        }
        l.rows = l.rawRows.map((r, i) => {
          const card = { sourceRow: r.sourceRow, serial: l.autoSerials[i] };
          for (const [key, column] of Object.entries(l.columnMap))
            if (column !== "") card[key] = r.cells[Number(column)] || "";
          card.serial ||= l.autoSerials[i];
          card.expiry = parser.date(card.expiry);
          return card;
        });
        this.error = "";
        this.changed();
      },
      categoryChanged() {
        for (const l of this.draft.lines)
          if (this.needsCategory(l)) l.product = this.draft.product || "";
        this.resolve();
        this.changed();
      },
      providerChanged() {
        this.draft.product = "";
        this.draft.sourceId = "";
        for (const l of this.draft.lines) l.product = "";
        this.resolve();
        this.changed();
      },
      resolve() {
        for (const l of this.draft.lines) {
          const matches = this.vm.engine.resolveOrderProduct(
            this.draft.provider,
            l.categoryCode,
          );
          l.referenceLabel = parser.referenceOf(l.categoryCode)?.label || "";
          l.product =
            matches.length === 1
              ? matches[0].id
              : matches.length > 1
                ? ""
                : this.products.some(
                      (p) => p.id === (l.product || this.draft.product),
                    )
                  ? l.product || this.draft.product
                  : "";
        }
      },
      add(parsed, replaceId) {
        const lines = parsed.map((p) => ({
          ...p,
          id: M.id("FILE"),
          product: "",
          cost: "",
          expenses: 0,
          expiry: "",
          columnMap: { serial: "", pin: "", expiry: "" },
          autoSerials: p.rows.map(() => "AUTO-" + crypto.randomUUID()),
          rows: p.rows.map((r) => ({
            ...r,
            serial: r.serial || "AUTO-" + crypto.randomUUID(),
          })),
        }));
        const kept = this.draft.lines.filter((l) => l.id !== replaceId);
        if ([...kept, ...lines].reduce((n, l) => n + l.rows.length, 0) > 50000)
          throw Error("الحد الأقصى للطلبية 50,000 بطاقة");
        this.draft.lines = [...kept, ...lines];
        this.resolve();
        this.changed();
      },
      async read(event, replaceId) {
        const files = [...event.target.files];
        event.target.value = "";
        if (!files.length) return;
        this.busy = true;
        this.error = "";
        const user = this.vm.currentUser;
        try {
          const parsed = [];
          for (const f of files) {
            try {
              if (!/\.(txt|csv|xlsx)$/i.test(f.name))
                throw Error("الملفات المدعومة TXT وCSV وXLSX");
              parsed.push(...(await parser.read(f)));
            } catch (e) {
              throw Error(f.name + ": " + e.message);
            }
          }
          if (user === this.vm.currentUser) this.add(parsed, replaceId);
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      paste() {
        try {
          if (new Blob([this.text]).size > 15000000)
            throw Error("النص أكبر من 15 ميغابايت");
          this.add(parser.parseText(this.text));
          this.text = "";
          this.error = "";
        } catch (e) {
          this.error = e.message;
        }
      },
      next() {
        this.error = "";
        try {
          if (
            !Number.isInteger(+this.draft.categoryCount) ||
            +this.draft.categoryCount < 1
          )
            throw Error("أدخل عدد الفئات كعدد صحيح أكبر من صفر");
          if (this.step === 0) {
            if (
              !this.draft.agent ||
              !this.draft.provider ||
              !this.draft.sourceId ||
              !this.draft.city
            )
              throw Error("أكمل الوكيل والشركة والمصدر والمحافظة");
            this.step = 1;
          } else {
            if (!this.draft.lines.length)
              throw Error("ارفع ملفات الفئات أولًا");
            this.changed();
            this.step = 2;
          }
        } catch (e) {
          this.error = e.message;
        }
      },
      validate() {
        this.error = "";
        try {
          this.preview = this.vm.engine.checkMultiOrder(this.draft);
          this.exclude = false;
        } catch (e) {
          this.error = e.message;
        }
      },
      send(direct) {
        if (this.busy) return;
        this.busy = true;
        this.error = "";
        try {
          const r = MasalMeetingRules.atomic(this.vm.engine, () => {
            if (
              !this.preview ||
              signature(this.preview) !==
                signature(this.vm.engine.checkMultiOrder(this.draft))
            )
              throw Error("تغير السعر أو نتيجة الفحص؛ ارجع وأعد المعاينة");
            const r = this.vm.engine.submitMultiOrder(
              this.draft,
              this.exclude,
              this.editId,
            );
            if (direct) this.vm.engine.reviewMultiOrder(r.id, "approve");
            persist(this.vm);
            return r;
          });
          this.done = r;
          this.vm.notify(
            direct
              ? "تم اعتماد الطلبية وإضافة بطاقاتها للمخزون"
              : "أرسلت الطلبية للإدارة",
          );
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      restart() {
        Object.assign(this.$data, form.data());
        if (!this.isAdmin)
          this.draft.agent =
            this.vm.actor.agent || this.vm.actor.staffAccount || "";
      },
      load(r) {
        this.restart();
        this.draft = draftOf(r);
        this.draft.categoryCount ??= new Set(
          r.lines.map((l) => l.product),
        ).size;
        this.editId = r.id;
        this.error = r.reason || "";
      },
    },
  };
  const lineView = {
    props: ["lines"],
    components: { "rejected-export": rejectedExport },
    data() {
      return { selected: "", page: 1, onlyErrors: false };
    },
    computed: {
      vm() {
        return this.$root;
      },
      current() {
        return this.lines.find((l) => l.id === this.selected);
      },
      rows() {
        return (
          this.current?.checked ||
          this.current?.rows.map((r, i) => ({
            ...r,
            expiry: r.expiry || this.current.meta.expiry,
            error: this.current.results[i],
          })) ||
          []
        ).filter((r) => !this.onlyErrors || r.error);
      },
      visible() {
        return this.rows.slice((this.page - 1) * 25, this.page * 25);
      },
    },
    watch: {
      selected() {
        this.page = 1;
      },
      onlyErrors() {
        this.page = 1;
      },
    },
  };
  form.components = {
    "order-lines": lineView,
    "rejected-export": rejectedExport,
  };
  const history = {
    data() {
      return {
        query: "",
        status: "",
        selected: "",
        reason: "",
        reviewAction: "",
        busy: false,
        expanded: true,
        page: 1,
      };
    },
    components: { "order-lines": lineView },
    computed: {
      vm() {
        return this.$root;
      },
      admin() {
        return admin(this.vm.engine);
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return (this.vm.s.pendingOrders || []).filter(
          (r) =>
            r.version === 2 &&
            this.vm.engine.allowed(r.agent) &&
            (!this.status || r.status === this.status) &&
            [
              r.id,
              r.meta.supplier,
              this.vm.nameOf("agents", r.agent),
              ...r.lines.map((l) => this.vm.nameOf("products", l.product)),
            ]
              .join(" ")
              .toLowerCase()
              .includes(q),
        );
      },
      current() {
        return this.rows.find((r) => r.id === this.selected);
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      status() {
        this.page = 1;
      },
      selected() {
        this.reason = "";
      },
    },
    methods: {
      start(r, action) {
        this.selected = r.id;
        this.reason = "";
        this.reviewAction = action;
        this.vm.$nextTick(() =>
          this.$refs.reviewPanel?.scrollIntoView({
            behavior: "smooth",
            block: "center",
          }),
        );
      },
      review(action) {
        this.error = "";
        this.busy = true;
        try {
          MasalMeetingRules.atomic(this.vm.engine, () => {
            this.vm.engine.reviewMultiOrder(this.selected, action, this.reason);
            persist(this.vm);
          });
          this.reviewAction = "";
          this.vm.notify("تمت مراجعة الطلبية");
        } catch (e) {
          this.vm.notify(e.message, true);
        } finally {
          this.busy = false;
        }
      },
      edit(r) {
        this.vm.importTab = "new";
        this.vm.$nextTick(() => this.vm.$refs.cashOrder.$refs.multi.load(r));
      },
    },
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      try {
        if (
          d.s.importReferenceCatalog?.revision !== "asiacell-reference-28-v1"
        ) {
          const s = copy(d.s);
          if (initializeReferenceCatalog(s)) {
            MasalStateStorage.write(s);
            d.s = s;
          }
        }
      } catch (e) {
        d.referenceCatalogError = e.message;
      }
      return d;
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      if (this.referenceCatalogError)
        this.notify(
          "تعذر إضافة فئات المرجع: " + this.referenceCatalogError,
          true,
        );
    };
    const legacyReview = P.reviewCashOrder;
    P.reviewCashOrder = function (id, accepted, reason) {
      if (this.s.pendingOrders?.some((r) => r.id === id && r.version === 2))
        return this.reviewMultiOrder(
          id,
          accepted ? "approve" : "reject",
          reason,
        );
      return legacyReview.call(this, id, accepted, reason);
    };
    const save = o.methods.saveEntity;
    o.methods.saveEntity = function () {
      if (this.page === "products") {
        try {
          const c = parser.codes([
            this.editForm.orderIdentifier,
            ...parser.codes(this.editForm.importCodes),
          ]);
          if (c.some((x) => !/^[A-Z0-9][A-Z0-9-]{0,39}$/.test(x)))
            throw Error("رموز الفئة أحرف إنكليزية وأرقام وشرطة فقط");
          if (
            this.s.products.some(
              (p) =>
                p.id !== this.editForm.id &&
                p.provider === this.editForm.provider &&
                parser
                  .codes([p.orderIdentifier, ...parser.codes(p.importCodes)])
                  .some((x) => c.includes(x)),
            )
          )
            throw Error("رمز الاستيراد مستخدم لفئة أخرى ضمن الشركة");
          this.editForm.importCodes = c.join(", ");
          delete this.editForm.orderIdentifier;
        } catch (e) {
          this.notify(e.message, true);
          return;
        }
      }
      return save.call(this);
    };
    const openCategory = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      openCategory.call(this, row);
      if (this.page === "products" && this.modal?.kind === "edit") {
        this.editForm.importCodes = parser
          .codes([
            this.editForm.orderIdentifier,
            ...parser.codes(this.editForm.importCodes),
          ])
          .join(", ");
        delete this.editForm.orderIdentifier;
      }
    };
    const editor = o.components["category-editor"];
    o.components["legacy-cash-order-form"] = o.components["cash-order-form"];
    o.components["multi-order-form"] = form;
    const review = o.components["pending-order-review"];
    o.components["legacy-order-review"] = {
      ...review,
      computed: {
        ...review.computed,
        rows() {
          return review.computed.rows.call(this).filter((r) => r.version !== 2);
        },
      },
    };
    o.components["multi-order-history"] = history;
    const legacyForm = o.components["legacy-cash-order-form"],
      legacyHistory = o.components["legacy-order-review"];
    o.components["cash-order-form"] = {
      components: { "legacy-form": legacyForm, "multi-form": form },
    };
    o.components["pending-order-review"] = {
      components: { "multi-history": history, "legacy-history": legacyHistory },
    };
  }
  root.MasalMultiOrders = { install, draftOf, initializeReferenceCatalog };
})(globalThis);

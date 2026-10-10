(function (root) {
  "use strict";
  const Access = root.MasalAccess || null;
  const date = () => new Date().toISOString(),
    businessDay = (t) =>
      new Intl.DateTimeFormat("en-CA", {
        timeZone: "Asia/Baghdad",
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
      }).format(t ? new Date(t) : new Date()),
    day = () => businessDay();
  const id = (p) =>
    p +
    "-" +
    (
      globalThis.crypto?.randomUUID?.() ||
      Date.now() + "-" + Math.random().toString(16).slice(2)
    ).slice(0, 12);
  const clone = (v) => JSON.parse(JSON.stringify(v));
  function seed() {
    const agents = [
      {
        id: "A1",
        name: "وكالة بغداد المركزية",
        type: "رئيسي",
        parent: "",
        city: "بغداد",
        phone: "07700000001",
        active: true,
        color: "#0284C7",
        support: "دعم بغداد • 07700000001",
        header: "أهلاً بكم في شبكة ماسال",
        footer: "احتفظ بالوصل حتى إتمام التعبئة",
        reprint: 5,
      },
      {
        id: "A2",
        name: "وكالة الفرات",
        type: "رئيسي",
        parent: "",
        city: "البصرة",
        phone: "07700000002",
        active: true,
        color: "#0F766E",
        support: "دعم الفرات • 07700000002",
        header: "وكالة الفرات",
        footer: "شكرًا لثقتكم",
        reprint: 3,
      },
      {
        id: "A3",
        name: "توزيع الكرادة",
        type: "فرعي",
        parent: "A1",
        city: "بغداد",
        phone: "07700000003",
        active: true,
        color: "#0284C7",
      },
    ];
    const products = [
      {
        id: "C1",
        name: "زين • 5,000 دينار",
        provider: "P1",
        face: 5000,
        currency: "IQD",
        kind: "محلية",
        active: true,
        min: 4500,
        limit: 10,
        dailyQty: 100,
        dailyAmount: 500000,
        fields: "serial,pin,expiry",
        order: 1,
      },
      {
        id: "C2",
        name: "آسياسيل • 10,000 دينار",
        provider: "P2",
        face: 10000,
        currency: "IQD",
        kind: "محلية",
        active: true,
        min: 9000,
        limit: 5,
        dailyQty: 50,
        dailyAmount: 500000,
        fields: "serial,pin,expiry",
        order: 2,
      },
      {
        id: "C3",
        name: "كورك • 5,000 دينار",
        provider: "P3",
        face: 5000,
        currency: "IQD",
        kind: "محلية",
        active: true,
        min: 4500,
        limit: 10,
        dailyQty: 100,
        dailyAmount: 500000,
        fields: "pin,expiry",
        order: 3,
      },
      {
        id: "C4",
        name: "PlayStation • $10",
        provider: "P4",
        face: 10,
        currency: "USD",
        kind: "عالمية",
        active: true,
        min: 14500,
        limit: 3,
        dailyQty: 30,
        dailyAmount: 500000,
        fields: "serial,pin,expiry,cvc,reference",
        order: 4,
      },
    ];
    const s = {
      version: 1,
      agents,
      products,
      providers: [
        {
          id: "P1",
          name: "زين العراق",
          supplier: "المجهز الوطني",
          organizer: "غير محدد",
          connection: "ملفات",
          active: true,
        },
        {
          id: "P2",
          name: "آسياسيل",
          supplier: "الموزع المركزي",
          connection: "ملفات",
          active: true,
        },
        {
          id: "P3",
          name: "كورك",
          supplier: "مجهز الشمال",
          connection: "ملفات",
          active: true,
        },
        {
          id: "P4",
          name: "PlayStation",
          supplier: "المجهز العالمي",
          connection: "ملفات",
          active: true,
        },
        {
          id: "P5",
          name: "الرابعة",
          supplier: "الرابعة",
          connection: "API",
          active: false,
        },
      ],
      representatives: [],
      posTypes: [
        { id: "POSTYPE-STORE", name: "متجر", active: true },
        { id: "POSTYPE-SUPER", name: "سوبر ماركت", active: true },
        { id: "POSTYPE-RESTAURANT", name: "مطعم", active: true },
      ],
      pos: [
        {
          id: "POS1",
          name: "مركز النور للاتصالات",
          owner: "أحمد علي",
          agent: "A3",
          city: "بغداد",
          address: "الكرادة • شارع 62",
          phone: "07700001001",
          email: "",
          lat: 33.3,
          lng: 44.43,
          serial: "DEMO-PAX-1001",
          model: "PAX A920",
          version: "1.0.0",
          active: true,
          online: true,
          lastSeen: date(),
          reprint: 5,
          representativeIds: [],
          documents: [],
        },
        {
          id: "POS2",
          name: "مكتبة الرافدين",
          owner: "سلام شاكر",
          agent: "A1",
          city: "بغداد",
          address: "المنصور",
          phone: "07700001002",
          lat: 33.32,
          lng: 44.34,
          serial: "DEMO-SUN-1002",
          model: "Sunmi V2",
          version: "1.0.0",
          active: true,
          online: true,
          lastSeen: date(),
          reprint: 5,
          representativeIds: [],
          documents: [],
        },
        {
          id: "POS3",
          name: "اتصالات العشار",
          owner: "حيدر حسن",
          agent: "A2",
          city: "البصرة",
          address: "العشار",
          phone: "07700001003",
          lat: 30.5,
          lng: 47.82,
          serial: "DEMO-PAX-1003",
          model: "PAX A920",
          version: "1.0.0",
          active: true,
          online: false,
          lastSeen: date(),
          reprint: 3,
          representativeIds: [],
          documents: [],
        },
      ],
      users: [
        {
          id: "U1",
          name: "مدير النظام",
          role: "owner",
          agent: "",
          assigned: [],
          active: true,
        },
        {
          id: "U2",
          name: "مشرف — بغداد",
          role: "supervisor",
          agent: "",
          assigned: ["A1"],
          active: true,
        },
        {
          id: "U3",
          name: "وكيل رئيسي — وكالة بغداد",
          role: "main",
          agent: "A1",
          active: true,
        },
        {
          id: "U4",
          name: "وكيل فرعي — توزيع الكرادة",
          role: "sub",
          agent: "A3",
          active: true,
        },
        {
          id: "U5",
          name: "نقطة بيع — مركز النور",
          role: "pos",
          agent: "A3",
          pos: "POS1",
          active: true,
        },
      ],
      batches: [],
      cards: [],
      prices: [],
      priceRequests: [],
      ledger: [],
      sales: [],
      claims: [],
      exports: [],
      integrations: [],
      notifications: [],
      tickets: [
        {
          id: "T1",
          agent: "A3",
          title: "توقف ورق الطابعة",
          description: "طلب إرشادات إعادة طباعة الوصل بعد استبدال الورق",
          status: "مفتوحة",
          priority: "متوسطة",
          replies: [],
          time: date(),
        },
      ],
      audit: [],
      news: [
        {
          id: "N1",
          title: "شبكتك أقرب، أعمالك أسهل",
          body: "إدارة متكاملة للبطاقات والوكلاء ونقاط البيع، من مكان واحد.",
          active: true,
        },
      ],
      settings: {
        app: true,
        registration: true,
        login: true,
        sales: true,
        printing: true,
        minVersion: "1.0.0",
        velocity: 3,
        expiryDays: 30,
        providerDaily: 1000000,
        reprint: 5,
        idle: 60,
        brand: "ماسال",
        support: "07700000000",
        email: "support@example.test",
        theme: "#0F766E",
        receiptWidth: 80,
        receiptLanguage: "العربية",
        blockedIPs: "",
        riskRegion: "العراق",
      },
      sessions: [],
      requests: {},
    };
    for (const a of ["A1", "A2"])
      for (const p of products) {
        const b = {
          id: "B-" + a + "-" + p.id,
          agent: a,
          product: p.id,
          provider: p.provider,
          city: a === "A1" ? "بغداد" : "البصرة",
          supplier: "مجهز تجريبي",
          expiry:
            p.id === "C3"
              ? new Date(Date.now() + 18 * 86400000).toISOString().slice(0, 10)
              : "2027-12-31",
          created: date(),
          status: "Loaded",
          quantity: 24,
          cost: p.min - 100,
          expenses: 0,
        };
        s.batches.push(b);
        for (let i = 1; i <= 24; i++)
          s.cards.push({
            id: "CARD-" + a + "-" + p.id + "-" + i,
            batch: b.id,
            agent: a,
            product: p.id,
            serial: p.id === "C3" ? "" : "DEMO-" + a + p.id + i,
            internal: "MSL-" + a + p.id + i,
            pin: "DEMO-NOT-VALID-" + a + p.id + i,
            expiry: b.expiry,
            cost: b.cost,
            status: "Available",
            created: date(),
            cvc: p.kind === "عالمية" ? "DEMO" : "",
            reference: p.kind === "عالمية" ? "SAMPLE-" + i : "",
          });
      }
    for (const a of agents)
      for (const p of products)
        s.prices.push({
          id: a.id + "-" + p.id,
          agent: a.id,
          product: p.id,
          price: p.min + 250,
          effective: "2026-01-01",
          region: "الكل",
        });
    for (const [account, amount] of [
      ["A1", 2000000],
      ["A2", 1500000],
      ["A3", 750000],
      ["POS1", 350000],
      ["POS2", 500000],
      ["POS3", 250000],
    ])
      s.ledger.push({
        id: id("L"),
        group: id("DEP"),
        account,
        amount,
        kind: "إيداع افتتاحي تجريبي",
        time: date(),
        counterparty: "EXTERNAL",
      });
    return s;
  }
  class Engine {
    constructor(state = seed(), user = "U1") {
      this.s = state;
      this.user = user;
    }
    actor() {
      return this.s.users.find((u) => u.id === this.user);
    }
    descendants(a) {
      const out = [a];
      for (let i = 0; i < out.length; i++)
        for (const x of this.s.agents.filter((x) => x.parent === out[i]))
          if (!out.includes(x.id)) out.push(x.id);
      return out;
    }
    scope() {
      return Access.scope(this.s, this.actor());
    }
    can(key) {
      return Access.can(this.actor(), key, this.s);
    }
    requirePermission(key, agent) {
      if (key === "security.app") {
        if (!this.actor()?.active) throw Error("الحساب موقوف");
      } else this.require(agent);
      if (!this.can(key)) throw Error("ليست لديك صلاحية: " + key);
    }
    allowed(agent) {
      return this.scope().includes(agent);
    }
    require(agent, roles) {
      const u = this.actor();
      if (!u?.active || !this.s.settings.app)
        throw Error("الحساب أو التطبيق موقوف");
      if (agent && !this.allowed(agent))
        throw Error("هذا السجل خارج نطاق صلاحياتك");
      if (roles && !roles.includes(u.role))
        throw Error("ليست لديك صلاحية تنفيذ هذا الإجراء");
    }
    main(a) {
      let x = this.s.agents.find((x) => x.id === a),
        seen = new Set();
      while (x?.parent) {
        if (seen.has(x.id)) throw Error("حلقة في شجرة الوكلاء");
        seen.add(x.id);
        x = this.s.agents.find((v) => v.id === x.parent);
      }
      return x?.id;
    }
    balance(a) {
      return this.s.ledger
        .filter((l) => l.account === a)
        .reduce((t, l) => t + l.amount, 0);
    }
    refreshBatch(batch) {
      const b = this.s.batches.find((x) => x.id === batch);
      if (
        !b ||
        ["Quarantined", "Exported", "Cancelled by Reversal"].includes(b.status)
      )
        return;
      const cards = this.s.cards.filter((c) => c.batch === batch);
      const available = cards.filter((c) => c.status === "Available").length;
      b.status =
        available === cards.length
          ? "Loaded"
          : available === 0
            ? "Completed"
            : "Partially Used";
    }
    log(action, entity, before, after) {
      this.s.audit.unshift({
        id: id("LOG"),
        time: date(),
        user: this.user,
        name: this.actor()?.name,
        action,
        entity,
        before: clone(before ?? null),
        after: clone(after ?? null),
        source: "جلسة محلية",
      });
    }
    accountAgent(a) {
      return this.s.pos.find((p) => p.id === a)?.agent || a;
    }
    transfer(from, to, amount) {
      this.requirePermission("wallets.transfer");
      this.require(this.accountAgent(from));
      amount = Number(amount);
      if (!Number.isFinite(amount) || amount <= 0 || from === to)
        throw Error("حدد حسابين مختلفين ومبلغًا موجبًا");
      const owner = this.s.agents.find((a) => a.id === from);
      if (!owner) throw Error("مصدر التحويل يجب أن يكون وكيلاً");
      const targetAgent = this.s.agents.find((a) => a.id === to),
        targetPOS = this.s.pos.find((p) => p.id === to);
      if (!targetAgent && !targetPOS) throw Error("الحساب المستلم غير موجود");
      if (
        !this.descendants(from).includes(targetPOS?.agent || targetAgent.id) ||
        targetAgent?.id === from
      )
        throw Error("التحويل مسموح للأبناء ونقاط البيع التابعة فقط");
      if (
        !["owner", "supervisor", "employee"].includes(this.actor().role) &&
        from !== this.actor().agent
      )
        throw Error("يمكنك التحويل من محفظتك فقط");
      if (this.balance(from) < amount) throw Error("الرصيد غير كافٍ");
      const group = id("TR");
      this.s.ledger.push(
        {
          id: id("L"),
          group,
          account: from,
          amount: -amount,
          kind: "تحويل صادر",
          counterparty: to,
          time: date(),
        },
        {
          id: id("L"),
          group,
          account: to,
          amount,
          kind: "تحويل وارد",
          counterparty: from,
          time: date(),
        },
      );
      this.log("تحويل مالي", group, null, { from, to, amount });
      return group;
    }
    deposit(account, amount, reference) {
      this.requirePermission("wallets.deposit");
      this.require(this.accountAgent(account));
      amount = Number(amount);
      if (!(amount > 0 && Number.isFinite(amount)) || !reference?.trim())
        throw Error("المبلغ الموجب ومرجع الإيداع مطلوبان");
      if (
        !this.s.agents.some((a) => a.id === account) &&
        !this.s.pos.some((p) => p.id === account)
      )
        throw Error("حساب غير موجود");
      const l = {
        id: id("L"),
        group: id("DEP"),
        account,
        amount,
        kind: "إيداع",
        reference,
        time: date(),
      };
      this.s.ledger.push(l);
      this.log("إيداع مالي", l.id, null, l);
    }
    validateImport(meta, rows) {
      this.requirePermission("import.preview");
      this.require(meta.agent);
      if (this.main(meta.agent) !== meta.agent)
        throw Error("المخزون يخص الوكيل الرئيسي فقط");
      const p = this.s.products.find((p) => p.id === meta.product);
      if (!p) throw Error("اختر الفئة");
      if (!Number.isFinite(+meta.cost) || +meta.cost <= 0 || +meta.expenses < 0)
        throw Error("تكلفة التوريد غير صحيحة");
      const fields = root.MasalCardFields || null,
        cfg = fields.policy(p);
      const seen = new Set(this.s.cards.map((c) => c.pin)),
        seenSerial = new Set(
          this.s.cards
            .filter((c) => c.serial)
            .map((c) => c.product + "|" + c.serial),
        );
      return rows.map((source, i) => {
        const r = {};
        for (const f of fields.catalog)
          if (cfg[f.key] !== "unused")
            r[f.key] = String(source[f.key] ?? "").trim();
        r.serial = String(source.serial ?? "").trim();
        r.expiry = r.expiry || meta.expiry;
        let error = "";
        const missing = fields.catalog.find(
          (f) => cfg[f.key] === "required" && !r[f.key],
        );
        if (missing)
          error =
            missing.key === "pin"
              ? "رمز البطاقة مفقود؛ أكمل الرمز في هذا السطر وتأكد من ربط عمود pin"
              : "حقل مطلوب مفقود: " +
                missing.label +
                "؛ أكمله في الملف وتأكد من ربط عموده";
        else if (seen.has(r.pin))
          error =
            "رمز البطاقة مكرر داخل الملف أو موجود في المخزون؛ احذف الصف المكرر ثم أعد الفحص";
        else if (r.serial && seenSerial.has(p.id + "|" + r.serial))
          error =
            "سيريال البطاقة مكرر ضمن الفئة؛ صحح السيريال أو احذف الصف المكرر";
        else if (
          !/^\d{4}-\d{2}-\d{2}$/.test(r.expiry) ||
          isNaN(Date.parse(r.expiry)) ||
          new Date(r.expiry).toISOString().slice(0, 10) !== r.expiry ||
          r.expiry <= day()
        )
          error =
            "تاريخ الانتهاء مفقود أو منتهٍ أو بتنسيق غير صحيح؛ استخدم تاريخًا بعد اليوم بصيغة YYYY-MM-DD أو حدد التاريخ الافتراضي";
        if (!error) {
          seen.add(r.pin);
          if (r.serial) seenSerial.add(p.id + "|" + r.serial);
        }
        return { ...r, row: i + 1, error };
      });
    }

    importBatch(meta, rows) {
      this.requirePermission("import.approve");
      const checked = this.validateImport(meta, rows),
        good = checked.filter((r) => !r.error);
      if (!good.length) throw Error("لا توجد بطاقات صالحة");
      const p = this.s.products.find((p) => p.id === meta.product),
        b = {
          ...meta,
          id: id("B"),
          provider: p.provider,
          cost: +meta.cost,
          expenses: +meta.expenses || 0,
          quantity: good.length,
          rejected: checked.length - good.length,
          status: "Loaded",
          created: date(),
        };
      this.s.batches.unshift(b);
      for (const r of good)
        this.s.cards.push({
          ...r,
          id: id("CARD"),
          batch: b.id,
          agent: b.agent,
          product: b.product,
          internal: id("MSL"),
          cost: b.cost + b.expenses / good.length,
          created: b.created,
          status: "Available",
        });
      this.log("تحميل دفعة", b.id, null, {
        quantity: good.length,
        rejected: b.rejected,
        agent: b.agent,
      });
      return b;
    }
    batchAction(batch, action) {
      if (!["cancel", "quarantine"].includes(action))
        throw Error("إجراء غير صالح");
      this.requirePermission(
        action === "cancel" ? "inventory.cancel" : "inventory.quarantine",
      );
      const b = this.s.batches.find((x) => x.id === batch);
      if (!b) throw Error("الدفعة غير موجودة");
      this.require(b.agent);
      const cards = this.s.cards.filter((c) => c.batch === batch);
      if (
        action === "cancel" &&
        cards.some((c) => !["Available", "Quarantined"].includes(c.status))
      )
        throw Error("لا يمكن إلغاء دفعة خرجت منها بطاقات");
      const before = b.status;
      b.status = action === "cancel" ? "Cancelled by Reversal" : "Quarantined";
      cards
        .filter(
          (c) =>
            c.status === "Available" ||
            (action === "cancel" && c.status === "Quarantined"),
        )
        .forEach(
          (c) =>
            (c.status =
              action === "cancel" ? "Cancelled by Reversal" : "Quarantined"),
        );
      this.log(
        action === "cancel" ? "إلغاء دفعة" : "حجر دفعة",
        batch,
        before,
        b.status,
      );
    }
    proposePrices(changes) {
      this.requirePermission("prices.propose");
      this.require(null);
      if (!changes.length) throw Error("قائمة التغييرات فارغة");
      const keys = new Set();
      for (const c of changes) {
        this.require(c.agent);
        const p = this.s.products.find((p) => p.id === c.product);
        if (
          !p ||
          !Number.isFinite(+c.price) ||
          +c.price <= 0 ||
          +c.price < p.min
        )
          throw Error("الفئة غير موجودة أو سعر البيع دون الحد الأدنى المسموح");
        const k = c.agent + "|" + c.product;
        if (keys.has(k)) throw Error("المنتج مكرر في الملف");
        keys.add(k);
      }
      const r = {
        id: id("PR"),
        creator: this.user,
        time: date(),
        status: "قيد المراجعة",
        changes: changes.map((c) => ({
          ...c,
          price: +c.price,
          old:
            this.s.prices.find(
              (p) => p.agent === c.agent && p.product === c.product,
            )?.price ?? null,
        })),
      };
      this.s.priceRequests.unshift(r);
      this.log("اقتراح أسعار", r.id, null, r);
      if (
        this.actor().role === "owner" ||
        (this.actor().role === "main" &&
          changes.every((c) => c.agent === this.actor().agent))
      )
        this.approvePrices(r.id);
      return r;
    }
    approvePrices(req) {
      this.require(null);
      const r = this.s.priceRequests.find((r) => r.id === req);
      const own =
        r &&
        this.actor().role === "main" &&
        r.creator === this.user &&
        r.changes.length > 0 &&
        r.changes.every((c) => c.agent === this.actor().agent);
      this.requirePermission(own ? "prices.propose" : "prices.approve");
      if (!r || r.status !== "قيد المراجعة") throw Error("الطلب غير متاح");
      if (r.creator === this.user && this.actor().role !== "owner" && !own)
        throw Error("يجب أن يعتمد التغيير شخص ثانٍ");
      r.changes.forEach((c) => {
        this.require(c.agent);
        const old = this.s.prices.find(
          (p) => p.agent === c.agent && p.product === c.product,
        );
        if ((old?.price ?? null) !== c.old)
          throw Error("تغير السعر منذ المراجعة؛ أعد تقديم الطلب");
      });
      for (const c of r.changes) {
        let p = this.s.prices.find(
          (p) => p.agent === c.agent && p.product === c.product,
        );
        if (p) {
          p.price = c.price;
          p.effective = day();
        } else this.s.prices.push({ id: id("PRICE"), ...c, effective: day() });
      }
      r.status = "معتمد";
      r.approver = this.user;
      r.approvedAt = date();
      this.log(
        "اعتماد أسعار",
        r.id,
        r.changes.map((c) => c.old),
        r.changes,
      );
    }
    reversePrices(req) {
      this.requirePermission("prices.reverse");
      this.require(null);
      const r = this.s.priceRequests.find((r) => r.id === req);
      if (!r || r.status !== "معتمد") throw Error("لا يمكن التراجع");
      r.changes.forEach((c) => {
        this.require(c.agent);
        if (
          this.s.prices.find(
            (p) => p.agent === c.agent && p.product === c.product,
          )?.price !== c.price
        )
          throw Error("يوجد تعديل أحدث؛ قدم طلبًا جديدًا");
      });
      for (const c of r.changes) {
        const p = this.s.prices.find(
          (p) => p.agent === c.agent && p.product === c.product,
        );
        p.price = c.old ?? 0;
      }
      r.status = "تم التراجع";
      this.log("تراجع أسعار", r.id, r.changes, null);
    }
    sell(posID, product, quantity, key) {
      this.requirePermission("sell.create");
      const old = this.s.sales.find((t) => t.key === key);
      if (old) {
        this.require(old.agent);
        if (this.actor().role === "pos" && this.actor().pos !== old.pos)
          throw Error("العملية خارج نطاق نقطة البيع");
        return old;
      }
      const pos = this.saleAccount(posID),
        p = this.s.products.find((p) => p.id === product);
      if (!pos || !p) throw Error("اختر نقطة بيع وفئة");
      this.require(pos.agent);
      if (this.actor().role === "pos" && this.actor().pos !== posID)
        throw Error("نقطة البيع خارج الصلاحية");
      if (!this.s.settings.sales) throw Error("البيع موقوف من إعدادات النظام");
      if (!this.s.settings.printing)
        throw Error("الطباعة موقوفة من إعدادات النظام");
      if (!pos.active) throw Error("نقطة البيع موقوفة؛ راجع الوكيل المسؤول");
      if (!pos.online)
        throw Error(
          "جهاز النقطة غير متصل؛ ابدأ جلسة الجهاز التجريبية لمتابعة الفحص المحلي",
        );
      if (
        !p.active ||
        !this.s.providers.find((v) => v.id === p.provider)?.active
      )
        throw Error("الفئة أو المزود غير مفعل");
      for (const a of this.s.agents.filter((a) =>
        this.descendants(a.id).includes(pos.agent),
      ))
        if (!a.active) throw Error("أحد وكلاء الشجرة موقوف");
      const q = Number(quantity);
      if (!Number.isInteger(q) || q <= 0)
        throw Error("عدد البطاقات يجب أن يكون عددًا صحيحًا موجبًا");
      const price = this.s.prices.find(
        (x) =>
          x.agent === pos.agent &&
          x.product === product &&
          x.effective <= day(),
      )?.price;
      if (!price || price < p.min)
        throw Error("لا يوجد سعر فعال صالح لهذه الفئة");
      const recent = this.s.sales.filter(
        (t) => t.pos === posID && businessDay(t.time) === day(),
      );
      if (
        recent.length &&
        (Date.now() - Date.parse(recent[recent.length - 1].time)) / 1000 <
          this.s.settings.velocity
      )
        throw Error("انتظر الفاصل المحدد بين العمليات");
      const own = recent.filter((t) => t.product === product);
      if (
        (!this.dailyCategoryOverride?.(posID, product) &&
          p.dailyQty > 0 &&
          own.reduce((n, t) => n + t.quantity, 0) + q > p.dailyQty) ||
        (p.dailyAmount > 0 &&
          own.reduce((n, t) => n + t.total, 0) + q * price > p.dailyAmount)
      )
        throw Error("تم بلوغ الحد اليومي للفئة");
      if (
        recent
          .filter(
            (t) =>
              this.s.products.find((x) => x.id === t.product)?.provider ===
              p.provider,
          )
          .reduce((n, t) => n + t.total, 0) +
          q * price >
        this.s.settings.providerDaily
      )
        throw Error("تم بلوغ الحد اليومي للمزود");
      if (this.balance(posID) < q * price)
        throw Error("رصيد نقطة البيع غير كافٍ");
      if (
        this.agentProductAllowed
          ? !this.agentProductAllowed(pos.agent, product)
          : p.allowedAgents?.length &&
            !p.allowedAgents.some((a) =>
              this.descendants(a).includes(pos.agent),
            )
      )
        throw Error("الفئة غير متاحة لهذا الوكيل");
      if (p.allowedCities?.length && !p.allowedCities.includes(pos.city))
        throw Error("الفئة غير متاحة في هذه المحافظة");
      const cards = this.s.cards
        .filter(
          (c) =>
            c.agent === this.main(pos.agent) &&
            c.product === product &&
            c.status === "Available" &&
            c.expiry > day(),
        )
        .sort(
          (a, b) =>
            a.expiry.localeCompare(b.expiry) ||
            a.created.localeCompare(b.created) ||
            a.id.localeCompare(b.id),
        )
        .slice(0, q);
      if (cards.length < q) throw Error("المخزون المتاح غير كافٍ");
      const t = {
        id: id("TX"),
        key,
        pos: posID,
        agent: pos.agent,
        product,
        quantity: q,
        price,
        total: q * price,
        cost: cards.reduce((n, c) => n + c.cost, 0),
        cards: cards.map((c) => c.id),
        time: date(),
        status: "Print Requested",
        attempts: [],
        reprints: 0,
        exposed: true,
      };
      cards.forEach((c) => {
        c.status = "Issued";
        c.sale = t.id;
      });
      [...new Set(cards.map((c) => c.batch))].forEach((b) =>
        this.refreshBatch(b),
      );
      this.s.sales.push(t);
      this.s.ledger.push({
        id: id("L"),
        group: t.id,
        account: posID,
        amount: -t.total,
        kind: "بيع بطاقة",
        time: t.time,
      });
      this.log("إصدار وطلب طباعة", t.id, null, {
        quantity: q,
        total: t.total,
        product,
        pos: posID,
      });
      return t;
    }
    printResult(tx, success) {
      this.requirePermission("sell.result");
      const t = this.s.sales.find((t) => t.id === tx);
      if (!t) throw Error("العملية غير موجودة");
      this.require(t.agent);
      if (this.actor().role === "pos" && this.actor().pos !== t.pos)
        throw Error("خارج نطاق نقطة البيع");
      if (!["Print Requested", "Reprint Requested"].includes(t.status))
        throw Error("لا يوجد طلب طباعة معلق");
      const previous = t.status;
      t.status = success
        ? previous === "Reprint Requested"
          ? "Reprinted"
          : "Printed"
        : "Print Failed";
      t.attempts.push({
        time: date(),
        user: this.user,
        device: t.pos,
        status: t.status,
      });
      this.s.cards
        .filter((c) => t.cards.includes(c.id))
        .forEach((c) => (c.status = t.status));
      this.log("نتيجة طباعة", t.id, previous, t.status);
    }
    reprint(tx, reason) {
      this.requirePermission("sell.reprint");
      const t = this.s.sales.find((t) => t.id === tx);
      if (!t) throw Error("العملية غير موجودة");
      this.require(t.agent);
      if (this.actor().role === "pos" && this.actor().pos !== t.pos)
        throw Error("خارج نطاق نقطة البيع");
      if (
        !this.s.settings.printing ||
        !this.s.pos.find((p) => p.id === t.pos)?.active ||
        !this.s.pos.find((p) => p.id === t.pos)?.online
      )
        throw Error("الطباعة موقوفة");
      if (!reason?.trim()) throw Error("سبب إعادة الطباعة مطلوب");
      if (!["Printed", "Print Failed", "Reprinted"].includes(t.status))
        throw Error("حالة العملية لا تسمح بإعادة الطباعة");
      const limit =
        this.s.pos.find((p) => p.id === t.pos)?.reprint ??
        this.s.settings.reprint;
      if (t.reprints >= limit)
        throw Error(
          "تجاوز حد إعادة الطباعة؛ يلزم تعديل الحد بواسطة الإدارة بعد المراجعة",
        );
      t.reprints++;
      t.status = "Reprint Requested";
      t.attempts.push({
        time: date(),
        reason,
        user: this.user,
        device: t.pos,
        status: "Reprint Requested",
      });
      this.log("طلب إعادة طباعة", t.id, null, { reason, attempt: t.reprints });
      return t;
    }
    claim(batch, reason) {
      this.requirePermission("claims.create");
      const b = this.s.batches.find((b) => b.id === batch);
      if (!b) throw Error("اختر دفعة");
      this.require(b.agent);
      if (!reason?.trim()) throw Error("سبب المطالبة مطلوب");
      if (this.s.claims.some((c) => c.batch === batch && c.status === "معلقة"))
        throw Error("توجد مطالبة معلقة");
      const cards = this.s.cards.filter(
        (c) =>
          c.batch === batch && ["Available", "Quarantined"].includes(c.status),
      );
      if (!cards.length) throw Error("لا توجد بطاقات متبقية");
      this.batchAction(batch, "quarantine");
      const c = {
        id: id("CL"),
        batch,
        agent: b.agent,
        reason,
        status: "معلقة",
        quantity: cards.length,
        value: cards.reduce((n, c) => n + c.cost, 0),
        time: date(),
      };
      this.s.claims.unshift(c);
      this.log("فتح مطالبة", c.id, null, c);
      return c;
    }
    settle(claim, outcome) {
      this.requirePermission("claims.settle");
      const c = this.s.claims.find((c) => c.id === claim);
      if (!c) throw Error("المطالبة غير موجودة");
      this.require(c.agent);
      if (
        c.status !== "معلقة" ||
        !["استبدال", "إعادة تفعيل", "رفض", "تعويض", "خسارة"].includes(outcome)
      )
        throw Error("تسوية غير صالحة");
      const cards = this.s.cards.filter(
        (x) =>
          x.batch === c.batch && ["Quarantined", "Exported"].includes(x.status),
      );
      if (
        outcome === "إعادة تفعيل" &&
        cards.some((x) => x.status === "Exported")
      )
        throw Error(
          "البطاقات التي تم تصدير رموزها لا تعاد للبيع؛ يلزم بديل من المزود",
        );
      c.status = outcome;
      c.settled = date();
      if (outcome === "إعادة تفعيل") {
        cards.forEach((x) => (x.status = "Available"));
        this.s.batches.find((x) => x.id === c.batch).status = "Loaded";
      } else {
        cards.forEach(
          (x) =>
            (x.status =
              outcome === "خسارة" || outcome === "رفض"
                ? "Written Off"
                : "Awaiting Replacement"),
        );
      }
      this.log("تسوية مطالبة", c.id, "معلقة", outcome);
    }
  }
  root.Masal = { Engine, seed, id, clone, day, businessDay };
})(globalThis);

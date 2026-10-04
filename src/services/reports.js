(function (root) {
  "use strict";
  function build(e, f = {}) {
    e.requirePermission("reports.view");
    const s = e.s,
      can = (k) => e.can(k),
      sum = (rows, k) => rows.reduce((n, r) => n + (Number(r[k]) || 0), 0),
      name = (list, id) =>
        s[list].find((x) => x.id === id)?.name ||
        (list === "pos" ? s.agents.find((x) => x.id === id)?.name : null) ||
        id ||
        "—",
      day = root.Masal.businessDay;
    if (f.from && f.to && f.from > f.to)
      throw Error("تاريخ البداية يجب أن يسبق تاريخ النهاية");
    const period = (t) =>
      (!f.from && !f.to) ||
      (!!t && (!f.from || day(t) >= f.from) && (!f.to || day(t) <= f.to));
    const allowedAgent = (a) =>
      e.allowed(a) && (!f.agent || e.descendants(f.agent).includes(a));
    const allowedPOS = (p) =>
      allowedAgent(p.agent) &&
      (e.actor().role !== "pos" || p.id === e.actor().pos) &&
      (!f.pos || p.id === f.pos) &&
      (!f.city || p.city === f.city);
    const points = s.pos.filter(allowedPOS),
      pointIDs = new Set([
        ...points.map((p) => p.id),
        ...s.agents
          .filter((a) => a.type === "فرعي" && allowedPOS({ ...a, agent: a.id }))
          .map((a) => a.id),
      ]);
    const product = (id) =>
      (!f.product || id === f.product) &&
      (!f.provider ||
        s.products.find((p) => p.id === id)?.provider === f.provider);
    const saleRole = root.MasalAccess.managementRole(s, e.actor());
    const sales = s.sales
      .filter(
        (t) =>
          pointIDs.has(t.pos) &&
          period(t.time) &&
          product(t.product) &&
          (!f.status || t.status === f.status),
      )
      .map((t) => {
        const acquisition = root.MasalAuditFixes?.loadCost(s, t) ?? t.loadCost;
        return {
          ...t,
          total: saleRole === "pos" ? (t.retailTotal ?? t.total) : t.total,
          cost:
            saleRole === "pos"
              ? (t.credit ?? t.total)
              : Number.isFinite(acquisition)
                ? acquisition
                : null,
        };
      });
    const sections = [],
      add = (id, title, columns, rows, note = "") => {
        if (!can("reports." + id.split("-")[0])) return;
        const cols = columns.filter((c) => !c.permission || can(c.permission));
        sections.push({
          id,
          title,
          snapshot: [
            "inventory",
            "inventory-cards",
            "inventory-products",
            "inventory-providers",
            "network",
            "network-pos",
            "network-categories",
            "network-presence",
            "prices",
            "users",
            "users-overrides",
            "users-permissions",
            "users-times",
            "support-contacts",
            "prices-policies",
            "operations",
            "operations-printing",
            "operations-integrations",
            "wallets-legacy",
          ].includes(id),
          columns: cols,
          rows: rows.map((r) =>
            Object.fromEntries(cols.map((c) => [c.key, r[c.key] ?? "—"])),
          ),
          note,
        });
      };
    const c = (key, label, type = "text", permission) => ({
      key,
      label,
      type,
      permission,
    });
    const money = [
      c("sales", "المبيعات", "money"),
      c("cost", "تكلفة المباع", "money", "data.cost"),
      c("profit", "الربح الإجمالي", "money", "data.profit"),
      c("margin", "نسبة الربح %", "percent", "data.profit"),
    ];
    const aggregate = (rows, id, keyLabel, getName) => {
      const map = new Map();
      for (const t of rows) {
        const key = t[id],
          r = map.get(key) || {
            id: key,
            name: getName(key),
            transactions: 0,
            quantity: 0,
            sales: 0,
            cost: 0,
          };
        r.transactions++;
        r.quantity += t.quantity;
        r.sales += t.total;
        if (t.cost === null) r.unknownCost = true;
        else r.cost += t.cost;
        map.set(key, r);
      }
      return [...map.values()]
        .map((r) => ({
          ...r,
          cost: r.unknownCost ? null : r.cost,
          profit: r.unknownCost ? null : r.sales - r.cost,
          margin: r.unknownCost
            ? null
            : r.sales
              ? (100 * (r.sales - r.cost)) / r.sales
              : 0,
        }))
        .sort((a, b) => b.sales - a.sales);
    };
    add(
      "sales",
      "سجل المبيعات التفصيلي",
      [
        c("id", "رقم العملية"),
        c("time", "التاريخ والوقت", "date"),
        c("agent", "الوكيل"),
        c("pos", "نقطة البيع"),
        c("product", "الفئة"),
        c("provider", "المزود"),
        c("quantity", "الكمية", "number"),
        c("price", "سعر الوحدة", "money"),
        ...money,
        c("status", "الحالة"),
        c("reprints", "إعادة الطباعة", "number"),
      ],
      sales.map((t) => ({
        ...t,
        agent: name("agents", t.agent),
        pos: name("pos", t.pos),
        product: name("products", t.product),
        provider: name(
          "providers",
          s.products.find((p) => p.id === t.product)?.provider,
        ),
        sales: t.total,
        profit: t.cost === null ? null : t.total - t.cost,
        margin:
          t.cost === null
            ? null
            : t.total
              ? (100 * (t.total - t.cost)) / t.total
              : 0,
      })),
      "ربح البيع منفصل عن ربح تحميل ماسال؛ السجلات التي لا تملك تكلفة تحميل موثقة تعرض دون ربح محسوب.",
    );
    for (const [id, title, field, list] of [
      ["products", "المبيعات حسب الفئة", "product", "products"],
      ["agents", "أداء الوكلاء", "agent", "agents"],
      ["pos", "أداء نقاط البيع", "pos", "pos"],
    ])
      add(
        "sales-" + id,
        title,
        [
          c("id", "المعرف"),
          c("name", "الاسم"),
          c("transactions", "العمليات", "number"),
          c("quantity", "الكمية", "number"),
          ...money,
        ],
        aggregate(sales, field, title, (k) => name(list, k)),
      );
    add(
      "sales-printing",
      "سجل محاولات الطباعة",
      [
        c("sale", "رقم العملية"),
        c("time", "التاريخ والوقت", "date"),
        c("user", "المستخدم"),
        c("device", "الجهاز"),
        c("status", "الحالة"),
        c("reason", "السبب"),
      ],
      sales.flatMap((t) =>
        (t.attempts || []).map((r) => ({
          ...r,
          sale: t.id,
          user: name("users", r.user),
          device: name("pos", r.device),
        })),
      ),
      "محاولات الطباعة للعمليات المطابقة لفترة البيع.",
    );
    const stocks = s.cards.filter(
      (x) =>
        allowedAgent(x.agent) &&
        product(x.product) &&
        (!f.pos ||
          e.main(s.pos.find((p) => p.id === f.pos)?.agent) === x.agent) &&
        (!f.city || s.agents.find((a) => a.id === x.agent)?.city === f.city),
    );
    const batches = s.batches.filter((b) =>
      stocks.some((c) => c.batch === b.id),
    );
    add(
      "inventory-purchases",
      "تفاصيل التوريد والطلبيات",
      [
        c("id", "الدفعة"),
        c("created", "تاريخ التحميل", "date"),
        c("agent", "الوكيل"),
        c("product", "المنتج"),
        c("supplier", "المجهز"),
        c("city", "المحافظة"),
        c("quantity", "الكمية", "number"),
        c("rejected", "الأسطر المرفوضة", "number"),
        c("cost", "تكلفة الوحدة", "money", "data.cost"),
        c("expenses", "مصاريف التوريد", "money", "data.cost"),
        c("total", "التكلفة الإجمالية", "money", "data.cost"),
        c("status", "الحالة"),
      ],
      batches
        .filter((b) => period(b.created))
        .map((b) => ({
          ...b,
          agent: name("agents", b.agent),
          product: name("products", b.product),
          rejected: b.rejected || 0,
          expenses: +b.expenses || 0,
          total: b.cost * b.quantity + (+b.expenses || 0),
        })),
      "حركات التحميل خلال الفترة المحددة؛ الدفعات الملغاة تظهر بحالتها دون اعتبارها شراءً صافيًا.",
    );
    add(
      "inventory-cards",
      "تتبع البطاقات دون الرموز",
      [
        c("internal", "المعرف الداخلي"),
        c("serial", "سيريال المزود"),
        c("batch", "الدفعة"),
        c("agent", "الوكيل"),
        c("product", "المنتج"),
        c("created", "تاريخ التحميل", "date"),
        c("expiry", "الانتهاء"),
        c("status", "الحالة"),
        c("sale", "رقم العملية"),
        c("cost", "التكلفة", "money", "data.cost"),
      ],
      stocks.map((r) => ({
        ...r,
        agent: name("agents", r.agent),
        product: name("products", r.product),
      })),
      "حالة البطاقات الحالية؛ رموز PIN وCVC مستبعدة دائمًا من التقارير.",
    );
    add(
      "inventory-products",
      "دليل المنتجات وحدود البيع",
      [
        c("id", "المعرف"),
        c("name", "المنتج"),
        c("provider", "المزود"),
        c("kind", "النوع"),
        c("face", "القيمة الاسمية", "number"),
        c("currency", "العملة"),
        c("min", "أقل سعر بيع مسموح", "money", "data.cost"),
        c("dailyQty", "حد الكمية اليومي", "number"),
        c("dailyAmount", "الحد المالي اليومي", "money"),
        c("active", "الحالة"),
      ],
      s.products
        .filter(
          (p) =>
            product(p.id) &&
            (!e.catalogProductVisible || e.catalogProductVisible(p.id)),
        )
        .map((p) => ({
          ...p,
          provider: name("providers", p.provider),
          active: p.active ? "مفعل" : "موقوف",
        })),
    );
    add(
      "inventory-providers",
      "دليل المزودين",
      [
        c("id", "المعرف"),
        c("name", "المزود"),
        c("supplier", "المجهز"),
        c("connection", "نوع الربط"),
        c("active", "الحالة"),
      ],
      s.providers
        .filter((p) => !f.provider || p.id === f.provider)
        .map((p) => ({ ...p, active: p.active ? "مفعل" : "موقوف" })),
    );
    add(
      "inventory",
      "المخزون والدفعات",
      [
        c("id", "الدفعة"),
        c("agent", "الوكيل"),
        c("product", "المنتج"),
        c("created", "تاريخ التحميل", "date"),
        c("expiry", "الانتهاء"),
        c("quantity", "المحمّل", "number"),
        c("available", "متاح صالح", "number"),
        c("expired", "منتهي", "number"),
        c("issued", "صادر", "number"),
        c("held", "محجور", "number"),
        c("exported", "مصدّر", "number"),
        c("cancelled", "ملغى", "number"),
        c("other", "حالات أخرى", "number"),
        c("value", "قيمة المخزون المتبقي", "money", "data.cost"),
        c("status", "الحالة"),
      ],
      batches.map((b) => {
        const cards = stocks.filter((x) => x.batch === b.id),
          count = (fn) => cards.filter(fn).length;
        return {
          ...b,
          agent: name("agents", b.agent),
          product: name("products", b.product),
          available: count(
            (x) => x.status === "Available" && x.expiry > root.Masal.day(),
          ),
          expired: count(
            (x) => x.status === "Available" && x.expiry <= root.Masal.day(),
          ),
          issued: count((x) => !!x.sale),
          held: count((x) => x.status === "Quarantined"),
          exported: count((x) => x.status === "Exported"),
          cancelled: count((x) => x.status === "Cancelled by Reversal"),
          other: count(
            (x) =>
              !x.sale &&
              ![
                "Available",
                "Quarantined",
                "Exported",
                "Cancelled by Reversal",
              ].includes(x.status),
          ),
          value: sum(
            cards.filter((x) =>
              [
                "Available",
                "Quarantined",
                "Exported",
                "Awaiting Replacement",
              ].includes(x.status),
            ),
            "cost",
          ),
        };
      }),
      "لقطة المخزون الحالية عند إنشاء التقرير؛ لا تمثل رصيدًا تاريخيًا عند نهاية الفترة.",
    );
    const accounts = [
        ...(e.actor().role === "pos" || f.pos
          ? []
          : s.agents.filter(
              (a) => allowedAgent(a.id) && (!f.city || a.city === f.city),
            )),
        ...points,
      ],
      accountIDs = new Set(accounts.map((a) => a.id));
    const legacy = s.ledger.filter((l) => accountIDs.has(l.account)),
      ledger = (s.serviceLedger || []).filter((l) => accountIDs.has(l.account));
    const services = [
        ...new Set([
          "voucher",
          "topup",
          "cash",
          ...ledger.map((l) => l.service),
        ]),
      ],
      serviceName = (id) => root.MasalAuditFixes?.serviceName(s, id) || id;
    add(
      "wallets",
      "أرصدة المحافظ حسب الخدمة",
      [
        c("id", "الحساب"),
        c("name", "الاسم"),
        c("service", "المحفظة"),
        c("opening", "رصيد افتتاح الفترة", "money"),
        c("credits", "الوارد", "money"),
        c("debits", "الصادر", "money"),
        c("closing", "رصيد إقفال الفترة", "money"),
        c("current", "الرصيد الحالي", "money"),
      ],
      accounts.flatMap((a) =>
        services.map((service) => {
          const all = ledger.filter(
              (l) => l.account === a.id && l.service === service,
            ),
            rows = all.filter((l) => period(l.time)),
            opening = sum(
              all.filter((l) => f.from && day(l.time) < f.from),
              "amount",
            );
          return {
            id: a.id,
            name: a.name,
            service: serviceName(service),
            opening,
            credits: sum(
              rows.filter((l) => l.amount > 0),
              "amount",
            ),
            debits: -sum(
              rows.filter((l) => l.amount < 0),
              "amount",
            ),
            closing: opening + sum(rows, "amount"),
            current: sum(all, "amount"),
          };
        }),
      ),
    );
    add(
      "wallets-ledger",
      "حركات محافظ الخدمات",
      [
        c("id", "القيد"),
        c("group", "مرجع العملية"),
        c("time", "التاريخ والوقت", "date"),
        c("account", "الحساب"),
        c("service", "المحفظة"),
        c("kind", "الحركة"),
        c("amount", "المبلغ", "money"),
      ],
      ledger
        .filter((l) => period(l.time))
        .map((l) => ({
          ...l,
          account: accounts.find((a) => a.id === l.account)?.name || l.account,
          service: serviceName(l.service),
        })),
    );
    add(
      "wallets-legacy",
      "الأرصدة النقدية السابقة",
      [
        c("id", "الحساب"),
        c("name", "الاسم"),
        c("current", "الرصيد السابق", "money"),
      ],
      accounts.map((a) => ({
        id: a.id,
        name: a.name,
        current: sum(
          legacy.filter((l) => l.account === a.id),
          "amount",
        ),
      })),
    );
    add(
      "inventory-invoices",
      "فواتير تحميل ماسال",
      [
        c("id", "الفاتورة"),
        c("time", "التاريخ والوقت", "date"),
        c("agent", "الوكيل"),
        c("batch", "الدفعة"),
        c("quantity", "الكمية", "number"),
        c("amount", "قيمة التحميل", "money"),
        c("cost", "التكلفة", "money", "data.cost"),
        c("profit", "ربح ماسال", "money", "data.profit"),
        c("status", "الحالة"),
      ],
      (s.batchInvoices || [])
        .filter(
          (i) =>
            allowedAgent(i.agent) &&
            period(i.time) &&
            batches.some((b) => b.id === i.batch),
        )
        .map((i) => ({
          ...i,
          agent: name("agents", i.agent),
          amount: i.status === "معكوسة" ? 0 : i.amount,
          profit: i.status === "معكوسة" ? 0 : i.profit,
        })),
    );
    add(
      "network",
      "شبكة الوكلاء",
      [
        c("id", "المعرف"),
        c("name", "الوكيل"),
        c("type", "المستوى"),
        c("parent", "الوكيل الأعلى"),
        c("city", "المحافظة"),
        c("active", "الحالة"),
      ],
      s.agents
        .filter((a) => allowedAgent(a.id) && (!f.city || a.city === f.city))
        .map((a) => ({
          ...a,
          parent: name("agents", a.parent),
          active: a.active ? "مفعل" : "موقوف",
        })),
      "السجلات الحالية ضمن النطاق.",
    );
    add(
      "network-pos",
      "الأجهزة ونقاط البيع",
      [
        c("id", "المعرف"),
        c("name", "نقطة البيع"),
        c("agent", "الوكيل"),
        c("city", "المحافظة"),
        c("model", "الجهاز"),
        c("serial", "سيريال الجهاز"),
        c("version", "الإصدار"),
        c("online", "الاتصال"),
        c("active", "الحالة"),
        c("owner", "اسم صاحب المكتب"),
        c("phone", "رقم الهاتف"),
        c("representative", "المندوب"),
        c("createdAt", "تاريخ إنشاء الحساب", "date"),
        c("notes", "الملاحظات"),
      ],
      points.map((p) => ({
        ...p,
        agent: name("agents", p.agent),
        online: p.online ? "متصل" : "غير متصل",
        active: p.active ? "مفعل" : "موقوف",
      })),
    );
    add(
      "prices",
      "قائمة الأسعار الحالية",
      [
        c("agent", "الوكيل"),
        c("product", "المنتج"),
        c("price", "السعر", "money"),
        c("effective", "تاريخ السريان"),
      ],
      s.prices
        .filter((p) => allowedAgent(p.agent) && product(p.product))
        .map((p) => {
          const main = e.main(p.agent),
            city = s.agents.find((a) => a.id === main)?.city,
            policy = (s.pricePolicies || [])
              .filter(
                (x) =>
                  x.agent === main &&
                  x.product === p.product &&
                  x.effective <= root.Masal.day() &&
                  (x.city === "الكل" || x.city === city),
              )
              .sort(
                (a, b) =>
                  b.effective.localeCompare(a.effective) ||
                  b.time.localeCompare(a.time),
              )[0];
          return {
            ...p,
            agent: name("agents", p.agent),
            product: name("products", p.product),
            price: e.policyPrice(p.agent, p.product),
            effective:
              policy?.effective ||
              s.prices.find((x) => x.agent === main && x.product === p.product)
                ?.effective,
          };
        }),
      "الأسعار الحالية؛ لا تطبق عليها فترة البيع.",
    );
    add(
      "prices-approvals",
      "سجل تغييرات الأسعار",
      [
        c("id", "الطلب"),
        c("time", "التاريخ والوقت", "date"),
        c("creator", "مقدم الطلب"),
        c("approver", "المعتمد"),
        c("status", "الحالة"),
        c("changes", "عدد التغييرات", "number"),
      ],
      s.priceRequests
        .filter(
          (r) =>
            period(r.time) && r.changes.every((c) => allowedAgent(c.agent)),
        )
        .map((r) => ({
          ...r,
          creator: name("users", r.creator),
          approver: name("users", r.approver),
          changes: r.changes.length,
        })),
    );
    add(
      "claims",
      "المطالبات والتسويات",
      [
        c("id", "المطالبة"),
        c("time", "التاريخ والوقت", "date"),
        c("batch", "الدفعة"),
        c("agent", "الوكيل"),
        c("quantity", "الكمية", "number"),
        c("value", "القيمة الأصلية", "money", "data.cost"),
        c("remaining", "القيمة المعلقة", "money", "data.cost"),
        c("soldBefore", "المباع قبل الحجر", "number"),
        c("replacedQuantity", "المستبدل", "number"),
        c("rejectedQuantity", "المرفوض", "number"),
        c("compensation", "التعويض", "money"),
        c("replacement", "الدفعة البديلة"),
        c("reason", "السبب"),
        c("status", "النتيجة"),
        c("settled", "تاريخ التسوية", "date"),
      ],
      s.claims
        .filter(
          (r) =>
            allowedAgent(r.agent) &&
            period(r.time) &&
            (!f.product ||
              s.batches.find((b) => b.id === r.batch)?.product === f.product),
        )
        .map((r) => ({
          ...r,
          remaining: r.status === "معلقة" ? r.value : 0,
          agent: name("agents", r.agent),
        })),
    );
    add(
      "claims-exports",
      "سجل تصدير البطاقات",
      [
        c("id", "المعرف"),
        c("time", "التاريخ والوقت", "date"),
        c("agent", "الوكيل"),
        c("batch", "الدفعة"),
        c("quantity", "الكمية", "number"),
        c("value", "التكلفة", "money", "data.cost"),
        c("reason", "السبب"),
      ],
      s.exports
        .filter((r) => allowedAgent(r.agent) && period(r.time))
        .map((r) => ({ ...r, agent: name("agents", r.agent) })),
      "لا يتضمن التقرير رموز PIN أو كلمات التشفير.",
    );
    add(
      "inventory-copies",
      "نسخ البطاقات المصدرة دون سحب",
      [
        c("id", "السجل"),
        c("time", "التاريخ", "date"),
        c("agent", "الوكيل"),
        c("batch", "الدفعة"),
        c("quantity", "العدد", "number"),
        c("format", "الصيغة"),
        c("user", "المستخدم"),
        c("reason", "السبب"),
      ],
      (s.inventoryCopies || [])
        .filter((r) => allowedAgent(r.agent) && period(r.time))
        .map((r) => ({
          ...r,
          agent: name("agents", r.agent),
          user: name("users", r.user),
        })),
    );
    add(
      "inventory-cancellations",
      "إلغاء واسترجاع بطاقات المخزون",
      [
        c("id", "السجل"),
        c("time", "تاريخ الإلغاء", "date"),
        c("agent", "الوكيل"),
        c("batch", "الدفعة"),
        c("quantity", "العدد", "number"),
        c("credit", "الرصيد التشغيلي", "money", "data.cost"),
        c("status", "الحالة"),
        c("reason", "سبب الإلغاء"),
        c("restoreReason", "سبب الاسترجاع"),
        c("restoredAt", "تاريخ الاسترجاع", "date"),
      ],
      (s.inventoryCancellations || [])
        .filter((r) => allowedAgent(r.agent) && period(r.time))
        .map((r) => ({ ...r, agent: name("agents", r.agent) })),
    );
    add(
      "support",
      "الدعم الفني",
      [
        c("id", "التذكرة"),
        c("time", "التاريخ والوقت", "date"),
        c("agent", "الوكيل"),
        c("title", "العنوان"),
        c("description", "الوصف"),
        c("senderName", "المرسل"),
        c("recipientName", "المستلم"),
        c("attachment", "المرفق"),
        c("priority", "الأولوية"),
        c("status", "الحالة"),
        c("replies", "عدد الردود", "number"),
      ],
      s.tickets
        .filter(
          (r) =>
            (e.supportVisible ? e.supportVisible(r) : allowedAgent(r.agent)) &&
            (!f.agent || allowedAgent(r.agent)) &&
            (!f.pos || r.origin === f.pos || r.recipient === f.pos) &&
            period(r.time),
        )
        .map((r) => ({
          ...r,
          agent: name("agents", r.agent),
          senderName: name("users", r.sender || r.user),
          recipientName: r.broadcastRecipient
            ? name("users", r.broadcastRecipient)
            : e.supportName
              ? e.supportName(r.recipient)
              : r.recipient,
          attachment: r.image ? "صورة مرفقة" : "لا يوجد",
          replies: r.replies.length,
        })),
    );
    add(
      "support-replies",
      "تفاصيل ردود الدعم",
      [
        c("ticket", "التذكرة"),
        c("time", "التاريخ والوقت", "date"),
        c("user", "المستخدم"),
        c("body", "الرد"),
      ],
      s.tickets
        .filter(
          (r) =>
            (!e.supportVisible || e.supportVisible(r)) &&
            (!f.agent || allowedAgent(r.agent)) &&
            (!f.pos || r.origin === f.pos || r.recipient === f.pos),
        )
        .flatMap((t) =>
          t.replies
            .filter(
              (r) =>
                period(r.time) &&
                (!r.audience || r.audience.includes(e.supportIdentity())),
            )
            .map((r) => ({ ...r, ticket: t.id, user: name("users", r.user) })),
        ),
    );
    const users = s.users
      .filter(
        (u) =>
          u.id === e.user ||
          (u.role !== "owner" &&
            root.MasalAccess.scope(s, u).length &&
            root.MasalAccess.scope(s, u).every((a) => allowedAgent(a))) ||
          (e.actor().role === "owner" && !f.agent),
      )
      .filter(
        (u) =>
          (!f.pos || u.pos === f.pos) &&
          (!f.city ||
            (u.role === "pos"
              ? s.pos.find((p) => p.id === u.pos)?.city
              : s.agents.find((a) => a.id === (u.staffAccount || u.agent))
                  ?.city) === f.city),
      );
    add(
      "users",
      "الموظفون والصلاحيات الفعلية",
      [
        c("id", "المعرف"),
        c("name", "الموظف"),
        c("role", "الدور"),
        c("email", "البريد الإلكتروني"),
        c("permissionType", "نوع الصلاحية"),
        c("active", "الحالة"),
        c("scope", "نطاق الوكلاء"),
        c("granted", "الصلاحيات الفعلية", "number"),
        c("allows", "منح مخصص", "number"),
        c("denies", "منع مخصص", "number"),
      ],
      users.map((u) => ({
        ...u,
        permissionType:
          s.permissionProfiles?.find((p) => p.id === u.permissionProfileId)
            ?.name || "—",
        active: u.active ? "مفعل" : "موقوف",
        scope: root.MasalAccess.scope(s, u)
          .map((a) => name("agents", a))
          .join("، "),
        granted: root.MasalAccess.catalog.filter((p) =>
          root.MasalAccess.can(u, p.key, s),
        ).length,
        allows: Object.values(u.access?.overrides || {}).filter(
          (x) => x === "allow",
        ).length,
        denies: Object.values(u.access?.overrides || {}).filter(
          (x) => x === "deny",
        ).length,
      })),
    );
    add(
      "users-overrides",
      "تفاصيل تخصيص صلاحيات الموظفين",
      [
        c("user", "الموظف"),
        c("module", "الوحدة"),
        c("permission", "الصلاحية"),
        c("key", "رمز الصلاحية"),
        c("override", "التخصيص"),
        c("effective", "النتيجة الفعلية"),
      ],
      users.flatMap((u) =>
        Object.entries(u.access?.overrides || {}).map(([key, value]) => {
          const p = root.MasalAccess.catalog.find((p) => p.key === key);
          return {
            user: u.name,
            module: p?.group,
            permission: p?.label,
            key,
            override: value === "allow" ? "سماح" : "منع",
            effective: root.MasalAccess.can(u, key, s)
              ? "مسموح فعليًا"
              : "ممنوع فعليًا",
          };
        }),
      ),
    );
    if (can("permissions.view"))
      add(
        "users-permissions",
        "الصلاحيات الفعلية حسب المستخدم",
        [
          c("user", "المستخدم"),
          c("module", "القسم"),
          c("permission", "الصلاحية"),
          c("effective", "النتيجة"),
        ],
        users.flatMap((u) =>
          root.MasalAccess.catalog
            .filter(
              (p) =>
                root.MasalAccess.can(u, p.key, s) ||
                u.access?.overrides?.[p.key] === "deny",
            )
            .map((p) => ({
              user: u.name,
              module: p.group,
              permission: p.label,
              effective: root.MasalAccess.can(u, p.key, s)
                ? "مسموح"
                : "منع مخصص",
            })),
        ),
      );
    const userIDs = new Set(users.map((u) => u.id));
    add(
      "audit",
      "سجل التدقيق",
      [
        c("id", "المعرف"),
        c("time", "التاريخ والوقت", "date"),
        c("name", "المستخدم"),
        c("action", "الإجراء"),
        c("entity", "السجل"),
        c("source", "المصدر"),
      ],
      s.audit.filter((r) => period(r.time) && userIDs.has(r.user)),
      "يعرض سجل النشاط دون تضمين قيم الحقول الحساسة.",
    );
    const settingLabels = {
      app: "تشغيل النظام",
      sales: "عمليات البيع",
      printing: "الطباعة",
      registration: "إنشاء الحسابات",
      login: "تسجيل الدخول",
      velocity: "حد تكرار العمليات",
      expiryDays: "التنبيه قبل الانتهاء بالأيام",
      providerDaily: "الحد اليومي للمزود",
      minVersion: "أقل إصدار مسموح",
      idle: "الخمول بالدقائق",
    };
    add(
      "operations",
      "إعدادات التشغيل",
      [c("key", "الإعداد"), c("value", "القيمة")],
      Object.entries(s.settings)
        .filter(([k]) => Object.hasOwn(settingLabels, k))
        .map(([key, value]) => ({
          key: settingLabels[key],
          value:
            typeof value === "boolean"
              ? value
                ? "مفعّل"
                : "معطّل"
              : String(value),
        })),
      "إعدادات حالية وليست سجلًا تاريخيًا.",
    );
    add(
      "operations-integrations",
      "إعدادات الربط",
      [
        c("agent", "الوكيل"),
        c("provider", "المزود"),
        c("environment", "البيئة"),
        c("expiry", "الانتهاء"),
      ],
      s.integrations
        .filter((r) => allowedAgent(r.agent))
        .map((r) => ({
          ...r,
          agent: name("agents", r.agent),
          provider: name("providers", r.provider),
        })),
    );
    add(
      "operations-notifications",
      "سجل الإشعارات",
      [
        c("id", "المعرف"),
        c("time", "التاريخ والوقت", "date"),
        c("user", "المرسل"),
        c("title", "العنوان"),
        c("body", "المحتوى"),
        c("target", "المستخدمون"),
        c("readers", "قرأها"),
        c("unread", "غير مقروءة", "number"),
        c("attachment", "المرفق"),
      ],
      s.notifications
        .filter(
          (r) =>
            (!(f.agent || f.pos || f.city) ||
              (r.recipientUsers || []).some(
                (id) => userIDs.has(id) && id !== e.user,
              ) ||
              accountIDs.has(r.target)) &&
            period(r.time) &&
            (Array.isArray(r.recipientUsers)
              ? r.user === e.user ||
                r.recipientUsers.includes(e.user) ||
                e.actor().role === "owner"
              : r.target === "all" || accountIDs.has(r.target)),
        )
        .map((r) => {
          const recipients = (r.recipientUsers || []).filter((id) =>
            userIDs.has(id),
          );
          return {
            ...r,
            user: name("users", r.user),
            target: recipients.length
              ? recipients.map((id) => name("users", id)).join("، ")
              : r.target === "all"
                ? "جميع المستخدمين"
                : name("agents", r.target),
            readers:
              recipients
                .filter((id) => r.readBy?.includes(id))
                .map((id) => name("users", id))
                .join("، ") || "لم تُقرأ",
            unread: recipients.filter((id) => !r.readBy?.includes(id)).length,
            attachment: r.image ? "صورة مرفقة" : "لا يوجد",
          };
        }),
    );
    // Only explicitly selected display fields enter a report; never serialize source records.
    const dateValue = (v) =>
      v != null && Number.isFinite(new Date(v).getTime())
        ? new Date(v).toISOString()
        : null;
    const list = (k) => s[k] || [],
      account = (id) =>
        id === "@owner"
          ? "إدارة النظام"
          : name(s.pos.some((p) => p.id === id) ? "pos" : "agents", id);
    const scoped = (id) => accountIDs.has(id),
      movement = (r) => period(r.time) && (scoped(r.from) || scoped(r.to));
    const movementColumns = [
      c("id", "رقم العملية"),
      c("time", "التاريخ والوقت", "date"),
      c("from", "الممول"),
      c("to", "المستلم"),
      c("service", "المحفظة"),
      c("amount", "المبلغ", "money"),
      c("status", "الحالة"),
      c("user", "المنفّذ"),
    ];
    const movementRow = (r) => ({
      ...r,
      from: account(r.from),
      to: account(r.to),
      service: serviceName(r.service),
      user: name("users", r.user),
    });
    add(
      "inventory-orders",
      "سجل الطلبيات والاعتماد",
      [
        c("id", "الطلبية"),
        c("time", "تاريخ الإرسال", "date"),
        c("agent", "الوكيل"),
        c("product", "الفئة"),
        c("supplier", "المصدر"),
        c("provider", "الشركة"),
        c("city", "المحافظة"),
        c("file", "الملف"),
        c("quantity", "بطاقات صالحة", "number"),
        c("rejected", "بطاقات مرفوضة", "number"),
        c("amount", "قيمة التحميل", "money"),
        c("status", "الحالة"),
        c("user", "مقدم الطلب"),
        c("reviewed", "تاريخ المراجعة", "date"),
        c("reviewer", "المراجع"),
        c("reason", "سبب الرفض أو الإعادة"),
        c("batch", "الدفعة المعتمدة"),
      ],
      list("pendingOrders")
        .filter((r) => allowedAgent(r.agent) && period(r.time))
        .flatMap((r) =>
          r.version === 2
            ? r.lines
                .filter((l) => product(l.product))
                .map((l) => ({
                  id: r.id,
                  time: r.time,
                  agent: account(r.agent),
                  product: name("products", l.product),
                  supplier: r.meta.supplier,
                  provider: name("providers", r.provider),
                  city: r.city,
                  file: l.name,
                  quantity: l.accepted,
                  rejected: l.rejected,
                  amount: l.accepted * l.meta.loadPrice,
                  status: r.status,
                  user: name("users", r.user),
                  reviewed: r.reviewed,
                  reviewer: name("users", r.reviewer),
                  reason: r.reason,
                  batch: l.batch,
                }))
            : product(r.meta?.product)
              ? [
                  {
                    id: r.id,
                    time: r.time,
                    agent: account(r.agent),
                    product: name("products", r.meta?.product),
                    supplier: r.meta?.supplier,
                    quantity: r.quantity,
                    amount: Number.isFinite(+r.meta?.loadPrice)
                      ? r.quantity * Number(r.meta.loadPrice)
                      : null,
                    status: r.status,
                    user: name("users", r.user),
                    reviewed: r.reviewed,
                    reason: r.reason,
                    batch: r.batch,
                  },
                ]
              : [],
        ),
    );
    add(
      "wallets-requests",
      "طلبات التمويل ومتابعتها",
      [
        ...movementColumns,
        c("approvedAmount", "المبلغ المعتمد", "money"),
        c("purpose", "الملاحظات"),
        c("reason", "سبب الرفض"),
        c("reviewedAt", "تاريخ المعالجة", "date"),
        c("approver", "المعالج"),
        c("transfer", "التحويل"),
        c("batch", "الدفعة"),
        c("reference", "المرجع"),
      ],
      list("fundingRequests")
        .filter(movement)
        .map((r) => ({
          ...movementRow(r),
          approver: name("users", r.approverId),
        })),
    );
    add(
      "wallets-transfers",
      "التحويلات المنفذة",
      [
        ...movementColumns,
        c("batchId", "مجموعة التمويل"),
        c("reference", "المرجع"),
      ],
      list("fundingTransfers")
        .filter(movement)
        .map((r) => ({ ...movementRow(r), status: r.status || "منفذ" })),
    );
    add(
      "wallets-holds",
      "حجوزات التمويل",
      [...movementColumns, c("request", "طلب التمويل")],
      list("fundingHolds").filter(movement).map(movementRow),
    );
    add(
      "wallets-groups",
      "تفاصيل التمويل المتعدد",
      [
        c("id", "المجموعة"),
        ...movementColumns.filter((x) => x.key !== "id"),
        c("transfer", "التحويل"),
        c("reference", "المرجع"),
      ],
      list("fundingBatches")
        .filter((r) => period(r.time))
        .flatMap((b) =>
          (b.results || [])
            .filter((r) => scoped(r.from) || scoped(r.to))
            .map((r) => ({
              ...movementRow({ ...r, time: b.time, user: b.user }),
              id: b.id,
            })),
        ),
    );
    add(
      "prices-changes",
      "الأسعار قبل وبعد التعديل",
      [
        c("id", "رقم التعديل"),
        c("time", "التاريخ والوقت", "date"),
        c("agent", "الوكيل"),
        c("product", "الفئة"),
        c("old", "السعر السابق", "money"),
        c("price", "السعر الجديد", "money"),
        c("difference", "فرق السعر", "money"),
        c("creator", "المنفّذ"),
        c("approver", "المعتمد"),
        c("status", "الحالة"),
      ],
      list("priceRequests")
        .filter((r) => period(r.time))
        .flatMap((r) =>
          (r.changes || [])
            .filter((x) => allowedAgent(x.agent) && product(x.product))
            .map((x) => ({
              ...x,
              id: r.id,
              time: r.time,
              agent: account(x.agent),
              product: name("products", x.product),
              difference: x.old == null ? null : x.price - x.old,
              creator: name("users", r.creator),
              approver: name("users", r.approver),
              status: r.status,
            })),
        ),
    );
    add(
      "network-categories",
      "الفئات المخصصة للوكلاء",
      [
        c("id", "المعرف"),
        c("name", "الوكيل"),
        c("products", "الفئات المسموحة"),
        c("count", "عدد الفئات", "number"),
      ],
      s.agents
        .filter((a) => a.type === "رئيسي" && allowedAgent(a.id))
        .map((a) => {
          const products = s.products.filter(
            (p) =>
              product(p.id) &&
              (!e.agentProductAllowed || e.agentProductAllowed(a.id, p.id)),
          );
          return {
            id: a.id,
            name: a.name,
            products: products.map((p) => p.name).join("، ") || "لا توجد فئات",
            count: products.length,
          };
        }),
    );
    if (can("agents.archiveView"))
      add(
        "network-archive",
        "الحسابات المؤرشفة",
        [
          c("id", "رقم الأرشفة"),
          c("entity", "معرف الحساب"),
          c("name", "الحساب"),
          c("time", "تاريخ الأرشفة", "date"),
          c("user", "المنفّذ"),
          c("reason", "السبب"),
        ],
        list("deletedAccounts")
          .filter(
            (r) =>
              period(r.time) &&
              allowedAgent(r.kind === "pos" ? r.before?.agent : r.entity),
          )
          .map((r) => ({ ...r, user: name("users", r.user) })),
      );
    if (can("security.policies")) {
      const policyRows = [
        {
          id: "general",
          name: "الضوابط العامة",
          active: true,
          targets: [],
          policy: e.printPolicy
            ? e.printPolicy("")
            : s.settings.printPolicy || {},
        },
        ...list("printPolicyRules").filter((r) =>
          r.targets.every((t) =>
            t.kind === "pos" ? scoped(t.id) : allowedAgent(t.id),
          ),
        ),
      ];
      add(
        "operations-printing",
        "ضوابط الطباعة العامة والمخصصة",
        [
          c("id", "المعرف"),
          c("name", "القاعدة"),
          c("targets", "النطاق"),
          c("status", "الحالة"),
          c("failedRetries", "إعادة الطباعة بعد الفشل", "number"),
          c("maxCards", "بطاقات الطلب الواحد", "number"),
          c("intervalSeconds", "الفاصل بالثواني", "number"),
          c("dailyCards", "الحد اليومي"),
          c("dailyMode", "احتساب الحد"),
          c("products", "الفئات"),
        ],
        policyRows.map((r) => ({
          ...r,
          ...r.policy,
          status: r.active ? "مفعّل" : "معطّل",
          targets:
            r.targets
              .map((t) => account(t.id) + (t.kind === "tree" ? " وتابعوه" : ""))
              .join("، ") || "عام",
          dailyCards: r.policy.dailyCards || "بلا حد",
          dailyMode:
            r.policy.dailyMode === "network" ? "مجموع الشبكة" : "لكل حساب",
          products:
            r.policy.dailyProductMode === "selected"
              ? (r.policy.dailyProducts || [])
                  .map((id) => name("products", id))
                  .join("، ")
              : "كل الفئات — مجموع مشترك",
        })),
      );
      add(
        "users-times",
        "أوقات صلاحية حسابات النظام",
        [
          c("id", "المعرف"),
          c("name", "المستخدم"),
          c("status", "الحالة"),
          c("startAt", "بداية الصلاحية"),
          c("endAt", "نهاية الصلاحية"),
          c("startTime", "بداية الدوام — بغداد"),
          c("endTime", "نهاية الدوام — بغداد"),
          c("idleMinutes", "الخروج عند الخمول بالدقائق"),
          c("sessionMinutes", "مدة الجلسة بالدقائق"),
        ],
        users
          .filter(
            (u) => u.timePolicy && !["main", "sub", "pos"].includes(u.role),
          )
          .map((u) => {
            const p = u.timePolicy;
            return {
              id: u.id,
              name: u.name,
              status: p.enabled ? "مفعّل" : "معطّل",
              startAt: p.dateEnabled ? p.startAt : "غير مفعّل",
              endAt: p.dateEnabled ? p.endAt : "غير مفعّل",
              startTime: p.hoursEnabled ? p.startTime : "غير مفعّل",
              endTime: p.hoursEnabled ? p.endTime : "غير مفعّل",
              idleMinutes: p.idleEnabled ? p.idleMinutes : "غير مفعّل",
              sessionMinutes: p.sessionEnabled ? p.sessionMinutes : "غير مفعّل",
            };
          }),
      );
    }
    if (can("audit.details"))
      add(
        "operations-security",
        "سجل الأحداث الأمنية",
        [
          c("id", "المعرف"),
          c("time", "التاريخ والوقت", "date"),
          c("user", "المستخدم"),
          c("action", "الحدث"),
        ],
        list("securityEvents")
          .filter((r) => userIDs.has(r.user) && period(r.time))
          .map((r) => ({ ...r, user: name("users", r.user) })),
      );
    add(
      "wallets-collections",
      "سجل التحصيل",
      [
        c("id", "العملية"),
        c("time", "التاريخ والوقت", "date"),
        c("account", "الحساب"),
        c("amount", "المبلغ", "money"),
        c("method", "طريقة التحصيل"),
        c("reference", "المرجع"),
        c("user", "المنفّذ"),
      ],
      list("collections")
        .filter((r) => scoped(r.account) && period(r.time))
        .map((r) => ({
          ...r,
          account: account(r.account),
          user: name("users", r.user),
        })),
    );
    add(
      "inventory-reservations",
      "حجوزات البطاقات",
      [
        c("id", "الحجز"),
        c("time", "التاريخ والوقت", "date"),
        c("pos", "الحساب"),
        c("product", "الفئة"),
        c("quantity", "الكمية", "number"),
        c("credit", "قيمة الحجز", "money"),
        c("status", "الحالة"),
      ],
      list("reservations")
        .filter(
          (r) => pointIDs.has(r.pos) && period(r.time) && product(r.product),
        )
        .map((r) => ({
          ...r,
          pos: account(r.pos),
          product: name("products", r.product),
        })),
    );
    add(
      "claims-requests",
      "طلبات سحب وتصدير البطاقات",
      [
        c("id", "الطلب"),
        c("time", "تاريخ الطلب", "date"),
        c("agent", "الوكيل"),
        c("batch", "الدفعة"),
        c("status", "الحالة"),
        c("reason", "السبب"),
        c("user", "مقدم الطلب"),
        c("approver", "المعتمد"),
        c("until", "انتهاء إذن التنزيل", "date"),
      ],
      list("exportRequests")
        .filter((r) => allowedAgent(r.agent) && period(r.time))
        .map((r) => ({
          ...r,
          agent: account(r.agent),
          user: name("users", r.user),
          approver: name("users", r.approver),
        })),
    );
    if (can("integrations.view"))
      add(
        "sales-services",
        "طلبات خدمات المزودين",
        [
          c("id", "الطلب"),
          c("time", "التاريخ والوقت", "date"),
          c("agent", "الوكيل"),
          c("provider", "المزود"),
          c("service", "المحفظة"),
          c("recipient", "المستفيد"),
          c("price", "سعر البيع", "money"),
          c("cost", "التكلفة", "money", "data.cost"),
          c("profit", "الربح", "money", "data.profit"),
          c("status", "الحالة"),
          c("attempts", "المحاولات", "number"),
        ],
        list("serviceOrders")
          .filter(
            (r) =>
              allowedAgent(r.agent) &&
              period(r.time) &&
              (!f.provider || r.provider === f.provider),
          )
          .map((r) => ({
            ...r,
            agent: account(r.agent),
            provider: name("providers", r.provider),
            service: serviceName(r.service),
          })),
      );
    if (can("map.view"))
      add(
        "network-presence",
        "آخر اتصال وموقع مسجل",
        [
          c("id", "المعرف"),
          c("name", "المستخدم"),
          c("status", "الاتصال"),
          c("lastSeen", "آخر ظهور", "date"),
          c("locationTime", "وقت آخر موقع", "date"),
          c("lat", "خط العرض"),
          c("lng", "خط الطول"),
          c("accuracy", "الدقة بالمتر"),
        ],
        users
          .filter((u) => u.role !== "owner" && userIDs.has(u.id))
          .map((u) => {
            const p = u.mapPresence || {},
              l = p.location || {},
              elapsed = Date.now() - Date.parse(p.lastSeen);
            return {
              id: u.id,
              name: u.name,
              status:
                u.active && p.connected && elapsed >= 0 && elapsed < 120000
                  ? "متصل"
                  : "غير متصل",
              lastSeen: p.lastSeen,
              locationTime: l.time,
              lat: l.lat,
              lng: l.lng,
              accuracy: l.accuracy,
            };
          }),
        "آخر بيانات مسجلة على هذا الجهاز، وليست سجلًا تاريخيًا لتحركات المستخدم.",
      );
    if (can("audit.details")) {
      add(
        "operations-sessions",
        "جلسات الدخول المحلية",
        [
          c("user", "المستخدم"),
          c("created", "وقت الدخول", "date"),
          c("lastActivity", "آخر نشاط", "date"),
          c("status", "الحالة"),
        ],
        list("localSessions")
          .filter((r) => userIDs.has(r.user) && period(dateValue(r.created)))
          .map((r) => ({
            user: name("users", r.user),
            created: dateValue(r.created),
            lastActivity: dateValue(r.lastActivity),
            status: r.active ? "نشطة" : "منتهية",
          })),
      );
      const labels = {
        name: "الاسم",
        owner: "صاحب المكتب",
        phone: "الهاتف",
        email: "البريد الإلكتروني",
        notes: "الملاحظات",
        active: "حالة الحساب",
        status: "الحالة",
        reason: "السبب",
        title: "العنوان",
        quantity: "الكمية",
        price: "السعر",
        old: "السعر السابق",
        amount: "المبلغ",
        dailyCards: "الحد اليومي",
        failedRetries: "محاولات الفشل",
        maxCards: "بطاقات الطلب",
        intervalSeconds: "الفاصل بالثواني",
        startAt: "بداية الصلاحية",
        endAt: "نهاية الصلاحية",
        startTime: "بداية الدوام",
        endTime: "نهاية الدوام",
        idleMinutes: "مهلة الخمول",
        sessionMinutes: "مدة الجلسة",
      };
      const scalar = (v) =>
        v == null
          ? "غير مسجل"
          : typeof v === "boolean"
            ? v
              ? "مفعّل"
              : "معطّل"
            : String(v);
      add(
        "audit-changes",
        "تفاصيل التغييرات قبل وبعد",
        [
          c("id", "السجل"),
          c("time", "التاريخ والوقت", "date"),
          c("user", "المنفّذ"),
          c("action", "الإجراء"),
          c("entity", "المرجع"),
          c("field", "الحقل"),
          c("before", "قبل التعديل"),
          c("after", "بعد التعديل"),
        ],
        s.audit
          .filter((r) => period(r.time) && userIDs.has(r.user))
          .flatMap((r) =>
            Object.entries(labels)
              .filter(([k]) => {
                const a = r.before?.[k],
                  b = r.after?.[k];
                return (
                  (a !== undefined || b !== undefined) &&
                  a !== b &&
                  [a, b].every(
                    (v) =>
                      v == null ||
                      ["string", "boolean", "number"].includes(typeof v),
                  )
                );
              })
              .map(([k, field]) => ({
                id: r.id,
                time: r.time,
                user: name("users", r.user),
                action: r.action,
                entity: r.entity,
                field,
                before: scalar(r.before?.[k]),
                after: scalar(r.after?.[k]),
              })),
          ),
      );
    }
    add(
      "sales-deliveries",
      "سجل تسليم البطاقات",
      [
        c("id", "التسليم"),
        c("tx", "عملية البيع"),
        c("time", "التاريخ والوقت", "date"),
        c("channel", "طريقة التسليم"),
        c("reference", "مرجع الاستلام"),
        c("user", "المنفّذ"),
      ],
      list("deliveryRecords")
        .filter((r) => period(r.time) && sales.some((t) => t.id === r.tx))
        .map((r) => ({ ...r, user: name("users", r.user) })),
    );
    add(
      "prices-policies",
      "قواعد الأسعار المحفوظة",
      [
        c("id", "المعرف"),
        c("agent", "الوكيل"),
        c("product", "الفئة"),
        c("city", "المحافظة"),
        c("finalPrice", "السعر النهائي", "money"),
        c("effective", "تاريخ النفاذ"),
        c("time", "تاريخ التسجيل", "date"),
      ],
      list("pricePolicies")
        .filter(
          (r) =>
            allowedAgent(r.agent) &&
            product(r.product) &&
            (!f.city || r.city === "الكل" || r.city === f.city),
        )
        .map((r) => ({
          ...r,
          agent: account(r.agent),
          product: name("products", r.product),
        })),
    );
    if (can("support.view"))
      add(
        "support-contacts",
        "أرقام الدعم المسجلة",
        [
          c("account", "الجهة"),
          c("label", "اسم الرقم"),
          c("phone", "رقم الهاتف"),
        ],
        [
          ...(e.actor().role === "owner" && !f.agent && !f.pos
            ? [{ id: "@owner", supportPhones: s.settings.supportPhones }]
            : []),
          ...s.agents.filter((a) => scoped(a.id)),
        ].flatMap((a) =>
          (a.supportPhones || []).map((p) => ({
            account: account(a.id),
            label: p.label,
            phone: p.number,
          })),
        ),
      );
    const total = sum(sales, "total"),
      cost = sales.some((t) => t.cost === null) ? null : sum(sales, "cost");
    return {
      sections,
      generated: new Date().toISOString(),
      sales: can("reports.sales")
        ? {
            count: sales.length,
            quantity: sum(sales, "quantity"),
            total,
            cost: can("data.cost") ? cost : null,
            profit: can("data.profit") && cost !== null ? total - cost : null,
            margin:
              can("data.profit") && cost !== null && total
                ? (100 * (total - cost)) / total
                : null,
          }
        : null,
    };
  }
  root.MasalReports = { build };
})(globalThis);

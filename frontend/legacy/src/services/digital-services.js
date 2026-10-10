(function (root) {
  "use strict";
  const providers = { rabiaa: "الرابعة", topup: "Topup" };
  const statuses = {
    pending: "قيد التنفيذ",
    review: "بانتظار التحقق",
    succeeded: "ناجحة",
    failed: "فاشلة",
    refunded: "مرتجعة",
  };
  const now = () => new Date().toISOString();
  const copy = (value) => Masal.clone(value);
  function initialize(s) {
    s.digitalConnections ??= [];
    s.digitalOrders ??= [];
    for (const c of s.digitalConnections)
      if (!Array.isArray(c.grants))
        c.grants = (c.posIds || []).map((id) => ({
          kind: "pos",
          target: id,
          from: c.agent,
          offerIds: c.offers.map((o) => o.id),
        }));
  }
  function amount(value) {
    const n = Number(value);
    if (!Number.isFinite(n) || n <= 0 || n > 100000000)
      throw Error("أدخل مبلغًا موجبًا صحيحًا");
    return Math.round(n * 100) / 100;
  }
  function phone(value, plus = false) {
    const digits = String(value || "")
      .replace(/[٠-٩]/g, (c) => String("٠١٢٣٤٥٦٧٨٩".indexOf(c)))
      .replace(/[۰-۹]/g, (c) => String("۰۱۲۳۴۵۶۷۸۹".indexOf(c)))
      .replace(/[\s()-]/g, "");
    const normalized = digits
      .replace(/^\+/, "")
      .replace(/^00/, "")
      .replace(/^0(?=7)/, "964");
    if (!/^9647\d{9}$/.test(normalized))
      throw Error("أدخل رقم عراقي صحيحًا مثل 07701234567");
    return (plus ? "+" : "") + normalized;
  }
  const P = Masal.Engine.prototype;
  P.digitalPOSAllowed = function (posId) {
    const p = this.s.pos.find((p) => p.id === posId),
      u = this.actor();
    if (
      !p ||
      p.active === false ||
      p.archivedAt ||
      !this.allowed(p.agent) ||
      (u.role === "pos" && u.pos !== posId)
    )
      return false;
    let a = this.s.agents.find((a) => a.id === p.agent);
    const seen = new Set();
    while (a) {
      if (a.active === false || a.archivedAt || seen.has(a.id)) return false;
      seen.add(a.id);
      a = this.s.agents.find((parent) => parent.id === a.parent);
    }
    return true;
  };
  P.digitalVisibleOrders = function () {
    initialize(this.s);
    if (!this.can("digital.view")) return [];
    const u = this.actor();
    const rows = this.s.digitalOrders.filter(
      (r) => this.allowed(r.agent) && (u.role !== "pos" || r.pos === u.pos),
    );
    // Preserve earlier Topup service orders without assigning an unknown POS.
    const legacy = (this.s.serviceOrders || [])
      .filter(
        (r) =>
          r.service === "topup" && this.allowed(r.agent) && u.role !== "pos",
      )
      .map((r) => ({
        ...r,
        legacy: true,
        provider: "topup",
        mainAgent: this.main(r.agent),
        pos: "",
        employee: "غير مسجل في العملية السابقة",
        category: r.sku || "Topup سابق",
        product: "",
        retail: r.price,
        mobile: r.recipient,
        mode: "legacy",
        status:
          {
            ناجح: "succeeded",
            فاشل: "failed",
            "غير معروف": "review",
            "قيد المعالجة": "pending",
          }[r.status] || "review",
        companyTransactionId: "",
        message: "عملية من سجل Topup السابق؛ بدون نقطة بيع محددة",
      }));
    return [...rows, ...legacy].sort((a, b) => b.time.localeCompare(a.time));
  };
  P.digitalAgentOffers = function (c, agentId, includePaused = false) {
    initialize(this.s);
    if (!c.active && !includePaused) return [];
    let agent = this.s.agents.find((a) => a.id === agentId),
      ids = c.offers
        .filter(
          (o) =>
            o.active &&
            o.remoteId &&
            this.s.products.some(
              (p) => p.id === o.productId && p.active !== false,
            ) &&
            this.agentProductAllowed(c.agent, o.productId),
        )
        .map((o) => o.id);
    const seen = new Set();
    while (agent && agent.id !== c.agent) {
      if (!agent.active || agent.archivedAt || seen.has(agent.id)) return [];
      seen.add(agent.id);
      const grant = c.grants.find(
        (g) =>
          g.kind === "agent" &&
          g.target === agent.id &&
          g.from === agent.parent,
      );
      if (!grant || (!includePaused && grant.active === false)) return [];
      ids = ids.filter((id) => grant.offerIds.includes(id));
      agent = this.s.agents.find((a) => a.id === agent.parent);
    }
    if (!agent || !agent.active || agent.archivedAt) return [];
    return c.offers.filter(
      (o) =>
        ids.includes(o.id) && this.agentProductAllowed(agentId, o.productId),
    );
  };
  P.digitalPointOffers = function (c, posId, includePaused = false) {
    if (!this.digitalPOSAllowed(posId)) return [];
    const pos = this.s.pos.find((p) => p.id === posId),
      grant = (c.grants || []).find(
        (g) => g.kind === "pos" && g.target === posId && g.from === pos.agent,
      );
    return grant && (includePaused || grant.active !== false)
      ? this.digitalAgentOffers(c, pos.agent, includePaused).filter(
          (o) =>
            grant.offerIds.includes(o.id) &&
            this.posProductAllowed(posId, o.productId),
        )
      : [];
  };
  P.digitalVisibleConnections = function () {
    initialize(this.s);
    if (!this.can("digital.view") && !this.can("integrations.view")) return [];
    const u = this.actor();
    return this.s.digitalConnections.filter(
      (c) =>
        u.role === "owner" ||
        (u.role === "pos"
          ? this.digitalPointOffers(c, u.pos).length
          : c.agent === u.agent ||
            this.digitalAgentOffers(c, u.agent, true).length),
    );
  };
  P.allocateDigitalOffers = function (connectionId, kind, target, offerIds) {
    this.requirePermission("digital.view");
    this.require(null, ["main", "sub"]);
    initialize(this.s);
    const actor = this.actor(),
      c = this.s.digitalConnections.find((c) => c.id === connectionId);
    if (
      !c ||
      !this.allowed(actor.agent) ||
      !["agent", "pos"].includes(kind) ||
      !Array.isArray(offerIds)
    )
      throw Error("طلب توزيع غير صحيح");
    const record =
      kind === "agent"
        ? this.s.agents.find((a) => a.id === target && a.parent === actor.agent)
        : this.s.pos.find((p) => p.id === target && p.agent === actor.agent);
    if (!record || !record.active || record.archivedAt)
      throw Error("اختر تابعًا مفعّلًا ضمن نطاقك");
    const available = this.digitalAgentOffers(c, actor.agent),
      ids = [...new Set(offerIds)];
    if (ids.some((id) => !available.some((o) => o.id === id)))
      throw Error("لا يمكنك منح فئة غير مسموحة لك");
    const old = c.grants.find((g) => g.kind === kind && g.target === target),
      grant = {
        kind,
        target,
        from: actor.agent,
        offerIds: ids,
        active: old?.active !== false,
      };
    const before = old ? copy(old) : null;
    if (old) Object.assign(old, grant);
    else c.grants.push(grant);
    c.updatedAt = now();
    this.log("توزيع فئات الخدمة", c.id, before, grant);
    return c;
  };
  P.setDigitalGrantActive = function (connectionId, kind, target, active) {
    this.requirePermission("digital.view");
    this.require(null, ["main", "sub"]);
    initialize(this.s);
    const actor = this.actor(),
      c = this.s.digitalConnections.find((c) => c.id === connectionId);
    const record =
      kind === "agent"
        ? this.s.agents.find((a) => a.id === target && a.parent === actor.agent)
        : kind === "pos"
          ? this.s.pos.find((p) => p.id === target && p.agent === actor.agent)
          : null;
    const grant = c?.grants.find(
      (g) => g.kind === kind && g.target === target && g.from === actor.agent,
    );
    if (
      typeof active !== "boolean" ||
      !record ||
      !grant ||
      !this.allowed(actor.agent) ||
      !this.descendants(c.agent).includes(actor.agent)
    )
      throw Error("التوزيع خارج نطاقك");
    if (active && (record.active === false || record.archivedAt))
      throw Error("التابع موقوف؛ فعّله من إدارته أولًا");
    const before = copy(grant);
    grant.active = active;
    c.updatedAt = now();
    this.log(
      active ? "تفعيل توزيع الفئات" : "تعطيل توزيع الفئات",
      c.id,
      before,
      copy(grant),
    );
    return grant;
  };
  const stockSell = P.sell;
  P.sell = function (posId, product, quantity, key, ...args) {
    initialize(this.s);
    if (
      !this.s.sales.some((r) => r.key === key) &&
      this.s.digitalConnections.some((c) =>
        this.digitalPointOffers(c, posId, true).some(
          (o) => o.productId === product,
        ),
      )
    )
      throw Error("هذه الفئة تباع من خدمات API");
    return stockSell.call(this, posId, product, quantity, key, ...args);
  };
  P.saveDigitalConnection = function (draft) {
    initialize(this.s);
    this.requirePermission("integrations.edit");
    this.require(null, ["owner"]);
    if (!providers[draft.provider]) throw Error("اختر الخدمة");
    const agent = this.s.agents.find(
      (a) =>
        a.id === draft.agent &&
        a.active !== false &&
        !a.archivedAt &&
        a.type === "رئيسي",
    );
    if (!agent) throw Error("اختر وكيلاً رئيسيًا مفعّلًا");

    if (
      /(?:nr_|BASIC_AUTH|Bearer\s|x-api-key)/i.test(
        String(draft.credentialLabel || ""),
      )
    )
      throw Error("أدخل اسمًا تعريفيًا فقط؛ التوكن الحقيقي يُحفظ على السيرفر");
    const old = this.s.digitalConnections.find((c) => c.id === draft.id);
    if (
      this.s.digitalConnections.some(
        (c) =>
          c.id !== old?.id &&
          c.agent === agent.id &&
          c.provider === draft.provider,
      )
    )
      throw Error("يوجد ربط لهذه الخدمة مع الوكيل؛ عدّل الربط الحالي");
    const posIds = old && old.agent === agent.id ? [...(old.posIds || [])] : [];
    const offers = (draft.offers || []).map((o) => {
      const product = this.s.products.find(
        (p) => p.id === o.productId && p.active !== false,
      );
      if (!product || !this.agentProductAllowed(agent.id, product.id))
        throw Error("اختر فئات مفعّلة ومسموحة للوكيل");
      const remoteId = String(o.remoteId || "").trim();
      if (remoteId.length > 100) throw Error("فئة الشركة غير صحيحة");
      const packageType =
        draft.provider === "rabiaa" && o.packageType === "premium"
          ? "premium"
          : "standard";
      const provinceId = String(o.provinceId || "").trim();
      if (provinceId && !/^\d+$/.test(provinceId))
        throw Error("المحافظة غير صحيحة");
      if (remoteId && draft.provider === "rabiaa" && !/^\d+$/.test(remoteId))
        throw Error("معرف فئة الرابعة يجب أن يكون catalogId رقميًا");
      const beinProvinceId =
        packageType === "premium" ? String(o.beinProvinceId || "").trim() : "";
      if (beinProvinceId && !/^\d+$/.test(beinProvinceId))
        throw Error("محافظة المشترك غير صحيحة");
      return {
        id: o.id || Masal.id("OFFER"),
        productId: product.id,
        name: product.name,
        remoteName: String(o.remoteName || "").slice(0, 200),
        province: String(o.province || "").slice(0, 100),
        remoteId,
        provinceId,
        beinProvinceId,
        packageType,
        type:
          draft.provider === "topup"
            ? ["topup", "bundle", "bill"].includes(o.type)
              ? o.type
              : "topup"
            : "voucher",
        cost: remoteId ? amount(o.cost) : 0,
        retail: amount(o.retail),
        active: o.active !== false,
      };
    });
    if (!offers.length) throw Error("أضف فئة واحدة على الأقل");
    if (
      new Set(offers.map((o) => o.productId)).size !== offers.length ||
      new Set(
        offers
          .filter((o) => o.remoteId)
          .map((o) => o.remoteId + ":" + o.provinceId),
      ).size !== offers.filter((o) => o.remoteId).length
    )
      throw Error("لا تكرر الفئة أو معرف الشركة في الربط");
    const providerId =
      draft.provider === "rabiaa"
        ? this.s.providers.find((p) => p.name.includes("الرابعة"))?.id || ""
        : "";
    const record = {
      id: old?.id || draft.id || Masal.id("CONNECTION"),
      provider: draft.provider,
      providerId,
      serviceKey: draft.provider === "topup" ? "topup" : "api:" + providerId,
      agent: agent.id,
      beinProvinces: (draft.beinProvinces || [])
        .filter((p) => /^\d+$/.test(String(p.id)) && p.name)
        .map((p) => ({ id: String(p.id), name: String(p.name).slice(0, 100) })),
      mode: "server",
      active: draft.active !== false,
      credentialLabel: String(draft.credentialLabel || "")
        .trim()
        .slice(0, 100),
      companyBalance:
        old &&
        old.agent === agent.id &&
        old.provider === draft.provider &&
        Number.isFinite(old.companyBalance)
          ? old.companyBalance
          : null,
      balanceUpdatedAt:
        old && old.agent === agent.id && old.provider === draft.provider
          ? old.balanceUpdatedAt || ""
          : "",
      posIds,
      grants: old && old.agent === agent.id ? copy(old.grants || []) : [],
      offers,
      updatedAt: now(),
    };
    // Whitelist metadata only: a pasted API key must never enter state or audit.
    const before = old ? copy(old) : null;
    if (old) Object.assign(old, record);
    else this.s.digitalConnections.push(record);
    this.log("إعداد ربط خدمة إلكترونية", record.id, before, record);
    return record;
  };
  P.beginDigitalOrder = function (draft, requestId) {
    initialize(this.s);
    this.requirePermission("digital.create");
    this.require(null, ["pos"]);
    if (!this.s.settings.sales) throw Error("البيع موقوف من إدارة النظام");
    if (!requestId || String(requestId).length > 100)
      throw Error("مرجع العملية غير صحيح");
    const old = this.s.digitalOrders.find((r) => r.requestId === requestId);
    if (old) {
      this.require(old.agent);
      if (
        old.user !== this.user ||
        old.pos !== draft.pos ||
        old.connection !== draft.connection ||
        old.offer !== draft.offer ||
        (old.mobile && phone(draft.mobile) !== old.mobile)
      )
        throw Error("مرجع العملية مستخدم لطلب آخر");
      return old;
    }
    if (!this.digitalPOSAllowed(draft.pos))
      throw Error("اختر نقطة بيع مفعّلة ضمن نطاقك");
    const pos = this.s.pos.find((p) => p.id === draft.pos);
    this.checkOperation(pos.id, "sales");
    const c = this.s.digitalConnections.find(
      (c) => c.id === draft.connection && c.active,
    );
    if (!c || !this.descendants(c.agent).includes(pos.agent))
      throw Error("الخدمة غير مفعّلة لهذه النقطة");
    const agent = this.s.agents.find((a) => a.id === c.agent);
    if (!agent?.active || agent.archivedAt)
      throw Error("الوكيل صاحب الربط موقوف");
    const offer = this.digitalPointOffers(c, pos.id).find(
      (o) => o.id === draft.offer,
    );
    if (
      !offer ||
      !this.s.products.some(
        (p) => p.id === offer.productId && p.active !== false,
      ) ||
      !this.agentProductAllowed(c.agent, offer.productId)
    )
      throw Error("الفئة غير متاحة");
    const mobile =
      c.provider === "topup" || offer.packageType === "premium"
        ? phone(draft.mobile)
        : "";
    let subscriber = null;
    if (offer.packageType === "premium") {
      const firstName = String(draft.firstName || "").trim(),
        lastName = String(draft.lastName || "").trim();
      const beinProvinceId = String(
        draft.beinProvinceId || offer.beinProvinceId || "",
      );
      if (
        !/^\d+$/.test(beinProvinceId) ||
        (draft.beinProvinceId &&
          !(c.beinProvinces || []).some((p) => String(p.id) === beinProvinceId))
      )
        throw Error("اختر محافظة المشترك");
      if (
        !firstName ||
        !lastName ||
        firstName.length > 100 ||
        lastName.length > 100
      )
        throw Error("أدخل اسم المشترك واسم العائلة");
      subscriber = { firstName, lastName, phone: "+" + mobile, beinProvinceId };
    }
    const r = {
      id: Masal.id("DIGITAL"),
      requestId,
      connection: c.id,
      offer: offer.id,
      provider: c.provider,
      mode: c.mode,
      agent: pos.agent,
      mainAgent: c.agent,
      pos: pos.id,
      user: this.user,
      employee: this.actor().name,
      product: offer.productId,
      category: offer.name,
      remoteId: offer.remoteId,
      provinceId: offer.provinceId,
      type: offer.type,
      packageType: offer.packageType,
      mobile,
      subscriber,
      cost: offer.cost,
      retail: offer.retail,
      status: "pending",
      time: now(),
      companyTransactionId: "",
      receiptRef: "",
      message: "",
    };
    this.s.digitalOrders.unshift(r);
    this.log("طلب خدمة إلكترونية", r.id, null, {
      provider: r.provider,
      pos: r.pos,
      category: r.category,
      requestId,
    });
    return r;
  };
  P.resolveDigitalOrder = function (id, result) {
    this.requirePermission("digital.create");
    this.require(null, ["pos"]);
    const r = this.s.digitalOrders.find((r) => r.id === id);
    if (!r || !this.digitalPOSAllowed(r.pos))
      throw Error("العملية خارج النطاق");
    if (!["pending", "review"].includes(r.status)) return r;
    if (!["succeeded", "failed", "review"].includes(result?.status))
      throw Error("لم يؤكد الخادم نتيجة العملية");
    const updates = {
      status: result.status,
      message: String(result.message || "").slice(0, 500),
      updatedAt: now(),
    };
    if (result.status === "succeeded") {
      if (!result.transactionId)
        throw Error("لم يرجع مرجع عملية الشركة؛ يلزم التحقق");
      updates.companyTransactionId = String(result.transactionId);
      if (
        this.s.digitalOrders.some(
          (o) =>
            o.id !== r.id &&
            o.connection === r.connection &&
            o.companyTransactionId === updates.companyTransactionId,
        )
      )
        throw Error("عملية الشركة مسجلة سابقًا");
      updates.cost = amount(result.cost);
      updates.retail = amount(result.retail);
      if (
        r.provider === "rabiaa" &&
        r.packageType === "premium" &&
        result.beinStatus !== "success"
      )
        throw Error("تفعيل BeIN غير مؤكد؛ يلزم التحقق");
      if (r.mode === "server") {
        if (!result.receiptRef)
          throw Error("مرجع الإيصال غير موجود؛ يلزم التحقق");
        updates.receiptRef = String(result.receiptRef);
        // Production codes stay on the server, never in browser persistence.
      }
    }
    if (
      result.status === "succeeded" &&
      r.provider === "topup" &&
      result.remaining_balance != null
    ) {
      const balance = Number(result.remaining_balance);
      if (!Number.isFinite(balance) || balance < 0)
        throw Error("رصيد الشركة غير صحيح؛ يلزم التحقق");
      const c = this.s.digitalConnections.find((c) => c.id === r.connection);
      if (c) {
        c.companyBalance = balance;
        c.balanceUpdatedAt = now();
      }
    }
    const before = { status: r.status };
    Object.assign(r, updates);
    this.log("نتيجة خدمة إلكترونية", r.id, before, {
      status: r.status,
      companyTransactionId: r.companyTransactionId,
    });
    return r;
  };
  function totals(rows) {
    const sold = rows.filter((r) => r.status === "succeeded"),
      refunds = rows.filter((r) => r.status === "refunded");
    return {
      quantity: sold.length,
      cost: sold.reduce((n, r) => n + r.cost, 0),
      retail: sold.reduce((n, r) => n + r.retail, 0),
      refunds: refunds.length,
      refundValue: refunds.reduce((n, r) => n + r.cost, 0),
      pending: rows.filter((r) => ["pending", "review"].includes(r.status))
        .length,
    };
  }
  const gateway = {
    async submit(r) {
      if (r.mode !== "server" || !root.MasalDigitalServer?.submit)
        throw Error("الخدمة غير متصلة");
      return root.MasalDigitalServer.submit({
        requestId: r.requestId,
        connectionId: r.connection,
        offerId: r.offer,
        posId: r.pos,
        mobile: r.mobile,
        subscriber: r.subscriber,
        ...(r.provider === "topup"
          ? { category: r.remoteId, type: r.type }
          : { catalogId: Number(r.remoteId) }),
      });
    },
    async verify(r) {
      if (r.mode !== "server" || !root.MasalDigitalServer?.verify)
        throw Error("الخدمة غير متصلة");
      return root.MasalDigitalServer.verify({
        requestId: r.requestId,
        connectionId: r.connection,
      });
    },
  };
  function transaction(vm, action) {
    const state = copy(vm.s);
    try {
      const result = action();
      MasalStateStorage.write(vm.s);
      return result;
    } catch (e) {
      vm.s = state;
      throw e;
    }
  }
  const panel = {
    props: {
      initialTab: { type: String, default: "sell" },
      selectedOffer: { type: Object, default: null },
      initialReceipt: { type: Object, default: null },
    },
    emits: ["back"],
    data() {
      return {
        tab: this.initialTab,
        draft: null,
        catalogLoaded: false,
        companyCatalog: [],
        categoryToAdd: "",
        credentialValue: "",
        assignment: null,
        sale: {
          connection: "",
          pos: "",
          offer: "",
          mobile: "",
          confirmMobile: "",
          firstName: "",
          lastName: "",
          beinProvinceId: "",
        },
        confirmation: false,
        busy: false,
        error: "",
        requestId: "",
        receipt: null,
        filter: {
          provider: "",
          agent: "",
          pos: "",
          status: "",
          category: "",
          from: "",
          to: "",
          mode: "",
          query: "",
        },
        page: 1,
      };
    },
    computed: {
      focusedSale() {
        return !!(this.selectedOffer || this.initialReceipt);
      },
      vm() {
        return this.$root;
      },
      e() {
        return this.vm.engine;
      },
      connections() {
        return this.e.digitalVisibleConnections();
      },
      points() {
        return this.vm.s.pos.filter((p) => this.e.digitalPOSAllowed(p.id));
      },
      sellConnections() {
        return this.connections.filter(
          (c) =>
            c.active && this.e.digitalPointOffers(c, this.vm.actor.pos).length,
        );
      },
      selected() {
        return this.sellConnections.find((c) => c.id === this.sale.connection);
      },
      salePoints() {
        return this.points.filter(
          (p) =>
            this.selected &&
            this.e.digitalPointOffers(this.selected, p.id).length,
        );
      },
      offers() {
        return this.selected
          ? this.e.digitalPointOffers(this.selected, this.sale.pos)
          : [];
      },
      offer() {
        return this.offers.find((o) => o.id === this.sale.offer);
      },
      needsPhone() {
        return (
          this.selected?.provider === "topup" ||
          this.offer?.packageType === "premium"
        );
      },
      agents() {
        return this.vm.s.agents.filter(
          (a) => a.type === "رئيسي" && a.active !== false && !a.archivedAt,
        );
      },
      draftPoints() {
        return this.draft
          ? this.vm.s.pos.filter(
              (p) =>
                p.active !== false &&
                !p.archivedAt &&
                this.e.descendants(this.draft.agent).includes(p.agent),
            )
          : [];
      },
      availableDraftProducts() {
        return this.draftProducts.filter(
          (p) => !this.draft.offers.some((o) => o.productId === p.id),
        );
      },
      draftProducts() {
        return this.draft
          ? this.vm.s.products.filter(
              (p) =>
                p.active !== false &&
                this.e.agentProductAllowed(this.draft.agent, p.id),
            )
          : [];
      },
      catalogRows() {
        if (!this.draft) return [];
        return [
          ...this.companyCatalog,
          ...this.draft.offers
            .filter(
              (o) =>
                !this.companyCatalog.some(
                  (item) =>
                    item.remoteId === o.remoteId &&
                    item.provinceId === o.provinceId,
                ),
            )
            .map((o) => ({
              key: "saved:" + o.id,
              offerId: o.id,
              name: o.remoteName || o.name,
              remoteId: o.remoteId,
              provinceId: o.provinceId,
              province: o.province,
              retail: o.retail,
            })),
        ];
      },
      allRows() {
        return this.e.digitalVisibleOrders();
      },
      topupBalance() {
        if (
          this.vm.actor.role === "pos" ||
          (this.filter.provider && this.filter.provider !== "topup")
        )
          return null;
        const accounts = this.connections.filter(
          (c) =>
            c.provider === "topup" &&
            (!this.filter.agent || c.agent === this.filter.agent),
        );
        if (!accounts.length) return null;
        const known = accounts.filter((c) => Number.isFinite(c.companyBalance));
        return {
          amount: known.length
            ? known.reduce((sum, c) => sum + c.companyBalance, 0)
            : null,
          known: known.length,
          total: accounts.length,
          agent: this.filter.agent,
        };
      },
      filtered() {
        const f = this.filter,
          q = f.query.trim().toLowerCase();
        return this.allRows.filter(
          (r) =>
            (!f.provider || r.provider === f.provider) &&
            (!f.agent || r.mainAgent === f.agent) &&
            (!f.pos || r.pos === f.pos) &&
            (!f.status || r.status === f.status) &&
            (!f.category || r.product === f.category) &&
            (!f.mode || r.mode === f.mode) &&
            (!f.from || Masal.businessDay(r.time) >= f.from) &&
            (!f.to || Masal.businessDay(r.time) <= f.to) &&
            (!q ||
              [
                r.id,
                r.companyTransactionId,
                r.employee,
                r.mobile,
                r.category,
                this.vm.nameOf("pos", r.pos),
              ]
                .join(" ")
                .toLowerCase()
                .includes(q)),
        );
      },
      shown() {
        return this.filtered.slice((this.page - 1) * 25, this.page * 25);
      },
      pages() {
        return Math.max(1, Math.ceil(this.filtered.length / 25));
      },
      summary() {
        return totals(this.filtered);
      },
      canSell() {
        return this.vm.can("digital.create");
      },
      canAssign() {
        return (
          ["main", "sub"].includes(this.vm.actor.role) &&
          this.vm.can("digital.view")
        );
      },
      assignmentPoints() {
        return this.points.filter((p) => p.agent === this.vm.actor.agent);
      },
      assignmentAgents() {
        return this.vm.s.agents.filter(
          (a) => a.parent === this.vm.actor.agent && a.active && !a.archivedAt,
        );
      },
      assignmentOffers() {
        const c = this.connections.find((c) => c.id === this.assignment?.id);
        return c ? this.e.digitalAgentOffers(c, this.vm.actor.agent) : [];
      },
      visibleGrants() {
        return this.connections.flatMap((c) =>
          (c.grants || [])
            .filter((g) => g.from === this.vm.actor.agent)
            .map((g) => ({ ...g, connection: c.id, provider: c.provider })),
        );
      },
      canSetup() {
        return (
          this.vm.actor.role === "owner" && this.vm.can("integrations.edit")
        );
      },
      costVisible() {
        return this.vm.actor.role !== "pos" && this.vm.can("data.cost");
      },
      providerNames() {
        return providers;
      },
      stateNames() {
        return statuses;
      },
    },
    watch: {
      "sale.connection"() {
        this.sale.offer = "";
        this.sale.beinProvinceId = "";
        this.sale.pos = this.salePoints[0]?.id || "";
        this.confirmation = false;
      },
      sale: {
        deep: true,
        handler() {
          this.confirmation = false;
          this.error = "";
        },
      },
      filter: {
        deep: true,
        handler() {
          this.page = 1;
        },
      },
      "vm.currentUser"() {
        this.draft = null;
        this.credentialValue = "";
        this.assignment = null;
        this.receipt = null;
        this.confirmation = false;
        this.requestId = "";
        this.sale = {
          connection: "",
          pos: "",
          offer: "",
          mobile: "",
          confirmMobile: "",
          firstName: "",
          lastName: "",
          beinProvinceId: "",
        };
        this.tab = "log";
        this.filter = {
          provider: "",
          agent: "",
          pos: "",
          status: "",
          category: "",
          from: "",
          to: "",
          mode: "",
          query: "",
        };
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
    },
    methods: {
      tr(v) {
        return this.vm.tr(v);
      },
      money(v) {
        return this.vm.money(v);
      },
      edit(c) {
        c =
          c ||
          this.connections.find(
            (c) => c.provider === "rabiaa" && c.agent === this.agents[0]?.id,
          );
        this.draft = c
          ? copy(c)
          : {
              provider: "rabiaa",
              agent: this.agents[0]?.id || "",
              mode: "server",
              active: true,
              credentialLabel: "",
              posIds: [],
              offers: [],
            };
        this.catalogLoaded = false;
        this.companyCatalog = [];
        this.credentialValue = "";
        this.categoryToAdd = "";
        this.error = "";
        this.tab = "settings";
      },
      selectDraftConnection() {
        const { provider, agent } = this.draft,
          existing = this.connections.find(
            (c) => c.provider === provider && c.agent === agent,
          );
        this.draft = existing
          ? copy(existing)
          : {
              provider,
              agent,
              mode: "server",
              active: true,
              credentialLabel: "",
              posIds: [],
              offers: [],
            };
        this.catalogLoaded = false;
        this.companyCatalog = [];
        this.credentialValue = "";
        this.categoryToAdd = "";
        this.error = "";
      },
      changeAgent() {
        this.selectDraftConnection();
      },
      changeProvider() {
        this.selectDraftConnection();
      },
      addCategory() {
        const p = this.availableDraftProducts.find(
          (p) => p.id === this.categoryToAdd,
        );
        if (p) this.toggleProduct(p, true);
        this.categoryToAdd = "";
      },
      toggleProduct(p, checked) {
        if (checked)
          this.draft.offers.push({
            id: Masal.id("OFFER"),
            productId: p.id,
            name: p.name,
            remoteId: "",
            provinceId: "",
            packageType: "standard",
            beinProvinceId: "",
            type: "topup",
            cost: 0,
            retail: Math.max(1, Number(p.face || 5000)),
            active: true,
          });
        else
          this.draft.offers = this.draft.offers.filter(
            (o) => o.productId !== p.id,
          );
      },
      async loadCatalog() {
        if (this.busy || !this.draft) return;
        this.busy = true;
        const draft = this.draft,
          actor = this.vm.currentUser,
          provider = draft.provider,
          agent = draft.agent;
        try {
          if (!root.MasalDigitalServer?.catalog)
            throw Error("جلب فئات الشركة غير متاح؛ الربط بالسيرفر غير متصل");
          const response = await root.MasalDigitalServer.catalog({
            connectionId: draft.id || "",
            provider: draft.provider,
            agentId: draft.agent,
            ...(this.credentialValue
              ? { credential: this.credentialValue }
              : {}),
          });
          if (
            actor !== this.vm.currentUser ||
            draft !== this.draft ||
            provider !== draft.provider ||
            agent !== draft.agent
          )
            return;
          const rows =
            draft.provider === "topup" ? response?.products : response?.data;
          draft.beinProvinces = Array.isArray(response?.beinProvinces)
            ? response.beinProvinces
                .filter((p) => /^\d+$/.test(String(p.id)) && p.name)
                .map((p) => ({
                  id: String(p.id),
                  name: String(p.name).slice(0, 100),
                }))
            : [];
          if (!Array.isArray(rows))
            throw Error("لم يرجع السيرفر قائمة فئات صحيحة");
          this.companyCatalog = rows
            .filter(
              (r) =>
                r &&
                r.title &&
                (draft.provider === "topup" ? r.product_id : r.catalogId) !=
                  null &&
                String(
                  draft.provider === "topup" ? r.product_id : r.catalogId,
                ).trim().length,
            )
            .map((r, i) => ({
              key: String(i),
              name: String(r.title),
              remoteId: String(
                draft.provider === "topup" ? r.product_id : r.catalogId,
              ),
              provinceId: String(r.provinceId || ""),
              province: String(r.province || ""),
              packageType: r.packageType === "premium" ? "premium" : "standard",
              type: ["topup", "bundle", "bill"].includes(r.type)
                ? r.type
                : "topup",
              cost: amount(
                draft.provider === "topup" ? r.price : r.vendorPrice,
              ),
              retail: amount(
                draft.provider === "topup" ? r.price : r.publicPrice,
              ),
            }));
          this.catalogLoaded = true;
          this.error = "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      selectCompanyCategory(o, key) {
        const item = this.companyCatalog.find((r) => r.key === key);
        if (!item) return;
        Object.assign(o, {
          remoteId: item.remoteId,
          remoteName: item.name,
          provinceId: item.provinceId,
          province: item.province,
          packageType: item.packageType,
          type: item.type,
          cost: item.cost,
          retail: item.retail,
        });
      },
      catalogOffer(item) {
        return this.draft.offers.find((o) =>
          item.offerId
            ? o.id === item.offerId
            : o.remoteId === item.remoteId && o.provinceId === item.provinceId,
        );
      },
      catalogProducts(item) {
        return this.draftProducts.filter(
          (p) =>
            !this.draft.offers.some(
              (o) =>
                o.productId === p.id &&
                o.remoteId &&
                (o.remoteId !== item.remoteId ||
                  o.provinceId !== item.provinceId),
            ),
        );
      },
      bindCatalogProduct(item, productId) {
        const old = this.catalogOffer(item);
        if (!productId) {
          if (old)
            this.draft.offers = this.draft.offers.filter(
              (o) => o.id !== old.id,
            );
          return;
        }
        const product = this.catalogProducts(item).find(
          (p) => p.id === productId,
        );
        if (!product) {
          this.error = "الفئة مرتبطة بعرض آخر";
          return;
        }
        let offer =
          old || this.draft.offers.find((o) => o.productId === productId);
        if (!offer) {
          this.toggleProduct(product, true);
          offer = this.draft.offers[this.draft.offers.length - 1];
        }
        const price = old?.retail;
        Object.assign(offer, { productId: product.id, name: product.name });
        this.selectCompanyCategory(offer, item.key);
        if (price !== undefined) offer.retail = price;
        this.error = "";
      },
      async refreshBalances() {
        if (this.busy) return;
        this.busy = true;
        const actor = this.vm.currentUser;
        try {
          if (!root.MasalDigitalServer?.inventory)
            throw Error("تحديث رصيد Topup غير متاح؛ الربط بالسيرفر غير متصل");
          for (const c of this.connections.filter(
            (c) =>
              c.provider === "topup" &&
              (!this.filter.agent || c.agent === this.filter.agent),
          )) {
            const response = await root.MasalDigitalServer.inventory({
              connectionId: c.id,
            });
            const balance = Number(response?.remaining_balance);
            if (
              response?.remaining_balance == null ||
              !Number.isFinite(balance) ||
              balance < 0
            )
              throw Error("لم يرجع السيرفر رصيدًا صحيحًا");
            if (actor !== this.vm.currentUser) return;
            transaction(this.vm, () => {
              c.companyBalance = balance;
              c.balanceUpdatedAt = now();
            });
          }
          this.error = "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      async save() {
        if (this.busy) return;
        this.busy = true;
        const actor = this.vm.currentUser;
        try {
          const draft = copy(this.draft);
          // Validate before sending a credential; secrets never enter browser state or audit.
          const validated = new Masal.Engine(
            copy(this.vm.s),
            actor,
          ).saveDigitalConnection(draft);
          draft.id = validated.id;
          if (this.credentialValue) {
            if (!root.MasalDigitalServer?.configure)
              throw Error("حفظ بيانات الربط غير متاح");
            const result = await root.MasalDigitalServer.configure({
              connection: draft,
              credential: this.credentialValue,
            });
            if (!result?.saved) throw Error("لم يؤكد السيرفر حفظ الربط");
          }
          if (actor !== this.vm.currentUser)
            throw Error("تغير المستخدم أثناء الحفظ");
          transaction(this.vm, () => this.e.saveDigitalConnection(draft));
          this.credentialValue = "";
          this.draft = null;
          this.vm.notify("تم حفظ إعداد الخدمة");
          this.error = "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      toggle(c) {
        try {
          transaction(this.vm, () =>
            this.e.saveDigitalConnection({ ...copy(c), active: !c.active }),
          );
        } catch (e) {
          this.error = e.message;
        }
      },
      assign(c) {
        this.assignment = { id: c.id, kind: "agent", target: "", offerIds: [] };
        this.error = "";
      },
      loadAllocation() {
        const c = this.connections.find((c) => c.id === this.assignment.id),
          g = c?.grants.find(
            (g) =>
              g.kind === this.assignment.kind &&
              g.target === this.assignment.target &&
              g.from === this.vm.actor.agent,
          );
        this.assignment.offerIds = (g?.offerIds || []).filter((id) =>
          this.assignmentOffers.some((o) => o.id === id),
        );
      },
      changeAllocationKind() {
        this.assignment.target = "";
        this.assignment.offerIds = [];
      },
      toggleGrant(g) {
        try {
          transaction(this.vm, () =>
            this.e.setDigitalGrantActive(
              g.connection,
              g.kind,
              g.target,
              g.active === false,
            ),
          );
          this.error = "";
        } catch (e) {
          this.error = e.message;
        }
      },
      saveAllocation() {
        try {
          transaction(this.vm, () =>
            this.e.allocateDigitalOffers(
              this.assignment.id,
              this.assignment.kind,
              this.assignment.target,
              this.assignment.offerIds,
            ),
          );
          this.assignment = null;
          this.vm.notify("تم حفظ الفئات المسموحة");
          this.error = "";
        } catch (e) {
          this.error = e.message;
        }
      },
      preview() {
        try {
          this.e.requirePermission("digital.create");
          if (
            !this.selected ||
            !this.offer ||
            !this.salePoints.some((p) => p.id === this.sale.pos)
          )
            throw Error("اختر الخدمة ونقطة البيع والفئة");
          if (!root.MasalDigitalServer?.submit) throw Error("الخدمة غير متصلة");
          if (this.selected.mode !== "server")
            throw Error("يجب تحديث إعداد الربط");
          if (this.focusedSale && this.needsPhone) phone(this.sale.mobile);
          if (
            this.needsPhone &&
            !this.focusedSale &&
            phone(this.sale.mobile) !== phone(this.sale.confirmMobile)
          )
            throw Error("رقم الزبون وتأكيد الرقم غير متطابقين");
          if (
            this.offer.packageType === "premium" &&
            (!this.sale.firstName.trim() || !this.sale.lastName.trim())
          )
            throw Error("أدخل اسم المشترك واسم العائلة");
          this.confirmation = true;
          this.error = "";
        } catch (e) {
          this.error = e.message;
        }
      },
      async submit() {
        if (this.busy || !this.confirmation) return;
        this.busy = true;
        const actor = this.vm.currentUser;
        let record;
        try {
          if (!root.MasalDigitalServer?.submit) throw Error("الخدمة غير متصلة");
          if (this.selected?.mode !== "server")
            throw Error("يجب تحديث إعداد الربط");
          if (this.focusedSale && this.needsPhone) phone(this.sale.mobile);
          if (
            this.needsPhone &&
            !this.focusedSale &&
            phone(this.sale.mobile) !== phone(this.sale.confirmMobile)
          )
            throw Error("رقم الزبون وتأكيد الرقم غير متطابقين");
          this.requestId ||= Masal.id("REQUEST");
          record = transaction(this.vm, () =>
            this.e.beginDigitalOrder(this.sale, this.requestId),
          );
          if (record.status !== "pending")
            throw Error("الطلب مسجل سابقًا؛ راجع سجل العمليات");
          let result;
          try {
            result = await gateway.submit(record);
          } catch (e) {
            result = {
              status: "review",
              message: e.message || "تعذر تأكيد النتيجة",
            };
          }
          if (actor !== this.vm.currentUser)
            throw Error("تغير المستخدم؛ العملية محفوظة بانتظار التحقق");
          try {
            transaction(this.vm, () =>
              this.e.resolveDigitalOrder(record.id, result),
            );
          } catch (e) {
            transaction(this.vm, () =>
              this.e.resolveDigitalOrder(record.id, {
                status: "review",
                message: e.message,
              }),
            );
          }
          const saved = this.vm.s.digitalOrders.find((r) => r.id === record.id);
          this.vm.notify(statuses[saved.status]);
          this.receipt = null;
          if (saved.status === "succeeded") await this.viewReceipt(saved);
          this.requestId = "";
          this.confirmation = false;
          this.sale.mobile = "";
          this.sale.confirmMobile = "";
          this.tab = this.focusedSale ? "sell" : "log";
          this.error = saved.status === "failed" ? saved.message : "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      async verify(r) {
        if (this.busy) return;
        this.busy = true;
        const actor = this.vm.currentUser;
        try {
          this.e.requirePermission("digital.create");
          this.e.require(r.agent);
          const result = await gateway.verify(r);
          if (actor !== this.vm.currentUser)
            throw Error("تغير المستخدم أثناء التحقق");
          transaction(this.vm, () => this.e.resolveDigitalOrder(r.id, result));
          this.error = "";
        } catch (e) {
          this.error = e.message;
        } finally {
          this.busy = false;
        }
      },
      async viewReceipt(r) {
        const actor = this.vm.currentUser;
        try {
          this.e.requirePermission("digital.receipt");
          if (
            !this.allRows.some((o) => o.id === r.id) ||
            r.status !== "succeeded" ||
            r.mode !== "server"
          )
            throw Error("الإيصال غير متاح");
          this.receipt = copy(r);
          if (root.MasalDigitalServer?.receipt) {
            const data = await root.MasalDigitalServer.receipt({
              connectionId: r.connection,
              receiptRef: r.receiptRef,
              posId: r.pos,
            });
            if (actor !== this.vm.currentUser || this.receipt?.id !== r.id)
              return;
            this.receipt = {
              ...copy(r),
              code: String(data.code || ""),
              serial: String(data.serial || ""),
            };
          }
        } catch (e) {
          this.error = e.message;
        }
      },
      print() {
        if (
          !this.receipt ||
          this.receipt.status !== "succeeded" ||
          !this.vm.can("digital.receipt")
        )
          return;
        try {
          this.e.checkOperation(this.receipt.pos, "printing");
        } catch (e) {
          this.error = e.message;
          return;
        }
        // Print only this receipt, without another purchase or accounting entry.
        document.body.classList.add("digital-printing");
        const clean = () => document.body.classList.remove("digital-printing");
        window.addEventListener("afterprint", clean, { once: true });
        try {
          window.print();
        } finally {
          setTimeout(clean, 1500);
        }
      },
      exportRows() {
        try {
          this.e.requirePermission("digital.export");
          const rows = this.filtered.map((r) => ({
            العملية: r.id,
            الخدمة: providers[r.provider],
            الوكيل: this.vm.nameOf("agents", r.mainAgent),
            النقطة: this.vm.nameOf("pos", r.pos),
            الموظف: r.employee,
            الفئة: r.category,
            "رقم الزبون": r.mobile,
            التاريخ: r.time,
            الحالة: statuses[r.status],
            ...(this.costVisible ? { الكلفة: r.cost } : {}),
            "مبلغ البيع": r.retail,
            "مرجع الشركة": r.companyTransactionId,
          }));
          if (!rows.length) throw Error("لا توجد عمليات للتصدير");
          const escape = (v) =>
            '"' + String(v ?? "").replaceAll('"', '""') + '"';
          const keys = Object.keys(rows[0]),
            content =
              "\ufeff" +
              [
                keys.map(escape).join(","),
                ...rows.map((r) =>
                  keys
                    .map((k) =>
                      escape(
                        /^[=+@-]/.test(String(r[k] ?? "")) ? "'" + r[k] : r[k],
                      ),
                    )
                    .join(","),
                ),
              ].join("\r\n");
          const url = URL.createObjectURL(
              new Blob([content], { type: "text/csv;charset=utf-8" }),
            ),
            a = document.createElement("a");
          a.href = url;
          a.download = "masal-digital-services.csv";
          a.click();
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) {
          this.error = e.message;
        }
      },
    },
  };
  const mobileHeader = {
    computed: {
      vm() {
        return this.$root;
      },
      point() {
        return this.vm.s.pos.find((p) => p.id === this.vm.actor.pos);
      },
    },
  };
  const mobileNav = {
    computed: {
      vm() {
        return this.$root;
      },
      items() {
        return [
          { id: "sell", label: "البيع" },
          { id: "sales", label: "العمليات" },
          { id: "wallets", label: "المحفظة" },
        ].filter(
          (n) =>
            this.vm.can(n.id + ".view") ||
            (["sell", "sales"].includes(n.id) && this.vm.can("digital.view")),
        );
      },
    },
  };
  const mobileCatalog = {
    components: { "digital-service-panel": panel },
    data: () => ({
      company: "all",
      kind: "all",
      query: "",
      selected: null,
      confirmation: false,
    }),
    computed: {
      vm() {
        return this.$root;
      },
      e() {
        return this.vm.engine;
      },
      entries() {
        const digital = this.e
          .digitalVisibleConnections()
          .flatMap((c) =>
            this.e
              .digitalPointOffers(c, this.vm.actor.pos)
              .map((o) => ({
                key: c.id + ":" + o.id,
                product: o.productId,
                company:
                  c.provider === "rabiaa"
                    ? c.providerId || "rabiaa"
                    : this.vm.s.providers.some(
                          (p) =>
                            p.id ===
                            this.vm.s.products.find((p) => p.id === o.productId)
                              ?.provider,
                        )
                      ? this.vm.s.products.find((p) => p.id === o.productId)
                          .provider
                      : c.provider,
                name: o.name,
                kind: c.provider === "topup" ? "topup" : "card",
                price: o.retail,
                connection: c.id,
                offer: o.id,
              })),
          );
        const assigned = new Set(digital.map((o) => o.product));
        const stock = (
          this.vm.can("sell.create") ? this.vm.availableSaleProducts : []
        )
          .filter(
            (p) =>
              !assigned.has(p.id) &&
              Number(this.vm.priceFor(this.vm.actor.agent, p.id)) > 0 &&
              this.vm.s.cards.some(
                (c) =>
                  c.product === p.id &&
                  c.agent === this.e.main(this.vm.actor.agent) &&
                  c.status === "Available" &&
                  c.expiry > Masal.day(),
              ),
          )
          .map((p) => ({
            key: "stock:" + p.id,
            product: p.id,
            company: p.provider,
            name: p.name,
            kind: "card",
            price: this.vm.priceFor(this.vm.actor.agent, p.id),
          }));
        return [...stock, ...digital];
      },
      companies() {
        return [...new Set(this.entries.map((o) => o.company))].map((id) => ({
          id,
          name:
            providers[id] ||
            (this.vm.nameOf("providers", id) === "—"
              ? id
              : this.vm.nameOf("providers", id)),
          count: this.entries.filter((o) => o.company === id).length,
        }));
      },
      companyName() {
        return this.companies.find((c) => c.id === this.company)?.name || "";
      },
      shown() {
        const q = this.query.trim().toLowerCase();
        return this.entries.filter(
          (o) =>
            (this.company === "all" || o.company === this.company) &&
            (this.kind === "all" || o.kind === this.kind) &&
            (!q || o.name.toLowerCase().includes(q)),
        );
      },
      balance() {
        return this.e.serviceAvailable(this.vm.actor.pos, "voucher");
      },
      todaySales() {
        return (
          this.vm.visibleSales
            .filter((r) => Masal.businessDay(r.time) === Masal.day())
            .reduce((n, r) => n + r.total, 0) +
          this.e
            .digitalVisibleOrders()
            .filter(
              (r) =>
                r.status === "succeeded" &&
                Masal.businessDay(r.time) === Masal.day(),
            )
            .reduce((n, r) => n + r.retail, 0)
        );
      },
      point() {
        return this.vm.s.pos.find((p) => p.id === this.vm.actor.pos);
      },
    },
    watch: {
      "vm.currentUser"() {
        this.reset();
      },
      "vm.page"() {
        this.reset();
      },
      "vm.saleForm.quantity"() {
        this.confirmation = false;
      },
      "vm.saleForm.retailPrice"() {
        this.confirmation = false;
      },
    },
    methods: {
      reset() {
        this.company = "all";
        this.selected = null;
        this.kind = "all";
        this.query = "";
        this.confirmation = false;
      },
      openCompany(c) {
        this.company = c.id;
        this.kind = "all";
        this.query = "";
      },
      choose(o) {
        this.selected = o;
        if (o.connection) return;
        this.vm.saleForm.pos = this.vm.actor.pos;
        this.vm.saleForm.product = o.product;
        this.vm.saleForm.quantity = 1;
        this.vm.saleForm.retailPrice = o.price;
        this.confirmation = false;
      },
      review() {
        const q = Number(this.vm.saleForm.quantity),
          price = Number(this.vm.saleForm.retailPrice);
        if (
          !Number.isInteger(q) ||
          q < 1 ||
          !Number.isFinite(price) ||
          price <= 0
        ) {
          this.vm.notify("أدخل عددًا وسعرًا صحيحين", true);
          return;
        }
        this.confirmation = true;
      },
      sell() {
        if (
          !this.confirmation ||
          !this.entries.some((o) => o.key === this.selected?.key)
        )
          return;
        this.vm.sell();
        if (this.vm.modal?.kind === "receipt") {
          this.selected = null;
          this.confirmation = false;
        }
      },
    },
  };
  const mobileOperations = {
    components: { "digital-service-panel": panel },
    data: () => ({ kind: "all", query: "", page: 1, receipt: null }),
    computed: {
      vm() {
        return this.$root;
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return [
          ...this.vm.visibleSales.map((r) => ({
            ...r,
            digital: false,
            name: this.vm.nameOf("products", r.product),
            value: r.total,
            kind: "card",
            state: this.vm.status(r.status),
          })),
          ...this.vm.engine
            .digitalVisibleOrders()
            .map((r) => ({
              ...r,
              digital: true,
              name: r.category,
              value: r.retail,
              kind: r.provider === "topup" ? "topup" : "card",
              state: statuses[r.status],
            })),
        ]
          .filter(
            (r) =>
              (this.kind === "all" || r.kind === this.kind) &&
              (!q ||
                [r.name, r.id, r.mobile].join(" ").toLowerCase().includes(q)),
          )
          .sort((a, b) => b.time.localeCompare(a.time));
      },
      shown() {
        return this.rows.slice(0, this.page * 20);
      },
    },
    watch: {
      kind() {
        this.page = 1;
      },
      query() {
        this.page = 1;
      },
    },
    methods: {
      providerNamesForOperation(id) {
        return providers[id] || "";
      },
      open(r) {
        if (r.digital) {
          if (r.status === "succeeded") this.receipt = r;
          else this.vm.notify(r.message || statuses[r.status], true);
        } else this.vm.viewReceipt(r);
      },
      async verifyOrder(r) {
        try {
          const result = await gateway.verify(r);
          transaction(this.vm, () =>
            this.vm.engine.resolveDigitalOrder(r.id, result),
          );
          this.vm.notify(statuses[result.status]);
        } catch (e) {
          this.vm.notify(e.message, true);
        }
      },
    },
  };
  function install(o, nav) {
    o.components["digital-service-panel"] = panel;
    o.components["pos-mobile-operations"] = mobileOperations;
    o.components["pos-mobile-header"] = mobileHeader;
    o.components["pos-mobile-nav"] = mobileNav;
    o.components["pos-mobile-catalog"] = mobileCatalog;
    o.computed.posMobileEnabled = function () {
      return (
        !this.loginScreen &&
        this.actor.role === "pos" &&
        this.posViewportWidth <= 700
      );
    };
    const navigate = o.methods.go;
    o.methods.go = function (page, ...args) {
      if (
        this.posMobileEnabled &&
        page === "sales" &&
        (this.can("sales.view") || this.can("digital.view"))
      ) {
        this.page = "sales";
        this.menuOpen = false;
        this.dashboardFilter = null;
        window.location.hash = "sales";
        window.scrollTo(0, 0);
        return;
      }
      if (
        this.posMobileEnabled &&
        page === "sell" &&
        (this.can("sell.view") || this.can("digital.view"))
      ) {
        this.page = "sell";
        this.menuOpen = false;
        window.location.hash = "sell";
        window.scrollTo(0, 0);
        return;
      }
      const result = navigate.call(this, page, ...args);
      if (this.posMobileEnabled) window.scrollTo(0, 0);
      return result;
    };
    o.computed.posMobileCatalog = function () {
      return this.posMobileEnabled && this.page === "sell";
    };
    const walletPanel = o.components["operations-panel"];
    if (walletPanel) void 0;
    const saleProducts = o.computed.availableSaleProducts;
    o.computed.availableSaleProducts = function () {
      const rows = saleProducts.call(this);
      if (this.actor.role !== "pos") return rows;
      const assigned = new Set(
        this.s.digitalConnections.flatMap((c) =>
          this.engine
            .digitalPointOffers(c, this.actor.pos, true)
            .map((o) => o.productId),
        ),
      );
      return rows.filter((p) => !assigned.has(p.id));
    };
    const dashboardCards = o.computed.dashboardCards;
    o.computed.dashboardCards = function () {
      const result = dashboardCards.call(this);
      if (!this.can("digital.view")) return result;
      const rows = this.engine.digitalVisibleOrders(),
        costVisible = this.actor.role !== "pos" && this.can("data.cost");
      for (const [key, title] of Object.entries(providers)) {
        const orders = rows.filter((r) => r.provider === key),
          summary = totals(orders);
        result.push({
          key: "digital:" + key,
          title,
          value:
            key === "rabiaa"
              ? summary.retail
              : costVisible
                ? summary.cost
                : summary.retail,
          unit: "د.ع",
          note:
            key === "rabiaa"
              ? "مبلغ المبيع"
              : costVisible
                ? "المصروف لدى الشركة"
                : "المبيعات الناجحة",
          icon: "sales",
          rows: orders.map((r) => ({
            name:
              this.nameOf("pos", r.pos) +
              " · " +
              r.employee +
              " · " +
              r.category,
            value:
              key === "rabiaa" ? r.retail : costVisible ? r.cost : r.retail,
            unit: "د.ع",
            note: statuses[r.status],
          })),
        });
      }
      if (this.actor.role !== "pos") {
        const card = result.find((c) => c.key === "digital:topup"),
          connections = this.engine
            .digitalVisibleConnections()
            .filter((c) => c.provider === "topup");
        const agents = [
          ...new Set(
            [
              ...connections.map((c) => c.agent),
              ...rows
                .filter((r) => r.provider === "topup")
                .map((r) => r.mainAgent),
            ].filter(Boolean),
          ),
        ];
        if (card)
          card.balanceRows = agents.map((id) => {
            const connection = connections.find((c) => c.agent === id),
              spent = totals(
                rows.filter(
                  (r) => r.provider === "topup" && r.mainAgent === id,
                ),
              ).cost;
            return {
              agent: id,
              name: this.nameOf("agents", id),
              remaining: Number.isFinite(connection?.companyBalance)
                ? connection.companyBalance
                : null,
              spent: costVisible ? spent : null,
              updatedAt: connection?.balanceUpdatedAt || "",
            };
          });
      }
      return result;
    };
    o.methods.refreshTopupBalances = async function () {
      if (this.digitalBalanceBusy) return;
      this.digitalBalanceBusy = true;
      const actor = this.currentUser;
      try {
        this.engine.requirePermission("digital.view");
        if (this.actor.role === "pos")
          throw Error("تحديث أرصدة الوكلاء غير متاح لهذا الحساب");
        if (!root.MasalDigitalServer?.inventory)
          throw Error("تحديث الرصيد غير متاح؛ الربط بالسيرفر غير متصل");
        const connections = this.engine
          .digitalVisibleConnections()
          .filter((c) => c.provider === "topup");
        const responses = await Promise.allSettled(
          connections.map(async (c) => {
            const result = await root.MasalDigitalServer.inventory({
                connectionId: c.id,
              }),
              value = Number(result?.remaining_balance);
            if (
              result?.remaining_balance == null ||
              !Number.isFinite(value) ||
              value < 0
            )
              throw Error("لم يرجع السيرفر رصيدًا صحيحًا");
            return { id: c.id, value };
          }),
        );
        if (actor !== this.currentUser) return;
        transaction(this, () => {
          for (const response of responses)
            if (response.status === "fulfilled") {
              const c = this.s.digitalConnections.find(
                (c) => c.id === response.value.id,
              );
              if (c) {
                c.companyBalance = response.value.value;
                c.balanceUpdatedAt = now();
              }
            }
        });
        if (responses.some((r) => r.status === "rejected"))
          this.notify("تعذر تحديث بعض الأرصدة؛ تظهر آخر قيمة مستلمة", true);
        else this.notify("تم تحديث أرصدة Topup");
      } catch (e) {
        if (actor === this.currentUser) this.notify(e.message, true);
      } finally {
        this.digitalBalanceBusy = false;
      }
    };
    o.methods.openTopupAgent = function (agentId) {
      if (
        !this.engine
          .digitalVisibleConnections()
          .some((c) => c.provider === "topup" && c.agent === agentId)
      )
        return;
      this.closeModal();
      this.digitalOpenProvider = "topup";
      this.digitalOpenAgent = agentId;
      this.go("digital");
    };
    const openDashboardPage = o.methods.openDashboardPage;
    o.methods.openDashboardPage = function () {
      if (
        this.dashboardDetail?.key === "digital:topup" &&
        this.dashboardDetail.balanceRows?.length === 1
      ) {
        this.openTopupAgent(this.dashboardDetail.balanceRows[0].agent);
        return;
      }
      if (this.dashboardDetail?.key.startsWith("digital:")) {
        const key = this.dashboardDetail.key.split(":")[1];
        this.closeModal();
        this.digitalOpenProvider = key;
        this.go("digital");
        return;
      }
      return openDashboardPage.call(this);
    };
    // The old frontend hid API setup entirely; expose the new owner-only setup.
    const can = o.methods.can;
    o.methods.can = function (key) {
      if (key === "integrations.view")
        return this.actor.role === "owner" && this.engine.can(key);
      return can.call(this, key);
    };
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      initialize(d.s);
      d.digitalOpenProvider = "";
      d.digitalOpenAgent = "";
      d.digitalBalanceBusy = false;
      d.digitalSelected = null;
      d.digitalOpenOrder = "";
      d.posViewportWidth = window.innerWidth;
      return d;
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      // The standalone Vue mount owns the contents of #app, so sync its host attributes too.
      this.$watch(
        () => [
          this.posMobileEnabled,
          this.actor.role,
          this.page,
          this.s.settings.theme,
          this.loginScreen,
        ],
        () => {
          const host = document.getElementById("app");
          if (!host) return;
          host.classList.toggle("pos-mobile-app", this.posMobileEnabled);
          host.classList.toggle("pos-simple-app", this.posMobileEnabled);
          host.classList.toggle(
            "digital-workspace",
            ["digital", "integrations"].includes(this.page),
          );
          host.style.setProperty(
            "--accent",
            this.s.settings.theme || "#0891b2",
          );
        },
        { immediate: true },
      );
      this._posResize = () => {
        const previous = this.posMobileEnabled;
        this.posViewportWidth = window.innerWidth;
        if (previous && !this.posMobileEnabled && this.page === "sales")
          this.go("sales");
      };
      window.addEventListener("resize", this._posResize);
      this.$watch(
        () => this.s,
        (s) => initialize(s),
        { flush: "sync" },
      );
    };
    const unmounted = o.beforeUnmount;
    o.beforeUnmount = function () {
      window.removeEventListener("resize", this._posResize);
      unmounted?.call(this);
    };
    panel.mounted = function () {
      if (this.initialReceipt) {
        this.tab = "receipt";
        this.viewReceipt(this.initialReceipt);
        return;
      }
      if (this.vm.digitalOpenProvider) {
        this.tab = "log";
        this.filter.provider = this.vm.digitalOpenProvider;
        this.filter.agent = this.vm.digitalOpenAgent || "";
        this.vm.digitalOpenAgent = "";
        this.vm.digitalOpenProvider = "";
        if (this.vm.digitalOpenOrder) {
          this.filter.query = this.vm.digitalOpenOrder;
          this.vm.digitalOpenOrder = "";
        }
      }
      if (!this.canSell && this.tab === "sell")
        this.tab = this.canSetup || this.canAssign ? "settings" : "log";
      if (this.selectedOffer || this.vm.digitalSelected) {
        const pick = this.selectedOffer || this.vm.digitalSelected;
        this.vm.digitalSelected = null;
        this.tab = "sell";
        this.sale.connection = pick.connection;
        this.$nextTick(() => {
          this.sale.pos = this.vm.actor.pos;
          this.sale.offer = pick.offer;
        });
      }
    };
    for (const group of nav)
      group.items = group.items.filter((n) => n.id !== "integrations");
    nav
      .find((g) => g.items.some((n) => n.id === "sell"))
      ?.items.push({
        id: "digital",
        label: "خدمات API",
        icon: "◈",
        description: "",
      });
    const title = o.computed.title;
    o.computed.title = function () {
      return this.page === "digital" ? "خدمات API" : title.call(this);
    };
  }
  root.MasalDigitalServices = { install, initialize, totals, phone, gateway };
})(globalThis);

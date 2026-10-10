(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype;
  const identity = (u) =>
    u.role === "owner" || u.staffAccount === "@system"
      ? "@owner"
      : u.role === "pos"
        ? u.pos
        : u.staffAccount || u.agent || u.id;
  const parent = (s, id) =>
    s.pos.find((p) => p.id === id)?.agent ||
    s.agents.find((a) => a.id === id)?.parent ||
    "@owner";
  const get = (e, list, id) => (e.s[list] || []).find((r) => r.id === id);
  const event = (page, r, title, accounts = [], users = []) =>
    r && { page, entity: r.id, title, accounts, users };
  const network = (e, r) => [r.agent, parent(e.s, r.agent)];
  function publish(e, x, key) {
    if (!x) return;
    const recipients = e.s.users
      .filter(
        (u) =>
          u.active &&
          u.id !== e.user &&
          (x.users.includes(u.id) || x.accounts.includes(identity(u))) &&
          new M.Engine(e.s, u.id).can(x.page + ".view"),
      )
      .map((u) => u.id);
    if (!recipients.length || e.s.notifications.some((n) => n.eventKey === key))
      return;
    e.createNotice(
      x.title,
      (e.actor()?.name || "النظام") + " · " + x.title + " · " + x.entity,
      recipients,
      { page: x.page, entity: x.entity, eventKey: key, audience: "workflow" },
    );
  }
  function wire(name, make) {
    const base = P[name];
    if (!base) return;
    P[name] = function (...args) {
      const previous = this.s.audit[0]?.id,
        oldNotices = new Set(this.s.notifications.map((n) => n.id));
      const result = base.apply(this, args);
      const log = this.s.audit[0];
      if (log?.id === previous) return result;
      const x = make(this, args, result);
      if (!x) return result;
      // Replace the older single-target funding notice with the routed event.
      if (["fund", "requestFunding", "recoverFunding"].includes(name))
        this.s.notifications = this.s.notifications.filter(
          (n) =>
            oldNotices.has(n.id) ||
            !["تمويل محفظة", "طلب تمويل جديد", "استرجاع رصيد"].includes(
              n.title,
            ) ||
            n.recipientUsers,
        );
      if (this._walletFlow) return result;
      publish(this, x, "activity:" + name + ":" + log.id);
      return result;
    };
  }
  function install(o) {
    wire("requestWalletFunding", (e, a, r) =>
      event("wallets", r, "طلب تمويل جديد", [r.from]),
    );
    wire("reviewWalletFunding", (e, a, r) =>
      event(
        "wallets",
        r,
        a[1] === "approve" ? "تم تنفيذ طلب التمويل" : "تم رفض طلب التمويل",
        [r.to],
        [r.user],
      ),
    );
    wire("cancelWalletFunding", (e, a, r) =>
      event("wallets", r, "تم إلغاء طلب التمويل", [r.from]),
    );
    wire("directWalletFunding", (e, a, r) =>
      event("wallets", r, "تم تحويل الرصيد", [r.to]),
    );
    const log = P.log;
    P.log = function (action, id, before, after) {
      const result = log.call(this, action, id, before, after);
      if (
        /^(إنشاء حساب |إضافة موظف|تعديل موظف|تعديل صلاحيات تابع|تغيير التفعيل|إنشاء سجل|تعديل سجل)/.test(
          action,
        )
      ) {
        const user = get(this, "users", id),
          account =
            get(this, "agents", id) ||
            get(this, "pos", id) ||
            (after?.id === id && ["رئيسي", "فرعي"].includes(after.type)
              ? after
              : null);
        if (user || account) {
          const own = user ? identity(user) : id,
            up = parent(this.s, own),
            page = user ? "users" : get(this, "pos", id) ? "pos" : "agents";
          publish(
            this,
            event(page, { id }, action, [up]),
            "activity:account:" + this.s.audit[0].id,
          );
          publish(
            this,
            event("notifications", { id }, action, [own]),
            "activity:account-self:" + this.s.audit[0].id,
          );
        }
      }
      return result;
    };
    const securityAccounts = (e, r) =>
      [
        ...e.s.agents.map((a) => ({
          id: a.id,
          kind: !a.parent
            ? "main"
            : e.s.agents.find((x) => x.id === a.parent)?.parent
              ? "subbranch"
              : "branch",
        })),
        ...e.s.pos.map((p) => ({ id: p.id, kind: "pos" })),
      ]
        .filter(
          (a) =>
            r.scope === "all" ||
            (r.scope === "custom" && r.accounts.includes(a.id)) ||
            r.scope === a.kind,
        )
        .map((a) => a.id);
    wire("addSecurityStop", (e, a, r) =>
      event(
        "notifications",
        r,
        "تم تطبيق توقيف على حسابك",
        securityAccounts(e, r),
      ),
    );
    wire("resumeSecurityStop", (e, a) => {
      const r = get(e, "securityStops", a[0]);
      return event(
        "notifications",
        r,
        "تم رفع التوقيف عن حسابك",
        securityAccounts(e, r),
      );
    });
    wire("submitCashOrder", (e, a, r) =>
      event("import", r, "طلبية جديدة بانتظار الاعتماد", ["@owner"]),
    );
    wire("reviewCashOrder", (e, a) => {
      const r = get(e, "pendingOrders", a[0]);
      return event(
        "import",
        r,
        a[1] ? "تم اعتماد الطلبية" : "تم رفض الطلبية",
        [r.agent],
        [r.user],
      );
    });
    wire("approveCashOrder", (e, a, r) =>
      event("inventory", r, "تم تحميل طلبية جديدة", [r.agent]),
    );
    wire("inventoryAction", (e, a, r) =>
      event(
        "inventory",
        r,
        {
          quarantine: "تم إيقاف بيع الطلبية",
          resume: "تمت إعادة تفعيل الطلبية",
          cancel: "تم إلغاء الطلبية",
          restore: "تم استرجاع الطلبية",
        }[a[1]],
        network(e, r),
      ),
    );
    wire("requestFunding", (e, a, r) =>
      event("wallets", r, "طلب تمويل جديد", [r.from]),
    );
    wire("fund", (e, a, r) =>
      event("wallets", r, "تم تحويل الرصيد", [r.from, r.to]),
    );
    wire("reserveFunding", (e, a, r) => {
      const q = get(e, "fundingRequests", a[0]);
      return event(
        "wallets",
        q,
        "تم اعتماد وحجز التمويل",
        [q.from, q.to],
        [q.user],
      );
    });
    wire("completeFunding", (e, a) => {
      const h = get(e, "fundingHolds", a[0]),
        q = get(e, "fundingRequests", h?.request);
      return event(
        "wallets",
        q,
        "تم تنفيذ طلب التمويل",
        [q.from, q.to],
        [q.user],
      );
    });
    wire("cancelFundingHold", (e, a) => {
      const h = get(e, "fundingHolds", a[0]),
        q = get(e, "fundingRequests", h?.request);
      return event(
        "wallets",
        q,
        "تم إلغاء حجز التمويل",
        [q.from, q.to],
        [q.user],
      );
    });
    wire("reverseFunding", (e, a, r) =>
      event("wallets", r, "تم عكس تحويل الرصيد", [r.from, r.to]),
    );
    wire("recoverFunding", (e, a, r) =>
      event("wallets", r, "تم استرجاع رصيد", [a[0], a[1]]),
    );
    wire("collect", (e, a, r) =>
      event("wallets", r, "تم تسجيل تحصيل", [
        r.account,
        parent(e.s, r.account),
      ]),
    );
    wire("serviceCredit", (e, a) =>
      event("wallets", { id: a[4] }, "تم إيداع رصيد", [
        a[0],
        parent(e.s, a[0]),
      ]),
    );
    wire("sell", (e, a, r) =>
      event("sales", r, "عملية بيع جديدة", [r.pos, parent(e.s, r.pos)]),
    );
    wire("printResult", (e, a) => {
      const r = get(e, "sales", a[0]);
      return event(
        a[1] ? "sales" : "exceptions",
        r,
        a[1] ? "تمت الطباعة" : "فشل طباعة يحتاج متابعة",
        [r.pos, parent(e.s, r.pos)],
      );
    });
    wire("startServiceOrder", (e, a, r) =>
      event("integrations", r, "طلب خدمة جديد", network(e, r)),
    );
    wire("resolveServiceOrder", (e, a, r) =>
      event("integrations", r, "تحديث نتيجة طلب الخدمة", network(e, r)),
    );
    wire("claim", (e, a, r) =>
      event("claims", r, "مطالبة جديدة", [...network(e, r), "@owner"]),
    );
    wire("settle", (e, a) => {
      const r = get(e, "claims", a[0]);
      return event(
        "claims",
        r,
        "تمت تسوية المطالبة: " + r.status,
        network(e, r),
        [r.user],
      );
    });
    wire("requestExport", (e, a, r) =>
      event("exports", r, "طلب تصدير جديد", ["@owner"]),
    );
    for (const name of ["approveExport", "rejectExport"])
      wire(name, (e, a) => {
        const r = get(e, "exportRequests", a[0]);
        return event(
          "exports",
          r,
          name === "approveExport" ? "تم اعتماد التصدير" : "تم رفض التصدير",
          [r.agent],
          [r.user],
        );
      });
    for (const name of [
      "proposePrices",
      "approvePrices",
      "reversePrices",
      "savePricePolicy",
    ])
      wire(name, (e, a, r) => {
        const record = r?.id
          ? r
          : get(e, "priceRequests", a[0]) || { id: e.s.audit[0].entity };
        const changes = record.changes || (Array.isArray(a[0]) ? a[0] : []),
          agents = [
            ...new Set(
              changes
                .map((c) => c.agent)
                .concat(record.agent || a[0]?.agent || []),
            ),
          ];
        return event(
          "prices",
          record,
          name === "proposePrices"
            ? "تحديث أسعار جديد"
            : "تم تحديث اعتماد الأسعار",
          [...agents, ...agents.map((id) => parent(e.s, id))],
          [record.user],
        );
      });
    const oldUsers = P.noticeUsers;
    P.noticeUsers = function () {
      const adjacent = this.supportRecipients().map((x) => x.id);
      return [
        ...new Map(
          [
            ...oldUsers.call(this),
            ...this.s.users.filter(
              (u) =>
                u.active &&
                u.id !== this.user &&
                adjacent.includes(identity(u)),
            ),
          ].map((u) => [u.id, u]),
        ).values(),
      ];
    };
    const oldSupport = P.supportNotice;
    P.supportNotice = function (...args) {
      oldSupport.apply(this, args);
      for (const n of this.s.notifications.filter(
        (n) => n.supportTicket === args[0].id,
      )) {
        n.page = "support";
        n.entity = n.supportTicket;
      }
    };
    const unread = (vm, n) =>
      n.recipientUsers?.includes(vm.currentUser) &&
      !n.readBy?.includes(vm.currentUser);
    const destination = (n) =>
      n.page ||
      (n.supportTicket
        ? "support"
        : n.eventKey?.startsWith("print:")
          ? "exceptions"
          : "");
    const entity = (n) =>
      n.entity ||
      n.supportTicket ||
      (n.eventKey?.startsWith("print:") ? n.eventKey.split(":")[1] : n.id);
    o.computed.sidebarCounts = function () {
      const sets = {},
        add = (page, id) => {
          if (this.can(page + ".view")) (sets[page] ??= new Set()).add(id);
        };
      for (const n of this.visibleNotifications)
        if (unread(this, n)) {
          const page = destination(n);
          if (page) add(page, entity(n));
        }
      const me = identity(this.actor),
        admin = me === "@owner";
      for (const r of this.s.pendingOrders || [])
        if (
          r.status === "بانتظار الاعتماد" &&
          admin &&
          this.can("import.approve") &&
          this.engine.allowed(r.agent)
        )
          add("import", r.id);
      for (const r of this.s.fundingRequests || [])
        if (
          ["بانتظار التمويل", "معتمد ومحجوز"].includes(r.status) &&
          r.from === me &&
          this.can("wallets.transfer")
        )
          add("wallets", r.id);
      for (const r of this.s.exportRequests || [])
        if (
          r.status === "بانتظار الاعتماد" &&
          this.can("exports.approve") &&
          this.engine.allowed(r.agent) &&
          (r.user !== this.currentUser || this.actor.role === "owner")
        )
          add("exports", r.id);
      for (const r of this.s.claims || [])
        if (
          r.status === "معلقة" &&
          this.can("claims.settle") &&
          this.engine.allowed(r.agent)
        )
          add("claims", r.id);
      for (const r of this.s.printOverrides || [])
        if (
          r.status === "قيد المراجعة" &&
          this.can("exceptions.approve") &&
          this.engine.canApproveReprint(r)
        )
          add("exceptions", r.id);
      for (const t of this.visibleTickets || [])
        if (t.status !== "مغلقة" && t.recipient === me) add("support", t.id);
      for (const r of this.s.priceRequests || [])
        if (
          r.status === "قيد المراجعة" &&
          this.can("prices.approve") &&
          (r.creator !== this.currentUser || this.actor.role === "owner") &&
          (r.changes || []).every((c) => this.engine.allowed(c.agent))
        )
          add("prices", r.id);
      const counts = Object.fromEntries(
        Object.entries(sets).map(([k, v]) => [k, v.size]),
      );
      counts.notifications = this.unreadNotices;
      return counts;
    };
    o.methods.sidebarCount = function (id) {
      return this.sidebarCounts[id] || 0;
    };
    o.methods.sidebarGroupCount = function (group) {
      return group.items
        .filter((n) => n.id !== "notifications")
        .reduce((sum, n) => sum + this.sidebarCount(n.id), 0);
    };
    o.methods.markPageNoticesRead = function (page) {
      for (const n of this.visibleNotifications)
        if (destination(n) === page && unread(this, n)) {
          n.readBy ??= [];
          n.readBy.push(this.currentUser);
        }
    };
    const go = o.methods.go;
    o.methods.go = function (page) {
      const r = go.call(this, page);
      if (this.page === page && page !== "notifications")
        this.markPageNoticesRead(page);
      return r;
    };
    const open = o.methods.openNotice;
    o.methods.openNotice = function (n) {
      const page = destination(n);
      if (!page || n.supportTicket) return open.call(this, n);
      this.run(() => {
        if (
          !this.visibleNotifications.some((x) => x.id === n.id) ||
          !this.can(page + ".view")
        )
          throw Error("الإشعار خارج صلاحيات الحساب");
        if (unread(this, n)) {
          n.readBy ??= [];
          n.readBy.push(this.currentUser);
        }
        this.go(page);
        if (page === "inventory") {
          this.inventoryTab = "all";
          this.statusFilter = "";
          this.inventoryAgent = "";
          this.search = n.entity || "";
        }
      });
    };
  }
  root.MasalActivityNotifications = { install };
})(globalThis);

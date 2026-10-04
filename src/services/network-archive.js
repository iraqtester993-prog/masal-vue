(function (root) {
  "use strict";
  const M = Masal,
    P = M.Engine.prototype;
  for (const [key, label] of [
    ["agents.archive", "حذف أرشيفي للوكلاء ونقاط البيع"],
    ["agents.archiveView", "عرض أرشيف المحذوفات"],
  ])
    MasalAccess.catalog.push({
      key,
      module: "agents",
      group: "الوكلاء والشجرة",
      label,
      sensitive: true,
    });
  function target(e, kind, id) {
    e.requirePermission("agents.archive");
    if (!["agents", "pos"].includes(kind)) throw Error("نوع حساب غير صالح");
    const a = e.s[kind].find((x) => x.id === id);
    if (!a || a.archivedAt) throw Error("الحساب غير موجود أو مؤرشف");
    e.require(kind === "pos" ? a.agent : a.id);
    const users = e.s.users.filter((u) =>
      kind === "pos" ? u.pos === id : u.agent === id || u.staffAccount === id,
    );
    if (users.some((u) => u.id === e.user || u.role === "owner"))
      throw Error("لا يمكن أرشفة حسابك الحالي أو مدير النظام");
    return { a, users };
  }
  function check(e, kind, id) {
    const out = target(e, kind, id);
    e.operations();
    if (
      kind === "agents" &&
      (e.s.agents.some((a) => a.parent === id && !a.archivedAt) ||
        e.s.pos.some((p) => p.agent === id && !p.archivedAt))
    )
      throw Error("أرشف الفروع ونقاط البيع التابعة أولًا");
    const balances = new Map();
    for (const l of e.s.serviceLedger.filter((l) => l.account === id))
      balances.set(
        l.service,
        (balances.get(l.service) || 0) + Number(l.amount),
      );
    if (
      [...balances.values()].some((v) => Math.abs(v) > 0.005) ||
      Math.abs(e.balance(id)) > 0.005
    )
      throw Error("لا يمكن الأرشفة قبل تسوية أرصدة الحساب");
    if (
      e.s.fundingHolds?.some(
        (h) => (h.from === id || h.to === id) && h.status === "محجوز",
      ) ||
      e.s.reservations.some(
        (r) => r.pos === id && ["محجوز", "قيد الإصدار"].includes(r.status),
      )
    )
      throw Error("يوجد حجز معلّق للحساب");
    if (
      e.s.sales.some(
        (t) =>
          t.pos === id &&
          (t.printPending ||
            ["Print Requested", "Reprint Requested", "Print Failed"].includes(
              t.status,
            )),
      )
    )
      throw Error("أكمل عمليات الطباعة المعلقة أولًا");
    if (
      e.s.cards.some(
        (c) =>
          c.agent === id &&
          ["Available", "Reserved", "Quarantined"].includes(c.status),
      )
    )
      throw Error("يجب تسوية المخزون المتبقي أولًا");
    if (
      e.s.fundingRequests.some(
        (r) =>
          (r.from === id || r.to === id) &&
          !["مكتمل", "مرفوض", "ملغى", "منفذ", "ممول", "تم التمويل"].includes(
            r.status,
          ),
      )
    )
      throw Error("يوجد طلب تمويل غير مغلق");
    if (
      e.s.serviceOrders.some(
        (r) =>
          (r.agent === id || r.account === id || r.pos === id) &&
          !["Success", "Failed", "Cancelled", "ناجح", "فاشل", "ملغى"].includes(
            r.status,
          ),
      )
    )
      throw Error("يوجد طلب خدمة غير مغلق");
    return out;
  }
  P.archiveNetwork = async function (
    kind,
    id,
    reason,
    password,
    stillCurrent = () => true,
  ) {
    const actor = this.user,
      credentials = JSON.stringify(this.actor().credentials);
    const initial = check(this, kind, id),
      stamp = JSON.stringify(initial.a);
    reason = String(reason || "").trim();
    if (!reason) throw Error("اكتب سبب الحذف الأرشيفي");
    const auth = ((this.s.archiveAuthAttempts ??= {})[actor] ??= {
      count: 0,
      until: 0,
    });
    if (auth.until > Date.now())
      throw Error("محاولات كثيرة؛ حاول بعد خمس دقائق");
    if (!this.actor().credentials)
      throw Error("يجب تعيين كلمة مرور لحسابك أولًا");
    if (
      !(await MasalStaff.verifyPassword(
        String(password || ""),
        this.actor().credentials,
      ))
    ) {
      auth.count++;
      if (auth.count >= 5) {
        auth.until = Date.now() + 300000;
        auth.count = 0;
      }
      throw Error("كلمة المرور غير صحيحة");
    }
    if (
      !stillCurrent() ||
      this.user !== actor ||
      JSON.stringify(this.actor().credentials) !== credentials
    )
      throw Error("تغير الحساب؛ أعد التأكيد");
    const { a, users } = check(this, kind, id);
    if (JSON.stringify(a) !== stamp)
      throw Error("تغيرت بيانات الحساب؛ أعد التأكيد");
    auth.count = 0;
    auth.until = 0;
    return MasalMeetingRules.atomic(this, () => {
      const time = new Date().toISOString(),
        entry = {
          id: M.id("ARCHIVE"),
          kind,
          entity: id,
          name: a.name,
          reason,
          time,
          user: actor,
          before: M.clone(a),
          users: users.map((u) => ({
            id: u.id,
            name: u.name,
            email: u.email || "",
            role: u.role,
            active: u.active,
          })),
        };
      (this.s.deletedAccounts ??= []).unshift(entry);
      a.archivedAt = time;
      a.archiveId = entry.id;
      a.active = false;
      if (kind === "pos") a.online = false;
      for (const u of users) {
        u.archivedAt = time;
        u.archiveId = entry.id;
        u.active = false;
        if (u.mapPresence) u.mapPresence.connected = false;
        MasalAuth.revoke(this.s, u.id);
      }
      this.log(
        "حذف أرشيفي للحساب",
        id,
        { name: a.name },
        { archive: entry.id, kind, reason, user: actor },
      );
      return entry;
    });
  };
  const account = P.accountCheck;
  P.accountCheck = function (id) {
    if (
      [...this.s.agents, ...this.s.pos].some((a) => a.id === id && a.archivedAt)
    )
      throw Error("الحساب مؤرشف ولا يقبل عمليات جديدة");
    return account.call(this, id);
  };
  const maps = P.mapUsers;
  P.mapUsers = function (...args) {
    return maps
      .apply(this, args)
      .filter((u) => !this.s.users.find((x) => x.id === u.id)?.archivedAt);
  };
  const archive = {
    data: () => ({ query: "", page: 1, selected: "" }),
    computed: {
      vm() {
        return this.$root;
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return (this.vm.s.deletedAccounts || []).filter((r) => {
          const a = this.vm.s[r.kind].find((x) => x.id === r.entity);
          return (
            a &&
            this.vm.engine.allowed(r.kind === "pos" ? a.agent : a.id) &&
            (!q ||
              [r.name, r.entity, r.reason, ...r.users.map((u) => u.email)]
                .join(" ")
                .toLowerCase()
                .includes(q))
          );
        });
      },
      shown() {
        return this.rows.slice((this.page - 1) * 20, this.page * 20);
      },
      pages() {
        return Math.max(1, Math.ceil(this.rows.length / 20));
      },
      record() {
        return this.rows.find((r) => r.id === this.selected);
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      pages(n) {
        this.page = Math.min(n, this.page);
      },
    },
  };
  const confirm = {
    data: () => ({ password: "", reason: "", busy: false, error: "" }),
    computed: {
      vm() {
        return this.$root;
      },
    },
    methods: {
      async save() {
        if (this.busy) return;
        this.busy = true;
        this.error = "";
        const vm = this.vm,
          actor = vm.currentUser,
          state = vm.s,
          dialog = vm.modal;
        try {
          await vm.engine.archiveNetwork(
            dialog.page,
            dialog.id,
            this.reason,
            this.password,
            () =>
              vm.currentUser === actor && vm.s === state && vm.modal === dialog,
          );
          vm.persist();
          vm.closeModal();
          vm.notify("تم نقل الحساب إلى أرشيف المحذوفات؛ السجلات محفوظة");
        } catch (e) {
          this.error = e.message;
          if (vm.s === state) vm.persist();
        } finally {
          this.password = "";
          this.busy = false;
        }
      },
    },
  };
  function install(o) {
    NAV.find((g) => g.title === "إدارة النظام").items.push({
      id: "deletedAccounts",
      label: "أرشيف المحذوفات",
      icon: "▤",
      subtitle: "",
    });
    const can = o.methods.can;
    o.methods.can = function (key) {
      if (key.startsWith("deletedAccounts."))
        return this.engine.can("agents.archiveView");
      return can.call(this, key);
    };
    o.components["network-archive"] = archive;
    o.components["network-archive-confirm"] = confirm;
    for (const k of ["visibleAgents", "visiblePOS"]) {
      const base = o.computed[k];
      o.computed[k] = function () {
        return base.call(this).filter((a) => !a.archivedAt);
      };
    }
    const rows = o.computed.filteredRows;
    o.computed.filteredRows = function () {
      return rows.call(this).filter((r) => !r.archivedAt);
    };
    o.methods.canArchiveNetwork = function (kind, id) {
      try {
        target(this.engine, kind, id);
        return true;
      } catch {
        return false;
      }
    };
    o.methods.askArchiveNetwork = function (kind, id) {
      this.run(() => {
        const { a } = target(this.engine, kind, id);
        this.modal = {
          kind: "archiveNetwork",
          title: "حذف أرشيفي — " + a.name,
          page: kind,
          id,
        };
      });
    };
    for (const k of ["openEdit", "toggleEntity"]) {
      const base = o.methods[k];
      o.methods[k] = function (row, ...args) {
        if (row?.archivedAt) {
          this.notify("الحساب مؤرشف؛ لا يمكن تعديله أو تفعيله", true);
          return;
        }
        return base.call(this, row, ...args);
      };
    }
  }
  root.MasalNetworkArchive = { install };
})(globalThis);

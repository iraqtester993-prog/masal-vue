(function (root) {
  "use strict";
  const P = Masal.Engine.prototype,
    now = () => new Date().toISOString();
  function identity(e) {
    const u = e.actor(),
      role = MasalAccess.managementRole(e.s, u);
    return role === "owner"
      ? "@system"
      : role === "pos"
        ? u.pos
        : u.staffAccount || u.agent || "";
  }
  function parent(e, id) {
    const point = e.s.pos.find((p) => p.id === id);
    if (point) return point.agent;
    return e.s.agents.find((a) => a.id === id)?.parent || "@system";
  }
  function recipient(e, r) {
    return r.recipient || parent(e, r.pos);
  }
  function notice(e, r, to, title) {
    const users = e.s.users
      .filter(
        (u) =>
          u.active &&
          (to === "@system"
            ? u.role === "owner" || u.staffAccount === "@system"
            : u.role === "pos"
              ? u.pos === to
              : (u.staffAccount || u.agent) === to),
      )
      .map((u) => u.id);
    e.s.notifications.unshift({
      id: Masal.id("NT"),
      title,
      body: r.tx + " · " + r.reason,
      target: to,
      recipientUsers: users,
      user: e.user,
      time: now(),
      readBy: [],
      eventKey: "print:" + r.id + ":" + Masal.id("EV"),
    });
  }
  const result = P.printResult;
  P.printResult = function (id, ok, reason) {
    reason = String(reason || "").trim();
    if (!ok && !reason) throw Error("اذكر سبب فشل الطباعة");
    return MasalMeetingRules.atomic(this, () => {
      const out = result.call(this, id, ok),
        t = this.s.sales.find((t) => t.id === id);
      if (!ok) {
        t.printFailureReason = reason;
        t.attempts.at(-1).reason = reason;
        this.log("سبب فشل الطباعة", id, null, { reason });
      }
      return out;
    });
  };
  const request = P.requestReprint;
  P.requestReprint = function (id, reason) {
    return MasalMeetingRules.atomic(this, () => {
      const t = this.s.sales.find((t) => t.id === id),
        prior = t?.reprintApproval,
        r = request.call(this, id, reason);
      if (r.id !== prior || !r.recipient) {
        r.recipient = parent(this, r.pos);
        r.failureReason = t.printFailureReason || "";
        r.history = [
          {
            from: r.pos,
            to: r.recipient,
            time: now(),
            user: this.user,
            reason: r.reason,
          },
        ];
        notice(this, r, r.recipient, "طلب معالجة طباعة");
      }
      return r;
    });
  };
  P.canApproveReprint = function (r) {
    return (
      r.user !== this.user &&
      identity(this) === recipient(this, r) &&
      this.allowed(r.agent)
    );
  };
  P.canEscalatePrint = function (r) {
    return (
      r.status === "قيد المراجعة" &&
      this.can("exceptions.approve") &&
      this.canApproveReprint(r) &&
      recipient(this, r) !== "@system"
    );
  };
  P.escalatePrint = function (id, reason) {
    this.requirePermission("exceptions.approve");
    const r = this.s.printOverrides.find((r) => r.id === id);
    if (!r || !this.canEscalatePrint(r))
      throw Error("التصعيد للمسؤول المستلم الحالي فقط");
    reason = String(reason || "").trim();
    if (!reason) throw Error("اذكر سبب التصعيد");
    return MasalMeetingRules.atomic(this, () => {
      const from = recipient(this, r),
        to = parent(this, from);
      r.recipient = to;
      r.history = r.history || [];
      r.history.push({ from, to, time: now(), user: this.user, reason });
      notice(this, r, to, "طلب معالجة طباعة مصعّد");
      this.log(
        "تصعيد معالجة الطباعة",
        id,
        { recipient: from },
        { recipient: to, reason },
      );
      return r;
    });
  };
  const approve = P.approveReprint;
  P.approveReprint = function (id, ok) {
    return MasalMeetingRules.atomic(this, () => {
      const result = approve.call(this, id, ok),
        r = this.s.printOverrides.find((r) => r.id === id);
      notice(
        this,
        r,
        r.pos,
        ok ? "تم اعتماد إعادة الطباعة" : "تم رفض إعادة الطباعة",
      );
      return result;
    });
  };
  function install(o) {
    const view = o.methods.viewReceipt;
    o.methods.viewReceipt = function (t) {
      const x = view.call(this, t);
      if (this.modal?.kind === "receipt") {
        this.modal.failureEditing = false;
        this.modal.failureReason = "";
      }
      return x;
    };
    o.methods.printResult = function (ok) {
      const id = this.modal?.tx?.id,
        reason = this.modal?.failureReason || "";
      this.run(
        () => {
          this.engine.printResult(id, ok, reason);
          this.modal.failureEditing = false;
        },
        ok
          ? "تم تسجيل نجاح الطباعة"
          : "تم تسجيل الفشل وسببه؛ يمكنك رفع طلب معالجة",
      );
    };
    const open = o.methods.openReprint;
    o.methods.openReprint = function (t) {
      const out = open.call(this, t);
      if (this.modal?.kind === "reprint")
        this.reason = t.printFailureReason || "";
      return out;
    };
    const panel = o.components["operations-panel"];
    panel.methods.escalatePrint = function (r) {
      this.act(
        () => this.e.escalatePrint(r.id, this.requestReasons[r.id]),
        "تم رفع الطلب للمستوى الأعلى",
      );
    };
    panel.methods.printRecipient = function (r) {
      const id = recipient(this.e, r);
      return id === "@system" ? "إدارة النظام" : this.name(id);
    };
  }
  root.MasalPrintEscalation = { install };
})(globalThis);

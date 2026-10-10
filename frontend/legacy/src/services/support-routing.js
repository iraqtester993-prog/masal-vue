(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  P.supportIdentity = function () {
    const u = this.actor();
    return u.role === "owner" ||
      (u.role === "employee" && u.staffAccount === "@system")
      ? "@owner"
      : u.role === "pos"
        ? u.pos
        : u.agent || u.id;
  };
  P.supportParent = function (id) {
    if (id === "@owner") return "";
    const p = this.s.pos.find((x) => x.id === id);
    if (p) return p.agent;
    const a = this.s.agents.find((x) => x.id === id);
    return a ? a.parent || "@owner" : "@owner";
  };
  P.supportName = function (id) {
    return id === "@owner"
      ? "مدير النظام"
      : this.s.agents.find((x) => x.id === id)?.name ||
          this.s.pos.find((x) => x.id === id)?.name ||
          this.s.users.find((x) => x.id === id)?.name ||
          id;
  };
  P.supportRecipients = function () {
    const own = this.supportIdentity(),
      ids = [];
    const parent = this.supportParent(own);
    if (parent) ids.push(parent);
    if (own === "@owner")
      ids.push(
        ...this.s.agents.filter((a) => !a.parent && a.active).map((a) => a.id),
      );
    else if (
      ["main", "sub"].includes(this.actor().role) ||
      (this.actor().role === "employee" && this.actor().staffAccount)
    ) {
      ids.push(
        ...this.s.agents
          .filter((a) => a.parent === own && a.active)
          .map((a) => a.id),
        ...this.s.pos
          .filter((p) => p.agent === own && p.active)
          .map((p) => p.id),
      );
    }
    return [...new Set(ids)].map((id) => ({ id, name: this.supportName(id) }));
  };
  P.supportVisible = function (t) {
    if (!t.routing) return this.actor().role === "owner";
    const own = this.supportIdentity();
    return t.sender === this.user || t.participants.includes(own);
  };
  P.supportCheck = function (id, permission) {
    this.requirePermission(permission);
    const t = this.s.tickets.find((t) => t.id === id);
    if (!t || !this.supportVisible(t))
      throw Error("الرسالة خارج نطاق المراسلة");
    return t;
  };
  P.sendSupport = function (form) {
    this.requirePermission("support.create");
    if (form.image) this.requirePermission("support.attach");
    if (!this.supportRecipients().some((x) => x.id === form.recipient))
      throw Error("اختر المسؤول المباشر أو أحد التابعين المباشرين");
    const title = String(form.title || "").trim(),
      description = String(form.description || "").trim();
    if (!title || !description) throw Error("العنوان والشرح مطلوبان");
    const own = this.supportIdentity(),
      t = {
        id: Masal.id("T"),
        routing: 1,
        sender: this.user,
        origin: own,
        recipient: form.recipient,
        participants: [own, form.recipient],
        agent:
          this.actor().agent ||
          this.s.agents.find((a) => a.id === form.recipient)?.id ||
          "",
        title,
        description,
        image: MasalFeatureUpdates.attachment(form.image),
        status: "مفتوحة",
        replies: [],
        history: [],
        time: new Date().toISOString(),
      };
    this.s.tickets.unshift(t);
    this.log("إرسال رسالة دعم", t.id, null, {
      from: own,
      to: t.recipient,
      title,
    });
    return t;
  };
  P.replySupport = function (id, body) {
    const t = this.supportCheck(id, "support.reply");
    if (t.status === "مغلقة") throw Error("المحادثة مغلقة");
    body = String(body || "").trim();
    if (!body) throw Error("اكتب الرد");
    t.replies.push({ body, user: this.user, time: new Date().toISOString() });
    this.log("رد على رسالة دعم", id, null, { user: this.user });
  };
  P.supportCanManage = function (t) {
    return (
      t.routing &&
      this.supportVisible(t) &&
      t.recipient === this.supportIdentity() &&
      t.status !== "مغلقة"
    );
  };
  P.changeSupport = function (id, status) {
    const t = this.supportCheck(
      id,
      status === "مغلقة" ? "support.close" : "support.escalate",
    );
    if (!this.supportCanManage(t))
      throw Error("الإجراء متاح للمستلم الحالي فقط");
    if (!["مغلقة", "مصعّدة"].includes(status)) throw Error("حالة غير صالحة");
    const old = t.recipient;
    if (status === "مصعّدة") {
      const parent = this.supportParent(old);
      if (!parent) throw Error("الرسالة وصلت إلى أعلى مستوى");
      t.recipient = parent;
      if (!t.participants.includes(parent)) t.participants.push(parent);
    }
    t.status = status;
    t.history.push({
      from: old,
      to: t.recipient,
      status,
      user: this.user,
      time: new Date().toISOString(),
    });
    this.log(
      "تحديث مسار رسالة دعم",
      id,
      { recipient: old },
      { recipient: t.recipient, status },
    );
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), directSupportReplies: {} };
    };

    o.computed.visibleTickets = function () {
      return this.s.tickets.filter((t) => this.engine.supportVisible(t));
    };
    o.computed.supportRecipients = function () {
      return this.engine.supportRecipients();
    };
    Object.assign(o.methods, {
      sendDirectSupportReply(t) {
        const key = this.currentUser + ":" + t.id;
        this.run(() => {
          this.engine.replySupport(t.id, this.directSupportReplies[key]);
          this.directSupportReplies[key] = "";
        }, "تم إرسال الرد");
      },
      openTicket() {
        this.run(() => {
          this.engine.requirePermission("support.create");
          this.ticketForm = {
            recipient: this.supportRecipients[0]?.id || "",
            title: "",
            description: "",
            image: "",
          };
          this.modal = { kind: "newTicket", title: "رسالة دعم جديدة" };
        });
      },
      saveTicket() {
        if (this.attachmentBusy) return;
        this.run(() => {
          this.engine.sendSupport(this.ticketForm);
          this.ticketForm = {
            recipient: "",
            title: "",
            description: "",
            image: "",
          };
          this.closeModal();
        }, "تم إرسال الرسالة للمستلم المحدد داخل النظام");
      },
      showTicket(t) {
        this.run(() => {
          const ticket = this.engine.supportCheck(t.id, "support.view");
          this.reply = "";
          this.modal = { kind: "ticket", title: ticket.title, ticket };
        });
      },
      replyTicket() {
        this.run(() => {
          this.engine.replySupport(this.modal.ticket.id, this.reply);
          this.reply = "";
        }, "تم إرسال الرد");
      },
      ticketStatus(status) {
        this.run(
          () => this.engine.changeSupport(this.modal.ticket.id, status),
          status === "مغلقة"
            ? "تم إغلاق المحادثة"
            : "تم تصعيد الرسالة للمستوى الأعلى",
        );
      },
    });
  }
  root.MasalSupportRouting = { install };
})(globalThis);

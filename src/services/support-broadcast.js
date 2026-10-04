(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  MasalAccess.catalog.push({
    key: "support.broadcast",
    module: "support",
    group: "الدعم الفني",
    label: "إرسال رسالة دعم عامة أو جماعية",
    sensitive: true,
  });
  function account(u) {
    return u.role === "pos" ? u.pos : u.staffAccount || u.agent || "@owner";
  }
  const noticeUsers = function () {
    const me = this.actor(),
      role = MasalAccess.managementRole(this.s, me),
      own = me.staffAccount || me.agent,
      desc = new Set(own && own !== "@system" ? this.descendants(own) : []);
    return this.s.users.filter((u) => {
      if (!u.active || u.id === me.id) return false;
      if (me.role === "owner") return true;
      if (role === "pos") return false;
      const id = account(u),
        agent =
          u.role === "pos"
            ? this.s.pos.find((p) => p.id === u.pos)?.agent
            : u.staffAccount || u.agent;
      if (!agent || agent === "@system" || !this.allowed(agent)) return false;
      if (role === "main" || role === "sub")
        return u.role !== "main" && id !== own && desc.has(agent);
      return u.role !== "owner";
    });
  };
  P.supportBroadcastUsers = function () {
    this.requirePermission("support.broadcast");
    return this.s.users.filter((u) => {
      if (!u.active || u.id === this.user) return false;
      if (this.actor().role === "owner") return true;
      const agent =
        u.role === "pos"
          ? this.s.pos.find((p) => p.id === u.pos)?.agent
          : u.staffAccount || u.agent;
      if (!agent || agent === "@system")
        return this.actor().staffAccount === "@system";
      return this.allowed(agent);
    });
  };
  P.sendSupportBroadcast = function (form, mode, selected) {
    this.requirePermission("support.create");
    this.requirePermission("support.broadcast");
    if (form.image) this.requirePermission("support.attach");
    if (!["all", "agents", "branches", "custom"].includes(mode))
      throw Error("اختر المستخدمين");
    let users = this.supportBroadcastUsers();
    if (mode === "agents") users = users.filter((u) => u.role === "main");
    if (mode === "branches") users = users.filter((u) => u.role === "sub");
    if (mode === "custom") {
      if (
        !Array.isArray(selected) ||
        selected.some((id) => !users.some((u) => u.id === id))
      )
        throw Error("المستلم خارج نطاقك");
      users = users.filter((u) => selected.includes(u.id));
    }
    if (!users.length) throw Error("اختر مستلمًا واحدًا على الأقل");
    const title = String(form.title || "").trim(),
      description = String(form.description || "").trim(),
      image = MasalFeatureUpdates.attachment(form.image);
    if (!title || !description) throw Error("العنوان والنص مطلوبان");
    return MasalMeetingRules.atomic(this, () => {
      const group = Masal.id("SUPPORT-GROUP");
      for (const u of users) {
        const recipient =
            u.role === "owner" || u.staffAccount === "@system"
              ? "@owner"
              : account(u),
          own = this.supportIdentity(),
          t = {
            id: Masal.id("T"),
            routing: 1,
            broadcastGroup: group,
            broadcastRecipient: u.id,
            sender: this.user,
            origin: own,
            recipient,
            routeStack: [...new Set([own, recipient])],
            participants: [...new Set([own, recipient])],
            agent: u.agent || "",
            title,
            description,
            image,
            status: "مفتوحة",
            replies: [],
            history: [],
            time: new Date().toISOString(),
          };
        this.s.tickets.unshift(t);
        this.createNotice(title, description, [u.id], {
          supportTicket: t.id,
          page: "support",
          entity: t.id,
          audience: "support",
        });
      }
      this.log("إرسال رسالة دعم جماعية", group, null, {
        count: users.length,
        mode,
      });
      return users.length;
    });
  };
  const visible = P.supportVisible;
  P.supportVisible = function (t) {
    return t.broadcastRecipient
      ? this.actor().role === "owner" ||
          t.sender === this.user ||
          t.broadcastRecipient === this.user
      : visible.call(this, t);
  };
  const notice = P.supportNotice;
  P.supportNotice = function (t, title, targets) {
    if (!t.broadcastRecipient) return notice.call(this, t, title, targets);
    const ids = [...new Set([t.sender, t.broadcastRecipient])].filter(
      (id) =>
        id !== this.user && this.s.users.some((u) => u.id === id && u.active),
    );
    if (ids.length)
      this.createNotice(title, t.title, ids, {
        supportTicket: t.id,
        page: "support",
        entity: t.id,
        audience: "support",
      });
  };
  const change = P.changeSupport;
  P.changeSupport = function (id, status) {
    if (
      status === "مصعّدة" &&
      this.s.tickets.find((t) => t.id === id)?.broadcastRecipient
    )
      throw Error("هذه محادثة مباشرة مع المرسل؛ لا تحتاج تصعيدًا");
    return change.call(this, id, status);
  };
  function install(o) {
    P.noticeUsers = noticeUsers;
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        supportAudience: "direct",
        supportSelected: [],
        supportSearch: "",
      };
    };
    o.computed.supportBroadcastChoices = function () {
      if (!this.can("support.broadcast")) return [];
      const q = this.supportSearch.trim().toLowerCase();
      return this.engine
        .supportBroadcastUsers()
        .filter((u) =>
          (u.name + " " + (u.email || "")).toLowerCase().includes(q),
        );
    };
    o.methods.resetSupportComposer = function () {
      this.supportAudience = this.can("support.broadcast") ? "all" : "direct";
      this.supportSelected = [];
      this.supportSearch = "";
      this.ticketForm = {
        recipient: this.supportRecipients[0]?.id || "",
        title: "",
        description: "",
        image: "",
      };
    };
    o.methods.openTicket = function () {
      this.run(() => {
        this.engine.requirePermission("support.create");
        this.closeModal();
        this.go("support");
        this.$nextTick(() =>
          document.querySelector(".support-composer input")?.focus(),
        );
      });
    };
    o.methods.saveTicket = function () {
      if (this.attachmentBusy || this.formPending.saveTicket) return;
      this.run(() => {
        this.engine.requirePermission("support.create");
        if (this.supportAudience === "direct") {
          this.engine.sendSupport(this.ticketForm);
          this.notify("تم إرسال الرسالة للمستلم المحدد");
        } else {
          const count = this.engine.sendSupportBroadcast(
            this.ticketForm,
            this.supportAudience,
            this.supportSelected,
          );
          this.notify("تم إرسال الرسالة إلى " + count + " مستخدم");
        }
        this.resetSupportComposer();
      });
    };
    for (const key of ["page", "currentUser"]) {
      const previous = o.watch[key];
      o.watch[key] = function (value, old) {
        if (typeof previous === "function") previous.call(this, value, old);
        else if (previous && typeof previous.handler === "function")
          previous.handler.call(this, value, old);
        if (key === "currentUser" || value === "support")
          this.resetSupportComposer();
      };
    }
  }
  root.MasalSupportBroadcast = { install };
})(globalThis);

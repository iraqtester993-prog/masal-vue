(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  P.supportMessages = function (t) {
    if (!this.supportVisible(t)) return [];
    const replies = new Set(this.visibleSupportReplies(t));
    return [
      {
        key: "opening",
        body: t.description,
        image: t.image,
        user: t.sender,
        time: t.time,
      },
      ...(t.replies || []).flatMap((r, i) =>
        replies.has(r) ? [{ ...r, key: "reply:" + i }] : [],
      ),
    ].map((m) => ({
      ...m,
      name:
        this.s.users.find((u) => u.id === m.user)?.name ||
        this.supportName(t.origin || t.agent),
      mine: m.user === this.user,
    }));
  };
  P.supportUnread = function (t) {
    const seen = new Set(t.supportRead?.[this.user] || []);
    return this.supportMessages(t).filter((m) => !m.mine && !seen.has(m.key))
      .length;
  };
  P.readSupport = function (id) {
    const t = this.supportCheck(id, "support.view");
    (t.supportRead ??= {})[this.user] = this.supportMessages(t).map(
      (m) => m.key,
    );
  };
  P.supportPhones = function (id) {
    this.requirePermission("support.view");
    if (
      !this.supportRecipients().some((x) => x.id === id) &&
      id !== this.supportIdentity() &&
      !this.s.tickets.some(
        (t) =>
          this.supportVisible(t) &&
          [t.origin, t.recipient, ...(t.participants || [])].includes(id),
      )
    )
      return [];
    const entity =
      id === "@owner"
        ? this.s.settings
        : this.s.agents.find((a) => a.id === id);
    if (!entity) return [];
    if (Array.isArray(entity.supportPhones)) return entity.supportPhones;
    const old = String(
      id === "@owner" ? entity.support || "" : entity.support || "",
    ).trim();
    return /^[+\d\s()-]{7,25}$/.test(old)
      ? [{ label: "الدعم الفني", number: old }]
      : [];
  };
  P.saveSupportPhones = function (rows) {
    this.requirePermission("support.create");
    const u = this.actor();
    if (!["owner", "main", "sub"].includes(u.role))
      throw Error("تعديل أرقام الدعم لصاحب الحساب فقط");
    const entity =
      u.role === "owner"
        ? this.s.settings
        : this.s.agents.find((a) => a.id === u.agent);
    if (!entity) throw Error("الحساب غير موجود");
    if (!Array.isArray(rows) || rows.length > 5)
      throw Error("الحد الأقصى خمسة أرقام للدعم");
    const next = rows.map((r) => ({
      label: String(r.label || "الدعم الفني")
        .trim()
        .slice(0, 60),
      number: String(r.number || "").trim(),
    }));
    if (next.some((r) => !/^07[78][0-9]{8}$/.test(r.number)))
      throw Error(
        "رقم الموبايل يجب أن يبدأ بـ077 أو 078 ويتكون من 11 رقمًا فقط",
      );
    const before = Masal.clone(entity.supportPhones || []);
    entity.supportPhones = next;
    this.log("تعديل أرقام الدعم", this.supportIdentity(), before, next);
    return next;
  };
  const phones = {
    props: ["account"],
    computed: {
      vm() {
        return this.$root;
      },
      rows() {
        return this.account ? this.vm.engine.supportPhones(this.account) : [];
      },
    },
    methods: {
      tel(n) {
        return "tel:" + n.replace(/[^+\d]/g, "");
      },
    },
  };
  const contacts = {
    data: () => ({ draft: [], loaded: false }),
    computed: {
      vm() {
        return this.$root;
      },
      canEdit() {
        return (
          ["owner", "main", "sub"].includes(this.vm.actor.role) &&
          this.vm.can("support.create")
        );
      },
      recipient() {
        return this.vm.supportAudience === "direct"
          ? this.vm.ticketForm.recipient
          : this.vm.engine.supportParent(this.vm.engine.supportIdentity());
      },
    },
    watch: {
      "vm.currentUser"() {
        this.loaded = false;
        this.draft = [];
      },
    },
    methods: {
      load(e) {
        if (e.target.open) {
          this.draft = Masal.clone(
            this.vm.engine.supportPhones(this.vm.engine.supportIdentity()),
          );
          this.loaded = true;
        }
      },
      save() {
        this.vm.run(() => {
          this.vm.engine.saveSupportPhones(this.draft);
          this.vm.persist();
        }, "تم حفظ أرقام الدعم");
      },
    },
  };
  const inbox = {
    data: () => ({
      query: "",
      filter: "all",
      page: 1,
      draft: "",
      sending: false,
    }),
    computed: {
      vm() {
        return this.$root;
      },
      e() {
        return this.vm.engine;
      },
      selected() {
        return this.vm.visibleTickets.find(
          (t) => t.id === this.vm.supportSelectedTicket,
        );
      },
      messages() {
        return this.selected ? this.e.supportMessages(this.selected) : [];
      },
      rows() {
        const q = this.query.trim().toLowerCase();
        return this.vm.visibleTickets
          .filter(
            (t) =>
              (this.filter !== "unread" || this.e.supportUnread(t) > 0) &&
              (!q ||
                [
                  t.title,
                  this.e.supportName(t.origin),
                  this.e.supportName(t.recipient),
                ]
                  .join(" ")
                  .toLowerCase()
                  .includes(q)),
          )
          .slice()
          .sort((a, b) => this.lastTime(b).localeCompare(this.lastTime(a)));
      },
      shown() {
        return this.rows.slice((this.page - 1) * 20, this.page * 20);
      },
      pages() {
        return Math.max(1, Math.ceil(this.rows.length / 20));
      },
      unread() {
        return this.vm.visibleTickets.filter((t) => this.e.supportUnread(t) > 0)
          .length;
      },
      counterpart() {
        if (!this.selected) return "";
        const own = this.e.supportIdentity();
        return this.selected.recipient === own
          ? this.selected.origin
          : this.selected.recipient;
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      filter() {
        this.page = 1;
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
      "vm.supportSelectedTicket"() {
        this.draft = "";
        this.read();
      },
      "vm.currentUser"() {
        this.draft = "";
        this.query = "";
        this.filter = "all";
      },
      messages: {
        deep: true,
        handler() {
          this.read();
        },
      },
    },
    mounted() {
      this.visibility = () => {
        if (!document.hidden) this.read();
      };
      document.addEventListener("visibilitychange", this.visibility);
      this.read();
    },
    beforeUnmount() {
      document.removeEventListener("visibilitychange", this.visibility);
    },
    methods: {
      lastTime(t) {
        return this.e.supportMessages(t).at(-1)?.time || t.time || "";
      },
      preview(t) {
        return this.e.supportMessages(t).at(-1)?.body || "";
      },
      open(t) {
        this.vm.showTicket(t);
      },
      read() {
        if (!this.selected || document.hidden || this.vm.page !== "support")
          return;
        const keys = this.messages.map((m) => m.key);
        if (
          JSON.stringify(this.selected.supportRead?.[this.vm.currentUser]) !==
          JSON.stringify(keys)
        ) {
          this.e.readSupport(this.selected.id);
          this.vm.persist();
        }
        this.$nextTick(() => {
          const box = this.$refs.messages;
          if (box) box.scrollTop = box.scrollHeight;
        });
      },
      send() {
        if (this.sending) return;
        this.sending = true;
        try {
          this.e.replySupport(this.selected.id, this.draft);
          this.draft = "";
          this.read();
          this.vm.persist();
        } catch (e) {
          this.vm.notify(e.message, true);
        } finally {
          this.sending = false;
        }
      },
      status(value) {
        this.vm.run(() => {
          this.e.changeSupport(this.selected.id, value);
          this.vm.persist();
        });
      },
    },
  };
  function install(o) {
    o.components["support-phone-links"] = phones;
    contacts.components = { "support-phone-links": phones };
    inbox.components = { "support-phone-links": phones };
    o.components["support-contacts"] = contacts;
    o.components["support-inbox"] = inbox;
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), supportSelectedTicket: "" };
    };
    o.methods.showTicket = function (t) {
      this.run(() => {
        this.engine.supportCheck(t.id, "support.view");
        this.go("support");
        this.supportSelectedTicket = t.id;
        this.$nextTick(() =>
          document
            .querySelector(".support-chat-detail")
            ?.scrollIntoView({ block: "nearest" }),
        );
      });
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      this.$watch(
        () => this.currentUser,
        () => (this.supportSelectedTicket = ""),
      );
    };
  }
  root.MasalSupportChat = { install };
})(globalThis);

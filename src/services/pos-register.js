(function (root) {
  "use strict";
  const columns = [
    ["name", "الاسم التجاري"],
    ["owner", "اسم صاحب المكتب"],
    ["createdAt", "تاريخ إنشاء الحساب"],
    ["phone", "رقم الهاتف"],
    ["representative", "المندوب"],
    ["agent", "الوكيل"],
    ["city", "المحافظة"],
    ["model", "الجهاز"],
    ["serial", "سيريال الجهاز"],
    ["active", "الحالة"],
    ["online", "الاتصال"],
  ];
  function created(vm, p) {
    const u = MasalNetworkAccounts.linked(vm.s, "pos", p.id);
    return (
      p.createdAt ||
      u?.createdAt ||
      vm.s.audit
        .filter(
          (r) =>
            (r.entity === p.id && r.action === "إنشاء سجل") ||
            (r.entity === u?.id && r.action === "إنشاء حساب نقطة بيع"),
        )
        .map((r) => r.time)
        .filter((t) => Number.isFinite(Date.parse(t)))
        .sort()[0] ||
      ""
    );
  }
  const table = {
    data: () => ({ hidden: [], now: Date.now() }),
    computed: {
      vm() {
        return this.$root;
      },
      columns() {
        return columns;
      },
      shown() {
        return columns.filter((c) => !this.hidden.includes(c[0]));
      },
      rows() {
        const q = this.vm.search.trim().toLowerCase();
        return this.vm.visiblePOS.filter(
          (p) =>
            !q ||
            [
              p.name,
              p.owner,
              p.phone,
              p.representative,
              p.city,
              this.vm.nameOf("agents", p.agent),
            ].some((v) =>
              String(v || "")
                .toLowerCase()
                .includes(q),
            ),
        );
      },
    },
    mounted() {
      this.load();
      this._presenceTimer = setInterval(() => (this.now = Date.now()), 15000);
      this._outside = (e) => {
        if (this.$refs.columns && !this.$refs.columns.contains(e.target))
          this.$refs.columns.open = false;
      };
      document.addEventListener("pointerdown", this._outside);
    },
    beforeUnmount() {
      clearInterval(this._presenceTimer);
      document.removeEventListener("pointerdown", this._outside);
    },
    watch: {
      "vm.currentUser"() {
        this.load();
      },
    },
    methods: {
      online(p) {
        return (
          !!p.active &&
          this.vm.s.users.some(
            (u) => u.pos === p.id && MasalUserMap.connected(u, this.now),
          )
        );
      },
      load() {
        try {
          const v = JSON.parse(
            localStorage.getItem("masal-pos-columns-" + this.vm.currentUser) ||
              "[]",
          );
          this.hidden = Array.isArray(v)
            ? v.filter((k) => k !== "name" && columns.some((c) => c[0] === k))
            : [];
        } catch {
          this.hidden = [];
        }
      },
      toggle(k) {
        this.hidden = this.hidden.includes(k)
          ? this.hidden.filter((x) => x !== k)
          : [...this.hidden, k];
        try {
          localStorage.setItem(
            "masal-pos-columns-" + this.vm.currentUser,
            JSON.stringify(this.hidden),
          );
        } catch {
          this.vm.notify("تعذر حفظ اختيار الأعمدة", true);
        }
      },
      value(p, k) {
        if (k === "online") return this.online(p) ? "متصل" : "غير متصل";
        if (k === "createdAt") {
          const t = created(this.vm, p);
          return t ? this.vm.formatTime(t) : "غير مسجل";
        }
        if (k === "agent") return this.vm.nameOf("agents", p.agent);
        if (k === "representative")
          return (
            (p.representativeIds || [])
              .map(
                (id) =>
                  this.vm.s.representatives?.find((r) => r.id === id)?.name,
              )
              .filter(Boolean)
              .join("، ") ||
            p.representative ||
            "—"
          );
        if (k === "posTypeId")
          return (
            this.vm.s.posTypes?.find((t) => t.id === p.posTypeId)?.name || "—"
          );
        return p[k] || "—";
      },
      edit(p) {
        this.vm.go("pos");
        if (this.vm.page === "pos") this.vm.openEdit(p);
      },
      details(p) {
        this.vm.engine.require(p.agent);
        this.vm.modal = {
          pos: p,
          kind: "posDetails",
          title: "تفاصيل نقطة البيع",
          data: {
            "بريد تسجيل الدخول": this.vm.networkLoginEmail("pos", p.id),
            المندوبون:
              (p.representativeIds || [])
                .map(
                  (id) =>
                    this.vm.s.representatives?.find((r) => r.id === id)?.name,
                )
                .filter(Boolean)
                .join("، ") || "غير محدد",
            المستمـسكات:
              (p.documents || []).reduce(
                (n, d) => n + (d.images?.length || 0),
                0,
              ) + " صورة",
            ...Object.fromEntries(
              columns.map(([k, n]) => [
                n,
                k === "active"
                  ? p.active
                    ? "مفعل"
                    : "موقوف"
                  : this.value(p, k),
              ]),
            ),
            الملاحظات: p.notes || "—",
          },
        };
      },
    },
  };
  root.MasalPOSRegister = {
    install(o) {
      table.components = { "page-actions": o.components["page-actions"] };
      table.methods.resettable = function (p) {
        const u = MasalNetworkAccounts.linked(this.vm.s, "pos", p.id);
        return !!u && this.vm.canResetUserPassword(u.id);
      };
      table.methods.requestReset = function (p) {
        const u = MasalNetworkAccounts.linked(this.vm.s, "pos", p.id);
        if (u) this.vm.requestPasswordReset(u.id);
      };
      o.components["network-points-table"] = table;
    },
    created,
  };
})(globalThis);

(function (root) {
  "use strict";
  function rows(s) {
    const saved = s.governorates || [];
    return [
      ...new Set([
        ...MasalCategoriesUI.governorates,
        ...saved.map((x) => x.name),
        ...s.agents.map((x) => x.city),
        ...s.pos.map((x) => x.city),
        ...s.products.flatMap((x) => x.allowedCities || []),
      ]),
    ]
      .filter(Boolean)
      .map((name) => ({
        name,
        active: saved.find((x) => x.name === name)?.active !== false,
      }));
  }
  function active(s, name) {
    return !!name && rows(s).some((x) => x.name === name && x.active);
  }
  Masal.Engine.prototype.toggleGovernorate = function (name) {
    this.requirePermission("governorates.toggle");
    if (this.actor().role !== "owner")
      throw Error("إدارة المحافظات لمدير النظام فقط");
    const list = rows(this.s),
      r = list.find((x) => x.name === name);
    if (!r) throw Error("المحافظة غير موجودة");
    const before = r.active;
    r.active = !before;
    this.s.governorates = list;
    this.log(
      "تغيير تفعيل محافظة",
      name,
      { active: before },
      { active: r.active },
    );
  };
  function install(o) {
    o.computed.activeGovernorates = function () {
      return rows(this.s)
        .filter((x) => x.active)
        .map((x) => x.name);
    };
    o.computed.governorateRows = function () {
      return rows(this.s);
    };
    o.computed.governorates = function () {
      return rows(this.s)
        .filter(
          (x) =>
            x.active ||
            (this.editForm.allowedCities || []).includes(x.name) ||
            this.editForm.city === x.name,
        )
        .map((x) => x.name);
    };
    const options = o.methods.optionsFor;
    o.methods.optionsFor = function (f) {
      if (["agents", "pos"].includes(this.page) && f.key === "city")
        return rows(this.s)
          .filter((x) => x.active || x.name === this.editForm.city)
          .map((x) => ({
            value: x.name,
            label: x.name + (x.active ? "" : " — معطلة"),
          }));
      return options.call(this, f);
    };
    const save = o.methods.saveEntity;
    o.methods.saveEntity = function () {
      if (this.page === "products") {
        const old = this.s.products.find((x) => x.id === this.editForm.id);
        if (
          (this.editForm.allowedCities || []).some(
            (name) =>
              !active(this.s, name) && !old?.allowedCities?.includes(name),
          )
        ) {
          this.notify("إحدى المحافظات المختارة معطلة؛ اختر محافظة مفعلة", true);
          return;
        }
      }
      return save.call(this);
    };
    o.methods.toggleGovernorate = function (name) {
      this.run(
        () => this.engine.toggleGovernorate(name),
        "تم تحديث حالة المحافظة",
      );
    };
    const cards = o.computed.dashboardCards;
    o.computed.dashboardCards = function () {
      if (this.actor.role !== "owner") return cards.call(this);
      return [
        {
          key: "mainAgents",
          title: "الوكلاء الرئيسيون",
          data: this.s.agents.filter((x) => x.type === "رئيسي"),
          icon: "agents",
          unit: "وكيل",
        },
        {
          key: "subAgents",
          title: "الوكلاء الفرعيون",
          data: this.s.agents.filter((x) => x.type === "فرعي"),
          icon: "agents",
          unit: "وكيل",
        },
        {
          key: "activeGovernorates",
          title: "المحافظات النشطة",
          data: rows(this.s).filter((x) => x.active),
          icon: "map",
          unit: "محافظة",
        },
        {
          key: "networkPOS",
          title: "نقاط البيع",
          data: this.s.pos,
          icon: "pos",
          unit: "نقطة",
        },
      ]
        .map((c) => ({
          ...c,
          value: c.data.length,
          note:
            c.key === "activeGovernorates"
              ? "المفعلة في النظام"
              : "إجمالي المسجلين",
          rows: c.data.map((r) => ({
            name: r.name,
            value: null,
            unit: "",
            note: r.active ? "مفعل" : "معطل",
          })),
        }))
        .concat(cards.call(this));
    };
    const reportKpis = o.computed.reportKpis;
    o.computed.reportKpis = function () {
      const existing = reportKpis.call(this);
      if (
        this.actor.role !== "owner" ||
        !this.can("reports.network") ||
        this.reportBundle.error
      )
        return existing;
      const network =
          this.reportBundle.sections.find((s) => s.id === "network")?.rows ||
          [],
        points =
          this.reportBundle.sections.find((s) => s.id === "network-pos")
            ?.rows || [];
      const scopedCities = this.reportPOS
        ? new Set(points.map((p) => p.city))
        : this.reportAgent
          ? new Set([...network, ...points].map((r) => r.city))
          : null;
      const provinces = rows(this.s).filter(
        (r) =>
          r.active &&
          (!this.reportCity || r.name === this.reportCity) &&
          (!scopedCities || scopedCities.has(r.name)),
      );
      return existing.concat([
        {
          label: "الوكلاء الرئيسيون",
          value: network.filter((a) => a.type === "رئيسي").length,
        },
        {
          label: "الوكلاء الفرعيون",
          value: network.filter((a) => a.type === "فرعي").length,
        },
        { label: "المحافظات النشطة", value: provinces.length },
        { label: "نقاط البيع", value: points.length },
        {
          label: "نقاط البيع النشطة",
          value: points.filter((p) => p.active === "مفعل").length,
        },
        {
          label: "الفرعيون التابعون لفرعي",
          value: network.filter((a) => {
            const original = this.s.agents.find((x) => x.id === a.id);
            return (
              original?.type === "فرعي" &&
              this.s.agents.some(
                (parent) =>
                  parent.id === original.parent && parent.type === "فرعي",
              )
            );
          }).length,
        },
        {
          label: "نقاط البيع المعطّلة",
          value: points.filter((p) => p.active === "موقوف").length,
        },
      ]);
    };
    const open = o.methods.openDashboardPage;
    o.methods.openDashboardPage = function () {
      const key = this.dashboardDetail?.key,
        page = {
          mainAgents: "agents",
          subAgents: "agents",
          activeGovernorates: "governorates",
          networkPOS: "pos",
        }[key];
      if (!page) return open.call(this);
      this.closeModal();
      this.go(page);
      this.treeView = false;
      this.dashboardFilter = {
        key,
        page,
        title: {
          mainAgents: "الوكلاء الرئيسيون",
          subAgents: "الوكلاء الفرعيون",
          activeGovernorates: "المحافظات النشطة",
          networkPOS: "نقاط البيع",
        }[key],
      };
    };
    const filtered = o.computed.filteredRows;
    o.computed.filteredRows = function () {
      let r = filtered.call(this);
      if (
        this.page === "agents" &&
        ["mainAgents", "subAgents"].includes(this.dashboardFilter?.key)
      )
        r = r.filter(
          (x) =>
            x.type ===
            (this.dashboardFilter.key === "mainAgents" ? "رئيسي" : "فرعي"),
        );
      return r;
    };
    o.components["governorates-page"] = {
      computed: {
        vm() {
          return this.$root;
        },
        rows() {
          return this.vm.governorateRows.filter(
            (r) =>
              (!this.vm.search || r.name.includes(this.vm.search)) &&
              (this.vm.dashboardFilter?.key !== "activeGovernorates" ||
                r.active),
          );
        },
      },
    };
  }
  root.MasalRegions = { rows, isActive: active, install };
})(globalThis);

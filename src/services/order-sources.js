(function (root) {
  "use strict";
  for (const [verb, label] of [
    ["view", "عرض"],
    ["create", "إضافة"],
    ["edit", "تعديل"],
    ["toggle", "تفعيل وتعطيل"],
  ])
    MasalAccess.catalog.push({
      key: "sources." + verb,
      module: "sources",
      group: "المصادر",
      label,
      sensitive: verb !== "view",
    });
  const P = Masal.Engine.prototype;
  P.saveOrderSource = function (draft) {
    this.s.orderSources ??= [];
    const old = this.s.orderSources.find((x) => x.id === draft.id);
    this.requirePermission("sources." + (old ? "edit" : "create"));
    const name = String(draft.name || "").trim(),
      provider = this.s.providers.find(
        (p) => p.id === draft.provider && p.active,
      );
    if (!name) throw Error("أدخل اسم المصدر");
    if (!provider) throw Error("اختر شركة مفعلة");
    if (
      this.s.orderSources.some(
        (x) =>
          x.id !== old?.id &&
          x.provider === provider.id &&
          x.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase(),
      )
    )
      throw Error("اسم المصدر مسجل لهذه الشركة");
    const before = old ? Masal.clone(old) : null,
      record = {
        id: old?.id || Masal.id("SOURCE"),
        name,
        provider: provider.id,
        active: old?.active ?? true,
      };
    if (old) Object.assign(old, record);
    else this.s.orderSources.push(record);
    this.log(old ? "تعديل مصدر" : "إضافة مصدر", record.id, before, record);
    return record;
  };
  P.toggleOrderSource = function (id) {
    this.requirePermission("sources.toggle");
    const r = this.s.orderSources?.find((x) => x.id === id);
    if (!r) throw Error("المصدر غير موجود");
    const before = Masal.clone(r);
    r.active = !r.active;
    this.log(
      r.active ? "تفعيل مصدر" : "تعطيل مصدر",
      id,
      before,
      Masal.clone(r),
    );
  };
  function install(o, nav) {
    nav
      .find((g) => g.title === "البطاقات والعمليات")
      .items.push({
        id: "sources",
        label: "المصادر",
        icon: "◈",
        description: "",
      });
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      d.s.orderSources ??= [];
      return d;
    };
    const validate = P.validateImport;
    P.validateImport = function (meta, rows) {
      if (meta.sourceId) {
        const p = this.s.products.find((p) => p.id === meta.product),
          source = this.s.orderSources?.find((x) => x.id === meta.sourceId);
        if (
          !source?.active ||
          source.provider !== p?.provider ||
          !this.s.providers.some((x) => x.id === source.provider && x.active)
        )
          throw Error("اختر مصدرًا مفعّلًا تابعًا لشركة الفئة");
      }
      return validate.call(this, meta, rows);
    };
    o.components["order-sources"] = {
      data() {
        return {
          query: "",
          draft: { name: "", provider: "" },
          editing: false,
          error: "",
        };
      },
      computed: {
        vm() {
          return this.$root;
        },
        rows() {
          const q = this.query.trim().toLocaleLowerCase();
          return (this.vm.s.orderSources || []).filter((r) =>
            (r.name + " " + this.vm.nameOf("providers", r.provider))
              .toLocaleLowerCase()
              .includes(q),
          );
        },
      },
      methods: {
        edit(row) {
          this.draft = row ? Masal.clone(row) : { name: "", provider: "" };
          this.error = "";
          this.editing = true;
        },
        save() {
          try {
            this.vm.engine.saveOrderSource(this.draft);
            this.vm.persist();
            this.editing = false;
            this.vm.notify("تم حفظ المصدر");
          } catch (e) {
            this.error = e.message;
          }
        },
      },
    };
    const f = o.components["cash-order-form"],
      fd = f.data,
      next = f.methods.next;
    f.data = function () {
      const d = fd.call(this);
      d.draft.sourceId = "";
      return d;
    };
    f.computed.orderSources = function () {
      const p = this.vm.s.products.find((p) => p.id === this.draft.product);
      return (this.vm.s.orderSources || []).filter(
        (r) =>
          r.active &&
          r.provider === p?.provider &&
          this.vm.s.providers.some((c) => c.id === r.provider && c.active),
      );
    };
    f.watch = {
      ...f.watch,
      "draft.product": function () {
        this.draft.sourceId = "";
        this.draft.supplier = "";
      },
    };
    f.methods.next = function () {
      const source = this.orderSources.find(
        (r) => r.id === this.draft.sourceId,
      );
      if (!source) {
        this.error = "اختر المصدر التابع لشركة الفئة";
        return;
      }
      this.draft.supplier = source.name;
      this.draft.sourceCompany = this.vm.nameOf("providers", source.provider);
      return next.call(this);
    };
  }
  root.MasalOrderSources = { install };
})(globalThis);

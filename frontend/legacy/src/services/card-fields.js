(function (root) {
  "use strict";
  const catalog = [
    { key: "pin", label: "رمز الشحن / التفعيل — PIN Code", fixed: true },
    { key: "expiry", label: "تاريخ الانتهاء — Expiry Date", fixed: true },
    { key: "serial", label: "الرقم التسلسلي — Serial Number" },
    { key: "cvc", label: "رمز التحقق — CVC / CVV" },
    { key: "reference", label: "الرقم المرجعي — Reference" },
  ];
  function policy(product) {
    const old = String(product.fields || "")
      .split(",")
      .map((s) => s.trim());
    return Object.fromEntries(
      catalog.map((f) => [
        f.key,
        f.fixed
          ? "required"
          : (
                product.fieldPolicy?.[f.key]
                  ? product.fieldPolicy[f.key] !== "unused"
                  : old.includes(f.key)
              )
            ? "required"
            : "unused",
      ]),
    );
  }
  function normalize(product) {
    const p = policy(product);
    for (const value of Object.values(p))
      if (!["required", "unused"].includes(value))
        throw Error("اختيار حالة حقل غير صالح");
    product.fieldPolicy = p;
    product.fields = catalog
      .filter((f) => p[f.key] !== "unused")
      .map((f) => f.key)
      .join(",");
    return product;
  }
  function install(o) {
    const open = o.methods.openEdit,
      save = o.methods.saveEntity;
    Object.assign(o.methods, {
      openEdit(row) {
        open.call(this, row);
        if (this.page === "products")
          this.editForm.fieldPolicy = policy(this.editForm);
      },
      saveEntity() {
        if (this.page === "products") {
          try {
            normalize(this.editForm);
          } catch (e) {
            this.notify(e.message, true);
            return;
          }
        }
        return save.call(this);
      },
      downloadImportTemplate() {
        this.run(() => {
          this.engine.requirePermission("import.template");
          const p = this.s.products.find((p) => p.id === this.imp.product);
          if (!p) throw Error("اختر الفئة قبل تنزيل القالب");
          const cfg = policy(p),
            example = {
              pin: "DEMO-NOT-VALID-NEW",
              expiry: "2027-12-31",
              serial: "DEMO-SERIAL-NEW",
              cvc: "DEMO",
              reference: "SAMPLE",
            };
          download(
            "masal-cards-template.csv",
            csv([
              Object.fromEntries(
                catalog
                  .filter((f) => cfg[f.key] !== "unused")
                  .map((f) => [f.key, example[f.key]]),
              ),
            ]),
            "text/csv;charset=utf-8",
          );
        });
      },
    });
    o.computed.cardFieldOptions = () => catalog;
  }
  root.MasalCardFields = { catalog, policy, normalize, install };
})(globalThis);

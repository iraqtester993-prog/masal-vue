(function (root) {
  function install(o) {
    o.computed.createLabel = function () {
      return this.page === "agents"
        ? this.managementRole === "owner"
          ? "إضافة وكيل رئيسي"
          : "إضافة وكيل فرعي"
        : {
            pos: "إضافة نقطة بيع",
            users: "إضافة موظف",
            products: "إضافة فئة",
            providers: "إضافة مزود",
          }[this.page] || "إضافة";
    };
    const open = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      const result = open.call(this, row);
      if (this.modal?.kind === "edit")
        this.modal.title = row ? "تعديل البيانات" : this.createLabel;
      return result;
    };
    o.methods.newSubAgent = function () {
      this.openEdit();
      if (this.modal?.kind === "edit") {
        this.editForm.type = "فرعي";
        this.modal.title = "إضافة وكيل فرعي";
      }
    };
    const actions = {
      computed: {
        vm() {
          return this.$root;
        },
      },
    };
    o.components["page-actions"] = actions;
    for (const name of ["operations-panel", "workflow-panel"]) {
      o.components[name].components ??= {};
      o.components[name].components["page-actions"] = actions;
    }
  }
  root.MasalVisualCleanup = { install };
})(globalThis);

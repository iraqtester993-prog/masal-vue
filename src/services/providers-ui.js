(function (root) {
  "use strict";
  const table = {
    computed: {
      vm() {
        return this.$root;
      },
      activeFilters() {
        return this.vm.catalogFiltersActive("providers");
      },
      rows() {
        return this.vm.filteredRows;
      },
    },
  };
  const editor = {
    computed: {
      vm() {
        return this.$root;
      },
    },
  };
  const preview = {
    props: ["provider"],
    computed: {
      vm() {
        return this.$root;
      },
    },
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      return { ...data.call(this), providerLogoBusy: false };
    };
    editor.components = {
      "image-attachment": o.components["image-attachment"],
    };
    Object.assign(o.components, {
      "provider-table": table,
      "provider-editor": editor,
      "provider-preview": preview,
    });
    const open = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      open.call(this, row);
      if (this.page === "providers" && this.modal?.kind === "edit") {
        this.providerLogoBusy = false;
        this.editForm.logo ??= "";
        this.modal.title = row ? "تعديل الشركة" : "إضافة شركة";
      }
    };
    const save = o.methods.saveEntity;
    o.methods.saveEntity = function () {
      if (this.page === "providers") {
        if (this.providerLogoBusy) return;
        try {
          const old = this.s.providers.find((p) => p.id === this.editForm.id);
          if (
            ["logo"].some((k) => (this.editForm[k] || "") !== (old?.[k] || ""))
          )
            this.engine.requirePermission("providers.images");
        } catch (e) {
          this.notify(e.message, true);
          return;
        }
      }
      return save.call(this);
    };
    o.methods.previewProvider = function (p) {
      this.run(() => {
        this.engine.requirePermission("providers.view");
        this.modal = {
          kind: "providerPreview",
          title: "معاينة الشركة",
          provider: Masal.clone(p),
        };
      });
    };
  }
  root.MasalProvidersUI = { install };
})(globalThis);

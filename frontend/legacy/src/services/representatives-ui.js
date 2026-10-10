(function (root) {
  "use strict";
  function readFile(file) {
    return new Promise((resolve, reject) => {
      if (
        !file ||
        !/^image\/(png|jpeg|webp)$/.test(file.type) ||
        file.size > 700000
      )
        return reject(
          Error("اختر صورة PNG أو JPG أو WEBP بحجم أقل من 700 كيلوبايت"),
        );
      const r = new FileReader();
      r.onload = () => resolve(r.result);
      r.onerror = () => reject(Error("تعذر قراءة الصورة"));
      r.readAsDataURL(file);
    });
  }
  const picker = {
    data() {
      return { query: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      items() {
        const agent = this.vm.editForm.agent,
          ids = agent ? this.vm.engine.descendants(agent) : [];
        return this.vm.s.representatives.filter(
          (r) =>
            r.active !== false &&
            ids.includes(r.agent) &&
            (!this.query ||
              [r.name, r.phone, r.address]
                .join(" ")
                .toLowerCase()
                .includes(this.query.toLowerCase())),
        );
      },
      selectedItems() {
        return this.vm.s.representatives.filter((r) =>
          (this.vm.editForm.representativeIds || []).includes(r.id),
        );
      },
    },
    methods: {
      toggle(id) {
        const current = Array.isArray(this.vm.editForm.representativeIds)
          ? this.vm.editForm.representativeIds
          : [];
        this.vm.editForm.representativeIds = current.includes(id)
          ? current.filter((x) => x !== id)
          : [...current, id];
      },
    },
  };
  const docs = {
    data() {
      return { busy: false, error: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      rows() {
        return this.vm.editForm.documents || [];
      },
    },
    methods: {
      async add(type, e) {
        const files = [...e.target.files];
        e.target.value = "";
        if (!files.length) return;
        this.busy = true;
        this.error = "";
        try {
          const images = await Promise.all(files.map(readFile)),
            row = this.rows.find((x) => x.type === type);
          if (row) row.images.push(...images);
          else (this.vm.editForm.documents ??= []).push({ type, images });
        } catch (x) {
          this.error = x.message;
        } finally {
          this.busy = false;
        }
      },
      remove(type, i) {
        const row = this.rows.find((x) => x.type === type);
        if (row) row.images.splice(i, 1);
      },
    },
  };
  const repPhotos = {
    data() {
      return { busy: false, error: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      images() {
        return this.vm.editForm.photoImages || [];
      },
    },
    methods: {
      async add(e) {
        const files = [...e.target.files];
        e.target.value = "";
        if (!files.length) return;
        this.busy = true;
        this.error = "";
        try {
          (this.vm.editForm.photoImages ??= []).push(
            ...(await Promise.all(files.map(readFile))),
          );
        } catch (x) {
          this.error = x.message;
        } finally {
          this.busy = false;
        }
      },
      remove(i) {
        this.vm.editForm.photoImages.splice(i, 1);
      },
    },
  };
  const personalPhoto = {
    data() {
      return { busy: false, error: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      image() {
        return this.vm.editForm.personalImage || "";
      },
    },
    methods: {
      async add(e) {
        const file = e.target.files?.[0];
        e.target.value = "";
        if (!file) return;
        this.busy = true;
        this.error = "";
        try {
          this.vm.editForm.personalImage = await readFile(file);
        } catch (x) {
          this.error = x.message;
        } finally {
          this.busy = false;
        }
      },
      remove() {
        this.vm.editForm.personalImage = "";
      },
    },
  };
  const docsViewer = {
    props: { embedded: Boolean },
    computed: {
      vm() {
        return this.$root;
      },
      pos() {
        return this.vm.modal?.pos || {};
      },
      documents() {
        return (this.pos.documents || []).filter(
          (d) => Array.isArray(d.images) && d.images.length,
        );
      },
    },
  };
  const imagePreview = {
    computed: {
      vm() {
        return this.$root;
      },
    },
  };
  const serialPolicy = {
    computed: {
      vm() {
        return this.$root;
      },
      enabled() {
        return !!this.vm.editForm.serialBinding;
      },
    },
    methods: {
      toggle() {
        this.vm.editForm.serialBinding = !this.enabled;
      },
    },
  };
  function install(o) {
    o.components ??= {};
    o.components["representative-picker"] = picker;
    o.components["pos-documents-editor"] = docs;
    o.components["representative-photos"] = repPhotos;
    o.components["pos-personal-photo"] = personalPhoto;
    o.components["pos-documents-viewer"] = docsViewer;
    o.components["document-image-preview"] = imagePreview;
    o.components["pos-serial-policy"] = serialPolicy;
    const data = o.data;
    const oldOpen = o.methods.openEdit,
      oldOptions = o.methods.optionsFor,
      oldSave = o.methods.saveEntity,
      oldFiltered = o.computed.filteredRows;
    o.data = function () {
      const d = data.call(this);
      d.documentPreview = null;
      d.s.representatives ??= [];
      d.s.posTypes ??= [];
      d.s.pos.forEach((p) => {
        p.representativeIds ??= [];
        p.documents ??= [];
        p.personalImage ??= "";
        p.serialBinding = !!p.serialBinding;
      });
      return d;
    };
    o.computed.filteredRows = function () {
      const rows = oldFiltered.call(this);
      if (this.page === "representatives" && this.actor.role !== "owner")
        return rows.filter((r) => this.engine.allowed(r.agent));
      return rows;
    };
    o.methods.optionsFor = function (f) {
      if (f.options === "posTypes")
        return this.s.posTypes
          .filter((t) => t.active || t.id === this.editForm.posTypeId)
          .map((t) => ({ value: t.id, label: t.name }));
      return oldOptions.call(this, f);
    };
    o.methods.openEdit = function (row) {
      const result = oldOpen.call(this, row);
      if (this.page === "pos") {
        this.editForm.representativeIds = Array.isArray(
          this.editForm.representativeIds,
        )
          ? this.editForm.representativeIds
          : [];
        this.editForm.documents = Array.isArray(this.editForm.documents)
          ? this.editForm.documents
          : [];
        this.editForm.personalImage = this.editForm.personalImage || "";
        this.editForm.serialBinding = !!this.editForm.serialBinding;
      }
      if (this.page === "representatives") {
        this.editForm.photoImages = Array.isArray(this.editForm.photoImages)
          ? this.editForm.photoImages
          : [];
        if (!row && this.actor.role !== "owner")
          this.editForm.agent = this.actor.agent;
      }
      return result;
    };
    o.methods.previewDocumentImage = function (image, title) {
      if (image) this.documentPreview = { image, title: title || "الصورة" };
    };
    o.methods.closeDocumentPreview = function () {
      this.documentPreview = null;
    };
    o.methods.saveEntity = function () {
      if (this.page === "representatives") {
        const v = this.editForm;
        if (this.actor.role !== "owner") v.agent = this.actor.agent;
        if (!v.agent || !this.engine.allowed(v.agent))
          return this.notify("اختر وكيلاً ضمن نطاقك", true);
        if ((v.phone || "").length > 40)
          return this.notify("رقم الهاتف طويل جدًا", true);
        v.photoImages = Array.isArray(v.photoImages) ? v.photoImages : [];
      }
      if (this.page === "pos") {
        if (
          this.editForm.serialBinding &&
          !String(this.editForm.serial || "").trim()
        )
          return this.notify(
            "أدخل الرقم التسلسلي قبل تفعيل مطابقة الجهاز",
            true,
          );
        this.editForm.serialBinding = !!this.editForm.serialBinding;
        this.editForm.representativeIds = [
          ...new Set(this.editForm.representativeIds || []),
        ].filter((id) =>
          this.s.representatives.some(
            (r) =>
              r.id === id &&
              r.active !== false &&
              this.engine.descendants(this.editForm.agent).includes(r.agent),
          ),
        );
        this.editForm.documents = (this.editForm.documents || []).filter(
          (d) => d && Array.isArray(d.images) && d.images.length,
        );
        this.editForm.personalImage = String(this.editForm.personalImage || "");
      }
      return oldSave.call(this);
    };
  }
  root.MasalRepresentativesUI = { install };
})(globalThis);

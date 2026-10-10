(function (root) {
  "use strict";
  const governorates = [
    "بغداد",
    "البصرة",
    "نينوى",
    "أربيل",
    "السليمانية",
    "دهوك",
    "حلبجة",
    "كركوك",
    "ديالى",
    "الأنبار",
    "صلاح الدين",
    "بابل",
    "كربلاء",
    "النجف",
    "واسط",
    "ميسان",
    "ذي قار",
    "المثنى",
    "القادسية",
  ];
  const fields = [
    ["importCodes", "معرّفات الفئة في ملفات الطلبيات"],
    ["provider", "الشركة"],
    ["kind", "النوع"],
    ["face", "القيمة الاسمية"],
    ["currency", "عملة القيمة الاسمية"],
    ["min", "أقل سعر بيع مسموح • د.ع"],
    ["dailyQty", "حد الكمية اليومي"],
    ["dailyAmount", "الحد المالي اليومي • د.ع"],
    ["order", "الترتيب"],
    ["receiptLanguage", "لغة الوصل"],
    ["receiptWidth", "عرض الوصل"],
    ["receiptHeader", "النص أعلى البطاقة"],
    ["receiptFooter", "النص أسفل البطاقة"],
    ["fields", "بيانات البطاقة"],
    ["allowedCities", "المحافظات المسموحة"],
  ];
  function value(vm, p, key) {
    if (key === "importCodes")
      return (
        MasalOrderParser.codes([
          p.orderIdentifier,
          ...MasalOrderParser.codes(p.importCodes),
        ]).join(", ") || "—"
      );
    if (key === "provider") return vm.nameOf("providers", p.provider);
    if (key === "allowedAgents")
      return p[key]?.length
        ? p[key].map((id) => vm.nameOf("agents", id)).join("، ")
        : "الكل";
    if (key === "allowedCities") return p[key]?.join("، ") || "الكل";
    if (key === "receiptLanguage") return p[key] || "لغة الوكيل";
    if (key === "receiptWidth") return (p[key] || 80) + " mm";
    if (key === "fields")
      return vm.cardFieldOptions
        .filter((f) =>
          p.fieldPolicy
            ? p.fieldPolicy[f.key] === "required"
            : (p.fields || "").split(",").includes(f.key),
        )
        .map((f) => f.label)
        .join("، ");
    return p[key] ?? "—";
  }
  const columns = [
    ["importCodes", "معرّفات الفئة في ملفات الطلبيات"],
    ["image", "الصورة"],
    ["name", "الفئة"],
    ["kind", "النوع"],
    ["provider", "الشركة"],
    ["face", "القيمة الاسمية"],
    ["currency", "عملة القيمة الاسمية"],
    ["min", "أقل سعر بيع مسموح"],
    ["dailyQty", "حد الكمية اليومي"],
    ["dailyAmount", "الحد المالي اليومي"],
    ["field_pin", "رمز الشحن / التفعيل"],
    ["field_expiry", "تاريخ الانتهاء"],
    ["field_serial", "الرقم التسلسلي"],
    ["field_cvc", "رمز التحقق"],
    ["field_reference", "الرقم المرجعي"],
    ["allowedCities", "المحافظات المسموحة"],
    ["receiptWidth", "عرض الوصل"],
    ["receiptHeader", "النص أعلى البطاقة"],
    ["receiptFooter", "النص أسفل البطاقة"],
    ["order", "الترتيب"],
    ["active", "الحالة"],
    ["actions", "الإجراءات"],
  ];
  const columnGroups = {
    limits: ["min", "dailyQty", "dailyAmount"],
    fields: [
      "field_pin",
      "field_expiry",
      "field_serial",
      "field_cvc",
      "field_reference",
    ],
    scope: ["allowedCities"],
    receipt: ["receiptWidth", "receiptHeader", "receiptFooter"],
  };
  const table = {
    data() {
      return { columns, hidden: [], columnsOpen: false, columnSearch: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      visibleColumns() {
        return this.columns.filter((c) => !this.hidden.includes(c[0]));
      },
      activeFilters() {
        return this.vm.catalogFiltersActive("products");
      },
      rows() {
        return this.vm.filteredRows;
      },
    },
    mounted() {
      this.loadColumns();
      this.outsideColumns = (e) => {
        if (this.columnsOpen && !this.$refs.columnsMenu?.contains(e.target))
          this.columnsOpen = false;
      };
      document.addEventListener("pointerdown", this.outsideColumns);
    },
    beforeUnmount() {
      document.removeEventListener("pointerdown", this.outsideColumns);
    },
    watch: {
      "vm.currentUser"() {
        this.loadColumns();
      },
    },
    methods: {
      value(p, k) {
        if (["dailyQty", "dailyAmount"].includes(k) && !(Number(p[k]) > 0))
          return "—";
        if (k.startsWith("field_"))
          return MasalCardFields.policy(p)[k.slice(6)] === "required"
            ? "مطلوب"
            : "غير مستخدم";
        if (["face", "min", "dailyAmount", "limit", "dailyQty"].includes(k))
          return (
            this.vm.money(p[k]) +
            (["min", "dailyAmount"].includes(k)
              ? " د.ع"
              : ["limit", "dailyQty"].includes(k)
                ? " " + this.vm.tr("بطاقة")
                : "")
          );
        return value(this.vm, p, k);
      },
      show(k) {
        return !this.hidden.includes(k);
      },
      loadColumns() {
        this.columnsOpen = false;
        this.columnSearch = "";
        try {
          const v = JSON.parse(
            localStorage.getItem(
              "masal-category-columns-" + this.vm.currentUser,
            ) || "[]",
          );
          this.hidden = Array.isArray(v)
            ? v
                .flatMap((k) => columnGroups[k] || [k])
                .filter(
                  (k) =>
                    columns.some((c) => c[0] === k) &&
                    !["name", "actions"].includes(k),
                )
            : [];
        } catch {
          this.hidden = [];
        }
      },
      closeColumns() {
        this.columnsOpen = false;
        this.$refs.columnsButton?.focus();
      },
      toggleColumn(k) {
        this.hidden = this.show(k)
          ? [...this.hidden, k]
          : this.hidden.filter((x) => x !== k);
        try {
          localStorage.setItem(
            "masal-category-columns-" + this.vm.currentUser,
            JSON.stringify(this.hidden),
          );
        } catch {
          this.vm.notify("تعذر حفظ اختيار الأعمدة", true);
        }
      },
    },
  };
  const editor = {
    data() {
      return { agentSearch: "", citySearch: "" };
    },
    computed: {
      vm() {
        return this.$root;
      },
      p() {
        return this.vm.editForm;
      },
      eligibleAgents() {
        return this.vm.visibleAgents.filter(
          (a) =>
            a.type === "رئيسي" &&
            (!this.vm.categorySpecificCities ||
              this.p.allowedCities.includes(a.city) ||
              this.p.allowedAgents.includes(a.id)) &&
            (!this.agentSearch || a.name.includes(this.agentSearch)),
        );
      },
    },
  };
  const preview = {
    props: ["product"],
    computed: {
      vm() {
        return this.$root;
      },
      fields() {
        return fields;
      },
    },
    methods: {
      value(k) {
        return value(this.vm, this.product, k);
      },
    },
  };
  const limitsEditor = {
    computed: {
      vm() {
        return this.$root;
      },
      p() {
        return this.vm.editForm;
      },
    },
  };
  function install(o) {
    const exportCurrent = o.methods.exportCurrent;
    o.methods.exportCurrent = function () {
      if (this.page !== "products") return exportCurrent.call(this);
      return this.run(() => {
        this.engine.requirePermission("products.export");
        this.engine.requirePermission("data.cost");
        const rows = this.filteredRows.map((p) =>
          Object.fromEntries([
            ["المعرف", p.id],
            ...columns
              .filter(([key]) => key !== "actions")
              .map(([key, label]) => {
                let cell;
                if (key === "active") cell = p.active ? "مفعلة" : "معطلة";
                else if (key.startsWith("field_"))
                  cell =
                    MasalCardFields.policy(p)[key.slice(6)] === "required"
                      ? "مطلوب"
                      : "غير مستخدم";
                else if (key === "image") cell = p.image || "";
                else if (["dailyQty", "dailyAmount"].includes(key))
                  cell = Number(p[key]) > 0 ? p[key] : "";
                else cell = value(this, p, key);
                return [label, cell];
              }),
          ]),
        );
        if (!rows.length) {
          this.notify("لا توجد بيانات للتصدير");
          return;
        }
        download("masal-products.csv", csv(rows), "text/csv;charset=utf-8");
        this.engine.log("تصدير تقرير", "products", null, {
          count: rows.length,
        });
        this.notify("تم تنزيل الفئات بجميع الأعمدة");
      });
    };
    schemas.products.fields = schemas.products.fields.filter(
      (f) => !["dailyQty", "dailyAmount"].includes(f.key),
    );
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        categoryImageBusy: false,
        categorySpecificCities: false,
        categorySpecificAgents: false,
      };
    };
    o.computed.governorates = function () {
      return [
        ...new Set([
          ...governorates,
          ...this.s.agents.map((a) => a.city),
          ...this.s.products.flatMap((p) => p.allowedCities || []),
        ]),
      ].filter(Boolean);
    };
    const options = o.methods.optionsFor;
    o.methods.optionsFor = function (f) {
      if (this.page === "agents" && f.key === "city")
        return this.governorates.map((city) => ({ value: city, label: city }));
      return options.call(this, f);
    };
    const save = o.methods.saveEntity;
    o.methods.saveEntity = function () {
      if (this.page === "products" && this.categoryImageBusy) return;
      if (this.page === "products") {
        try {
          const p = this.editForm;
          if (!["quantity", "amount"].includes(p.dailyLimitType))
            throw Error("اختر نوع الحد اليومي");
          const selected =
              p.dailyLimitType === "quantity" ? "dailyQty" : "dailyAmount",
            n = Number(p[selected]);
          if (
            !Number.isFinite(n) ||
            n <= 0 ||
            (selected === "dailyQty" && !Number.isInteger(n))
          )
            throw Error(
              "أدخل حدًا يوميًا موجبًا، والكمية يجب أن تكون عددًا صحيحًا",
            );
          p[selected] = n;
          p[selected === "dailyQty" ? "dailyAmount" : "dailyQty"] = null;
          if (this.categorySpecificCities && !p.allowedCities.length)
            throw Error("اختر محافظة واحدة على الأقل أو اختر كل المحافظات");
          if (!this.categorySpecificCities) p.allowedCities = [];
          const old = this.s.products.find((x) => x.id === p.id);
          if (
            JSON.stringify(p.extraFields || []) !==
            JSON.stringify(old?.extraFields || [])
          )
            this.engine.requirePermission("products.fields");
          for (const f of p.extraFields || []) {
            f.key = f.key.trim();
            f.label = f.label.trim();
            if (
              !/^[a-z][a-z0-9_]{1,30}$/.test(f.key) ||
              !f.label ||
              [
                "pin",
                "expiry",
                "serial",
                "cvc",
                "reference",
                "constructor",
                "prototype",
                "__proto__",
              ].includes(f.key)
            )
              throw Error("أكمل اسم الحقل واسم عمود استيراد صالح وغير محجوز");
          }
          if (
            new Set((p.extraFields || []).map((f) => f.key)).size !==
            (p.extraFields || []).length
          )
            throw Error("اسم عمود الاستيراد مكرر");
        } catch (e) {
          this.notify(e.message, true);
          return;
        }
      }
      return save.call(this);
    };
    editor.components = {
      "image-attachment": o.components["image-attachment"],
    };
    table.components = { "page-actions": o.components["page-actions"] };
    preview.components = { "meeting-receipt": o.components["meeting-receipt"] };
    Object.assign(o.components, {
      "category-table": table,
      "category-editor": editor,
      "category-daily-limit": limitsEditor,
      "category-preview": preview,
    });
    const open = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      open.call(this, row);
      if (this.page === "products" && this.modal?.kind === "edit") {
        const p = this.editForm;
        p.dailyLimitType ??= !row
          ? "quantity"
          : Number(p.dailyQty) > 0 && !(Number(p.dailyAmount) > 0)
            ? "quantity"
            : Number(p.dailyAmount) > 0 && !(Number(p.dailyQty) > 0)
              ? "amount"
              : "";
        for (const [key, v] of Object.entries({
          image: "",
          receiptImage: "",
          receiptLanguage: "",
          receiptWidth: 80,
          receiptHeader: "",
          receiptFooter: "",
          allowedAgents: [],
          allowedCities: [],
          extraFields: [],
        }))
          p[key] ??= v;
        if (!row) p.dailyQty = Number(p.dailyQty) > 0 ? p.dailyQty : 100;
        this.categorySpecificCities = !!p.allowedCities.length;
        this.categorySpecificAgents = !!p.allowedAgents.length;
        this.modal.title = row ? "تعديل الفئة" : "إضافة فئة";
      }
    };
    o.methods.previewCategory = function (p) {
      this.run(() => {
        this.engine.requirePermission("products.view");
        this.modal = { kind: "categoryPreview", title: p.name, product: p };
      }, "");
    };
  }
  root.MasalCategoriesUI = { install, governorates };
})(globalThis);

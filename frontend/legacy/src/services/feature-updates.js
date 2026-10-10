(function (root) {
  "use strict";
  const norm = (x) =>
    String(x ?? "")
      .normalize("NFKC")
      .trim()
      .toLowerCase();
  function attachment(value) {
    if (!value) return "";
    if (
      typeof value !== "string" ||
      value.length > 940000 ||
      !/^data:image\/(png|jpeg);base64,[a-z0-9+/]+=*$/i.test(value)
    )
      throw Error("الصورة غير صالحة أو أكبر من 700 كيلوبايت");
    const b = atob(value.split(",")[1]);
    if (
      b.length > 700000 ||
      !(b.startsWith("\x89PNG\r\n\x1a\n") || b.startsWith("\xff\xd8\xff"))
    )
      throw Error("اختر صورة PNG أو JPG صالحة");
    return value;
  }
  function detect(file, products, templates) {
    const candidates = new Set(),
      headers = file.rows[0].map(norm),
      column = headers.findIndex((h) =>
        [
          "product",
          "product_id",
          "category",
          "category_id",
          "الفئة",
          "معرف الفئة",
        ].includes(h),
      );
    let unknown = false;
    if (column >= 0) {
      for (const row of file.rows.slice(1)) {
        const value = norm(row[column]);
        if (!value) continue;
        const matches = products.filter((p) =>
          [p.id, p.name].some((x) => norm(x) === value),
        );
        if (matches.length !== 1) unknown = true;
        else candidates.add(matches[0].id);
      }
      if (unknown || candidates.size !== 1)
        return {
          product: "",
          contentConflict: true,
          matchNote: "بيانات الفئة غير موحدة؛ افصل الملفات أو صحح عمود الفئة",
        };
      return { product: [...candidates][0], matchNote: "الفئة من عمود الملف" };
    }
    const filename =
      " " + norm(file.name).replace(/[^\p{L}\p{N}]+/gu, " ") + " ";
    for (const p of products)
      if (
        [p.id, p.name].some((x) => {
          const token = norm(x).replace(/[^\p{L}\p{N}]+/gu, " ");
          return token && filename.includes(" " + token + " ");
        })
      )
        candidates.add(p.id);
    for (const t of templates || [])
      if (
        t.product &&
        t.filenameMatch &&
        products.some((p) => p.id === t.product) &&
        norm(file.name).includes(norm(t.filenameMatch)) &&
        JSON.stringify(t.headers.map(norm)) === JSON.stringify(headers)
      )
        candidates.add(t.product);
    return candidates.size === 1
      ? {
          product: [...candidates][0],
          matchNote: "الفئة من اسم الملف أو القالب",
        }
      : {
          product: "",
          matchNote: candidates.size
            ? "تعارض في القواعد؛ اختر الفئة"
            : "لم تحدد الفئة؛ اخترها للمراجعة",
        };
  }
  const imageField = {
    props: ["modelValue"],
    emits: ["update:modelValue", "busy"],
    data() {
      return { busy: false, sequence: 0 };
    },
    beforeUnmount() {
      this.sequence++;
      if (this.busy) this.$emit("busy", false);
    },
    methods: {
      async choose(e) {
        const file = e.target.files[0];
        e.target.value = "";
        if (!file) return;
        const sequence = ++this.sequence,
          actor = this.$root.currentUser;
        this.busy = true;
        this.$emit("busy", true);
        try {
          if (
            !["image/png", "image/jpeg"].includes(file.type) ||
            file.size > 700000
          )
            throw Error("اختر PNG أو JPG بحجم أقل من 700 كيلوبايت");
          const value = await new Promise((resolve, reject) => {
            const r = new FileReader();
            r.onload = () => resolve(r.result);
            r.onerror = () => reject(Error("تعذرت قراءة الصورة"));
            r.readAsDataURL(file);
          });
          attachment(value);
          await new Promise((resolve, reject) => {
            const i = new Image();
            i.onload = () =>
              i.width && i.height
                ? resolve()
                : reject(Error("الصورة غير صالحة"));
            i.onerror = () => reject(Error("الصورة غير صالحة"));
            i.src = value;
          });
          if (sequence === this.sequence && actor === this.$root.currentUser)
            this.$emit("update:modelValue", value);
        } catch (err) {
          if (sequence === this.sequence) this.$root.notify(err.message, true);
        } finally {
          if (sequence === this.sequence) {
            this.busy = false;
            this.$emit("busy", false);
          }
        }
      },
    },
  };
  function install(o) {
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        attachmentBusy: false,
        priceImportBusy: false,
        dragProduct: "",
      };
    };
    o.components["image-attachment"] = imageField;
    const originalExport = o.methods.exportCurrent;
    o.methods.exportCurrent = function () {
      if (!["agents", "pos"].includes(this.page))
        return originalExport.call(this);
      this.run(() => {
        this.engine.requirePermission(this.page + ".export");
        const rows = this.filteredRows.map((r) => {
          this.engine.require(this.page === "agents" ? r.id : r.agent);
          const u = this.s.users.find((u) =>
            this.page === "pos"
              ? u.pos === r.id && u.role === "pos"
              : u.agent === r.id && ["main", "sub"].includes(u.role),
          );
          const common = {
            المعرف: r.id,
            الاسم: r.name,
            الحالة: r.active ? "مفعل" : "موقوف",
            المحافظة: r.city || "",
            الهاتف: String(r.phone || ""),
            البريد: r.email || u?.email || "",
            "معرف حساب الدخول": u?.id || "",
            "اسم الدخول": u?.username || u?.email || "",
            "حالة حساب الدخول": u ? (u.active ? "مفعل" : "موقوف") : "",
          };
          return this.page === "agents"
            ? {
                ...common,
                المستوى: r.type,
                "معرف الوكيل الأعلى": r.parent || "",
                "الوكيل الأعلى":
                  this.s.agents.find((a) => a.id === r.parent)?.name || "",
                العنوان: r.address || "",
                "معلومات الدعم": r.support || "",
                "لون الهوية": r.color || "",
                "ترويسة الوصل": r.header || "",
                "تذييل الوصل": r.footer || "",
                "عرض الورق": r.width ?? "",
                "حد إعادة الطباعة": r.reprint ?? "",
                "سقف المديونية": this.can("wallets.view")
                  ? (r.creditLimit ?? "")
                  : "",
              }
            : {
                ...common,
                "اسم صاحب المحل": r.owner || "",
                "معرف الوكيل": r.agent,
                الوكيل: this.s.agents.find((a) => a.id === r.agent)?.name || "",
                العنوان: r.address || "",
                "موديل الجهاز": r.model || "",
                "سيريال الجهاز": String(r.serial || ""),
                "إصدار التطبيق": r.version || "",
                "حد إعادة الطباعة": r.reprint ?? "",
                "آخر اتصال": r.lastSeen || "",
              };
        });
        download(
          "masal-" + this.page + ".xlsx",
          MasalExcel.workbook(rows),
          "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        );
        this.engine.log("تصدير بيانات Excel", this.page, null, {
          count: rows.length,
        });
      }, "تم تنزيل ملف Excel");
    };
    o.methods.downloadPrices = function () {
      this.run(() => {
        this.engine.requirePermission("prices.template");
        this.engine.require(this.priceAgent);
        download(
          "masal-prices-v1.xlsx",
          MasalExcel.workbook(
            this.s.products
              .filter((p) =>
                this.engine.agentProductAllowed(this.priceAgent, p.id),
              )
              .map((p) => ({
                version: "1",
                agent: this.priceAgent,
                product: p.id,
                name: p.name,
                price: this.priceFor(this.priceAgent, p.id),
              })),
          ),
          "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        );
      }, "تم تنزيل قالب Excel");
    };
    o.methods.importPrices = async function (e) {
      const f = e.target.files[0];
      e.target.value = "";
      if (!f || this.priceImportBusy) return;
      const actor = this.currentUser,
        agent = this.priceAgent;
      this.priceImportBusy = true;
      try {
        this.engine.requirePermission("prices.import");
        this.engine.require(agent);
        if (!/\.(csv|xlsx)$/i.test(f.name)) throw Error("اختر CSV أو XLSX");
        const sheets = await MasalImportReader.read(f),
          draft = {};
        let count = 0;
        for (const sheet of sheets) {
          if (!sheet.rows.length) continue;
          const headers = sheet.rows[0].map(norm);
          if (
            new Set(headers).size !== headers.length ||
            !["version", "agent", "product", "price"].every((k) =>
              headers.includes(k),
            )
          )
            throw Error("استخدم قالب الأسعار المعتمد");
          for (const row of sheet.rows.slice(1)) {
            const r = Object.fromEntries(
                headers.map((k, i) => [k, String(row[i] ?? "").trim()]),
              ),
              p = this.s.products.find((p) => p.id === r.product);
            if (
              r.version !== "1" ||
              r.agent !== agent ||
              !p ||
              !this.engine.agentProductAllowed(agent, p.id)
            )
              throw Error("إصدار القالب أو الوكيل أو الفئة غير صالح");
            const price = Number(r.price);
            if (
              !r.price ||
              !Number.isFinite(price) ||
              price <= 0 ||
              price < p.min
            )
              throw Error("سعر غير صالح للفئة " + p.name);
            if (Object.hasOwn(draft, p.id)) throw Error("فئة مكررة: " + p.name);
            draft[p.id] = price;
            count++;
          }
        }
        if (!count) throw Error("الملف لا يحتوي أسعارًا");
        if (
          this.currentUser !== actor ||
          this.priceAgent !== agent ||
          this.page !== "prices"
        )
          throw Error("تغير الحساب أو الوكيل أو الصفحة؛ أعد الاستيراد");
        this.engine.requirePermission("prices.import");
        this.engine.require(agent);
        this.priceDraft = draft;
        this.notify("تمت قراءة " + count + " سعر للمعاينة؛ لم يتم التطبيق بعد");
      } catch (error) {
        this.notify(error.message, true);
      } finally {
        this.priceImportBusy = false;
      }
    };
    const filtered = o.computed.filteredRows;
    o.computed.filteredRows = function () {
      const rows = filtered.call(this);
      return this.page === "products"
        ? [...rows].sort(
            (a, b) =>
              (Number(a.order) || 0) - (Number(b.order) || 0) ||
              a.id.localeCompare(b.id),
          )
        : rows;
    };
    o.methods.dropProduct = function (target) {
      const source = this.dragProduct;
      this.dragProduct = "";
      if (!source || source === target) return;
      this.run(() => {
        this.engine.requirePermission("products.order");
        if (this.page !== "products" || this.search)
          throw Error("امسح البحث قبل ترتيب الفئات");
        const rows = [...this.s.products].sort(
            (a, b) =>
              (Number(a.order) || 0) - (Number(b.order) || 0) ||
              a.id.localeCompare(b.id),
          ),
          from = rows.findIndex((p) => p.id === source),
          to = rows.findIndex((p) => p.id === target);
        if (from < 0 || to < 0) throw Error("الفئة غير موجودة");
        const before = rows.map((p) => p.id),
          [item] = rows.splice(from, 1);
        rows.splice(to, 0, item);
        rows.forEach((p, i) => (p.order = i + 1));
        this.engine.log(
          "ترتيب الفئات",
          "products",
          before,
          rows.map((p) => p.id),
        );
        const panel = this.$refs.operations;
        if (panel?.productDraft)
          panel.productDraft.order = this.s.products.find(
            (p) => p.id === panel.product,
          )?.order;
      }, "تم حفظ ترتيب الفئات");
    };
    o.methods.moveProduct = function (id, delta) {
      const rows = this.filteredRows,
        i = rows.findIndex((p) => p.id === id);
      if (rows[i + delta]) {
        this.dragProduct = id;
        this.dropProduct(rows[i + delta].id);
      }
    };
    const panel = o.components["operations-panel"];
    panel.watch.files = {
      deep: true,
      handler() {
        this.preview = [];
      },
    };
    panel.watch.mapping = {
      deep: true,
      handler() {
        this.preview = [];
      },
    };
    const pd = panel.data;
    panel.data = function () {
      return { ...pd.call(this), templateMatch: "", templateProduct: "" };
    };
    panel.methods.saveTemplate = function () {
      this.act(() => {
        this.e.requirePermission("import.template");
        this.e.requirePermission("import.rules");
        if (!this.templateName.trim() || !this.headers.length)
          throw Error("اختر ملفًا واكتب اسم القالب");
        if (
          this.templateMatch.trim() &&
          !this.s.products.some((p) => p.id === this.templateProduct)
        )
          throw Error("اختر فئة قاعدة الملف");
        const old = this.s.importTemplates.find(
          (t) => t.name === this.templateName.trim(),
        );
        const data = {
          name: this.templateName.trim(),
          headers: [...this.headers],
          mapping: Masal.clone(this.mapping),
          filenameMatch: this.templateMatch.trim(),
          product: this.templateMatch.trim() ? this.templateProduct : "",
        };
        if (old) Object.assign(old, data);
        else this.s.importTemplates.push(data);
        this.e.log("حفظ قالب استيراد", data.name, null, {
          product: data.product,
          filenameMatch: data.filenameMatch,
        });
      });
    };
    const load = panel.methods.loadTemplate;
    panel.methods.loadTemplate = function (name) {
      load.call(this, name);
      const t = this.s.importTemplates.find((t) => t.name === name);
      if (t) {
        this.templateName = t.name;
        this.templateMatch = t.filenameMatch || "";
        this.templateProduct = t.product || "";
      }
    };
    const reset = panel.methods.reset;
    panel.methods.reset = function () {
      reset.call(this);
      this.templateMatch = "";
      this.templateProduct = "";
      this.templateName = "";
    };
    const preview = panel.methods.previewFiles;
    panel.methods.previewFiles = function () {
      if (
        this.files.some(
          (f) =>
            f.contentConflict ||
            !this.s.products.some((p) => p.id === f.product),
        )
      ) {
        this.vm.notify(
          "حدد فئة كل ملف وصحح أي تعارض في عمود الفئة قبل المعاينة",
          true,
        );
        return;
      }
      return preview.call(this);
    };
  }
  root.MasalFeatureUpdates = { install, attachment, detect };
})(globalThis);

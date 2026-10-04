(function (root) {
  "use strict";
  const blocks = [
    "company",
    "header",
    "category",
    "image",
    "codes",
    "amount",
    "agent",
    "footer",
  ];
  const labels = {
    company: "صورة الشركة واسمها",
    header: "النص العلوي",
    category: "اسم الفئة",
    image: "صورة الفئة",
    codes: "بيانات البطاقة",
    amount: "المبلغ",
    agent: "صورة الوكيل ونصه",
    footer: "النص السفلي",
  };
  const P = Masal.Engine.prototype;
  function systemUser(u) {
    return (
      u.role === "owner" ||
      (!u.agent && (!u.staffAccount || u.staffAccount === "@system"))
    );
  }
  function legacyOrder(order) {
    const result = [];
    for (const key of order || MasalMeetingRules.blocks) {
      if (key === "footer") result.push("agent");
      result.push(key);
      if (key === "company") result.push("header");
    }
    return [...new Set([...result, ...blocks])].filter((k) =>
      blocks.includes(k),
    );
  }
  function resolve(s, id, agent = {}, override) {
    const p = s.products.find((x) => x.id === id) || {},
      provider = s.providers.find((x) => x.id === p.provider) || {},
      inherited = p.designSource === "provider";
    const legacy =
      p.receiptHeader || p.receiptFooter || p.receiptImage || p.receiptWidth
        ? {
            ...(provider.cardDesign || {}),
            header: p.receiptHeader ?? provider.cardDesign?.header,
            footer: p.receiptFooter ?? provider.cardDesign?.footer,
            width: p.receiptWidth || provider.cardDesign?.width,
            image: p.receiptImage ?? provider.cardDesign?.image,
          }
        : null;
    const d =
        override ||
        (!inherited && (p.cardDesign || legacy)) ||
        provider.cardDesign ||
        {},
      simple = p.simpleCardTemplate;
    const companyImage =
      provider.image ||
      provider.logo ||
      provider.cardDesign?.image ||
      provider.receiptImage ||
      "";
    const image =
      d.image ||
      p.image ||
      (!inherited && p.receiptImage) ||
      provider.cardDesign?.image ||
      provider.receiptImage ||
      provider.image ||
      provider.logo ||
      "";
    let categoryImage = p.image || p.cardDesign?.image || p.receiptImage || "";
    if (!categoryImage && image !== companyImage) categoryImage = image;
    const personal = agent.productCardOptions?.[id];
    return {
      color: override?.color ?? simple?.color ?? d.color ?? "#172b4d",
      width: d.width || (!inherited && p.receiptWidth) || agent.width || 80,
      header:
        override?.header ??
        simple?.header ??
        d.header ??
        (!inherited ? p.receiptHeader : "") ??
        "",
      footer:
        override?.footer ??
        simple?.footer ??
        d.footer ??
        (!inherited ? p.receiptFooter : "") ??
        "",
      image,
      companyImage,
      categoryImage,
      order: d.order || MasalMeetingRules.blocks,
      displayOrder:
        override?.displayOrder || simple?.order || legacyOrder(d.order),
      agentImage:
        agent.productImages?.[id] ||
        (personal?.imageRemoved ? "" : agent.logo) ||
        "",
      agentText:
        personal?.text ??
        [agent.header, agent.footer].filter(Boolean).join("\n"),
      agentColor: personal?.color || agent.color || "#172b4d",
    };
  }
  function text(value) {
    const result = String(value ?? "").trim();
    if (result.length > 1000) throw Error("النص يجب ألا يتجاوز 1000 حرف");
    return result;
  }
  P.saveSimpleCardTemplate = function (productId, draft) {
    this.requirePermission("branding.receipt");
    this.requirePermission("products.edit");
    if (!systemUser(this.actor()))
      throw Error("تعديل ترتيب البطاقة متاح لإدارة النظام فقط");
    const product = this.s.products.find((p) => p.id === productId);
    if (!product) throw Error("اختر الفئة");
    if (
      !Array.isArray(draft.order) ||
      draft.order.length !== blocks.length ||
      new Set(draft.order).size !== blocks.length ||
      draft.order.some((k) => !blocks.includes(k))
    )
      throw Error("ترتيب البطاقة غير صالح");
    const color = String(draft.color ?? resolve(this.s, productId).color);
    if (!/^#[0-9a-f]{6}$/i.test(color)) throw Error("اختر لونًا صالحًا للنص");
    const value = {
        header: text(draft.header),
        footer: text(draft.footer),
        color,
        order: [...draft.order],
      },
      before = product.simpleCardTemplate
        ? Masal.clone(product.simpleCardTemplate)
        : null;
    product.simpleCardTemplate = value;
    this.log("تعديل نصوص وترتيب البطاقة", productId, before, value);
  };
  P.saveAgentCardPersonalization = function (agentId, productId, draft) {
    this.requirePermission("branding.edit", agentId);
    const agent = this.s.agents.find((a) => a.id === agentId);
    if (!agent || this.main(agentId) !== agentId)
      throw Error("اختر الوكيل الرئيسي");
    if (!this.agentProductAllowed(agentId, productId))
      throw Error("الفئة غير مسموحة لهذا الوكيل");
    const image = MasalFeatureUpdates.attachment(draft.image),
      value = {
        text: text(draft.text),
        color: String(draft.color || ""),
        imageRemoved: !image,
      };
    if (!/^#[0-9a-f]{6}$/i.test(value.color))
      throw Error("اختر لونًا صالحًا للخط");
    const before = {
      ...agent.productCardOptions?.[productId],
      hasImage: !!agent.productImages?.[productId],
    };
    agent.productImages = { ...agent.productImages };
    if (image) agent.productImages[productId] = image;
    else delete agent.productImages[productId];
    agent.productCardOptions = {
      ...agent.productCardOptions,
      [productId]: value,
    };
    this.log(
      "تعديل بطاقة الوكيل",
      agentId,
      { product: productId, ...before },
      { product: productId, ...value, hasImage: !!image },
    );
  };
  // Compatibility for images saved by earlier versions; no original artwork is changed.
  P.saveAgentProductImage = function (agentId, productId, image) {
    const agent = this.s.agents.find((a) => a.id === agentId) || {},
      layout = resolve(this.s, productId, agent);
    return this.saveAgentCardPersonalization(agentId, productId, {
      image,
      text: layout.agentText,
      color: layout.agentColor,
    });
  };
  P.inheritCompanyDesign = function (id) {
    this.requirePermission("branding.receipt");
    this.requirePermission("products.edit");
    const p = this.s.products.find((x) => x.id === id);
    if (!p) throw Error("اختر الفئة");
    p.designSource = "provider";
    this.log("استخدام قالب الشركة", id, null, { provider: p.provider });
  };
  const saveDesign = P.saveCardDesign;
  P.saveCardDesign = function (type, id, draft) {
    const result = saveDesign.call(this, type, id, draft);
    if (type === "product") {
      const p = this.s.products.find((x) => x.id === id);
      p.designSource = "product";
      Object.assign(p, {
        receiptWidth: p.cardDesign.width,
        receiptHeader: p.cardDesign.header,
        receiptFooter: p.cardDesign.footer,
        receiptImage: p.cardDesign.image,
      });
    }
    return result;
  };

  function install(o) {
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        brandingTab: "simple",
        brandPreviewProduct: "",
      };
    };
    const receipt = o.components["meeting-receipt"];
    receipt.props.push("agentOverride");
    receipt.computed.agent = function () {
      return (
        this.agentOverride ||
        this.vm.s.agents.find(
          (a) =>
            a.id === this.vm.engine.main(this.tx?.agent || this.vm.brandAgent),
        ) ||
        {}
      );
    };
    receipt.computed.layout = function () {
      return resolve(this.vm.s, this.product.id, this.agent, this.design);
    };
    receipt.computed.image = function () {
      return this.layout.categoryImage;
    };
    const imageField = { ...o.components["image-attachment"] };
    o.components["branding-switcher"] = {};
    o.components["card-designer"] = {
      components: {
        "meeting-receipt": receipt,
        "image-attachment": imageField,
      },
      data: () => ({
        company: "",
        product: "",
        draft: {
          header: "",
          footer: "",
          order: [],
          image: "",
          text: "",
          color: "#172b4d",
        },
        busy: false,
        saved: "",
      }),
      computed: {
        vm() {
          return this.$root;
        },
        isAdmin() {
          return systemUser(this.vm.actor);
        },
        agentId() {
          const u = this.vm.actor;
          return this.isAdmin
            ? ""
            : this.vm.engine.main(
                u.staffAccount && u.staffAccount !== "@system"
                  ? u.staffAccount
                  : u.agent,
              ) || "";
        },
        agent() {
          return this.vm.s.agents.find((a) => a.id === this.agentId) || {};
        },
        available() {
          return this.vm.s.products.filter((p) =>
            this.isAdmin
              ? !this.vm.engine.catalogProductVisible ||
                this.vm.engine.catalogProductVisible(p.id)
              : this.vm.engine.agentProductAllowed(this.agentId, p.id),
          );
        },
        companies() {
          const ids = new Set(this.available.map((p) => p.provider));
          return this.vm.s.providers.filter((p) => ids.has(p.id));
        },
        products() {
          return this.available.filter((p) => p.provider === this.company);
        },
        editable() {
          return this.isAdmin
            ? this.vm.can("branding.receipt") && this.vm.can("products.edit")
            : !!this.agentId && this.vm.can("branding.edit");
        },
        previewDesign() {
          if (!this.isAdmin) return undefined;
          return {
            ...resolve(this.vm.s, this.product),
            header: this.draft.header,
            footer: this.draft.footer,
            color: this.draft.color,
            displayOrder: this.draft.order,
          };
        },
        previewAgent() {
          if (this.isAdmin) return {};
          const images = { ...this.agent.productImages };
          if (this.draft.image) images[this.product] = this.draft.image;
          else delete images[this.product];
          return {
            ...this.agent,
            productImages: images,
            productCardOptions: {
              ...this.agent.productCardOptions,
              [this.product]: {
                text: this.draft.text,
                color: this.draft.color,
                imageRemoved: !this.draft.image,
              },
            },
          };
        },
        dirty() {
          return JSON.stringify(this.draft) !== this.saved;
        },
      },
      mounted() {
        this.reset();
      },
      watch: {
        company() {
          this.choose();
        },
        product() {
          this.load();
        },
        "vm.currentUser"() {
          this.reset();
        },
        "vm.page"(page) {
          if (page === "branding") this.reset();
        },
      },
      methods: {
        reset() {
          this.company = this.companies[0]?.id || "";
          this.choose();
        },
        choose() {
          if (!this.products.some((p) => p.id === this.product))
            this.product = this.products[0]?.id || "";
          this.load();
        },
        load() {
          const layout = resolve(
            this.vm.s,
            this.product,
            this.isAdmin ? {} : this.agent,
          );
          this.busy = false;
          this.draft = {
            header: layout.header,
            footer: layout.footer,
            order: [...layout.displayOrder],
            image: layout.agentImage,
            text: layout.agentText,
            color: this.isAdmin ? layout.color : layout.agentColor,
          };
          this.saved = JSON.stringify(this.draft);
        },
        label(key) {
          return labels[key];
        },
        preview() {
          if (this.busy || !this.product) return;
          this.vm.previewCardDesign(
            this.product,
            this.previewDesign,
            this.previewAgent,
          );
        },
        move(i, step) {
          const next = i + step;
          if (
            !this.isAdmin ||
            !this.editable ||
            next < 0 ||
            next >= this.draft.order.length
          )
            return;
          [this.draft.order[i], this.draft.order[next]] = [
            this.draft.order[next],
            this.draft.order[i],
          ];
        },
        save() {
          if (this.busy || !this.product || !this.editable) return;
          this.vm.run(() => {
            if (this.isAdmin)
              this.vm.engine.saveSimpleCardTemplate(this.product, this.draft);
            else
              this.vm.engine.saveAgentCardPersonalization(
                this.agentId,
                this.product,
                this.draft,
              );
            this.vm.persist();
            this.load();
          }, "تم حفظ تصميم هذه الفئة");
        },
      },
    };
    o.methods.previewCardDesign = function (productId, design, agentDraft) {
      this.run(() => {
        this.engine.requirePermission("branding.view");
        if (!this.s.products.some((p) => p.id === productId))
          throw Error("اختر فئة للمعاينة");
        const a =
          agentDraft ??
          this.s.agents.find((x) => x.id === this.brandAgent) ??
          {};
        this.modal = {
          kind: "cardDesignPreview",
          title: "معاينة البطاقة",
          productId,
          design: Masal.clone(design || resolve(this.s, productId, a)),
          agent: Masal.clone(a),
        };
      });
    };
    const go = o.methods.go;
    o.methods.go = function (page) {
      const result = go.call(this, page);
      if (page === "branding" && this.page === page)
        this.brandingTab = "simple";
      return result;
    };
    const agent = o.computed.receiptAgent;
    o.computed.receiptAgent = function () {
      const a = agent.call(this),
        source =
          this.s.agents.find(
            (x) => x.id === this.engine.main(this.modal?.tx?.agent),
          ) || {};
      return {
        ...a,
        width: resolve(this.s, this.modal?.tx?.product, source).width,
      };
    };
    const save = o.methods.saveEntity;
    o.methods.saveEntity = function () {
      if (this.page === "products") {
        const p = this.editForm,
          old = this.s.products.find((x) => x.id === p.id),
          keys = [
            "receiptHeader",
            "receiptFooter",
            "receiptWidth",
            "receiptImage",
          ];
        if (
          (!old &&
            (p.receiptHeader ||
              p.receiptFooter ||
              p.receiptImage ||
              p.receiptWidth !== 80)) ||
          (old &&
            (p._loadedReceipt
              ? JSON.stringify([
                  p.receiptHeader,
                  p.receiptFooter,
                  p.receiptWidth,
                  p.receiptImage,
                ]) !== p._loadedReceipt
              : keys.some((k) => p[k] !== old[k])))
        ) {
          const d = resolve(this.s, p.id);
          p.cardDesign = {
            ...Masal.clone(d),
            header: p.receiptHeader || "",
            footer: p.receiptFooter || "",
            width: Number(p.receiptWidth) || 80,
            image: p.receiptImage || "",
          };
          p.designSource = "product";
        }
        delete p._loadedReceipt;
      }
      return save.call(this);
    };
    const open = o.methods.openEdit;
    o.methods.openEdit = function (row) {
      open.call(this, row);
      if (this.page === "products" && row) {
        const d = resolve(this.s, row.id);
        Object.assign(this.editForm, {
          receiptHeader: d.header,
          receiptFooter: d.footer,
          receiptWidth: d.width,
          receiptImage: d.image,
        });
        this.editForm._loadedReceipt = JSON.stringify([
          d.header,
          d.footer,
          d.width,
          d.image,
        ]);
      }
    };
    o.computed.brandingProducts = function () {
      return this.s.products.filter(
        (p) =>
          (!this.engine.catalogProductVisible ||
            this.engine.catalogProductVisible(p.id)) &&
          (!this.brandAgent ||
            this.engine.agentProductAllowed(this.brandAgent, p.id)),
      );
    };
  }
  root.MasalCardLayout = { resolve, install };
})(globalThis);

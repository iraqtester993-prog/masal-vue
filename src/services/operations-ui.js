(function (root) {
  "use strict";
  const services = [
    { id: "voucher", name: "البطاقات • رصيد تشغيلي" },
    { id: "topup", name: "Top-up" },
  ];
  const panel = {
    data() {
      return {
        tab: "balances",
        service: "voucher",
        account: "",
        from: "",
        to: "",
        amount: 0,
        reason: "",
        reference: "",
        key: Masal.id("ACTION"),
        exception: "",
        bulkText: "",
        bulkResult: [],
        fundAmounts: {},
        fundSelected: [],
        requestPurpose: "",
        files: [],
        mapping: {},
        importAgent: "",
        loadPrice: 0,
        importCost: 0,
        expenses: 0,
        contract: "إعادة بيع",
        commission: 0,
        preview: [],
        importKey: Masal.id("IMPORT"),
        importDone: false,
        templateName: "",
        defaultExpiry: "2027-12-31",
        product: "",
        productDraft: null,
        device: "",
        deviceDraft: null,
        claimDetails: {},
        requestReasons: {},
        notificationTargets: [],
        integration: "",
        integrationDraft: null,
        apiRecipient: "",
        apiCost: 0,
        apiPrice: 0,
        apiKey: Masal.id("API"),
        collectionForm: { account: "", amount: "", method: "", reference: "" },
        collectionKey: Masal.id("COLLECT"),
        collectionBusy: false,
        backupPassword: "",
        backupBusy: false,
        publicDraft: null,
        replyImage: "",
        bulkBusy: false,
        catalogText: "",
        serviceSKU: "",
        slide: 0,
        serviceResult: "غير معروف",
        includeChildren: true,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      s() {
        return this.vm.s;
      },
      e() {
        return this.vm.engine;
      },
      page() {
        return this.vm.page;
      },
      accounts() {
        return this.vm.accounts;
      },
      agents() {
        return this.vm.visibleAgents;
      },
      services() {
        return [
          ...services,
          ...this.s.providers
            .filter((p) => p.connection === "API")
            .map((p) => ({ id: "api:" + p.id, name: "API • " + p.name })),
        ];
      },
      ledger() {
        return this.s.serviceLedger
          .filter(
            (l) =>
              this.accounts.some((a) => a.id === l.account) &&
              l.service === this.service &&
              (!this.account || l.account === this.account),
          )
          .slice()
          .reverse();
      },
      requests() {
        return this.s.fundingRequests.filter(
          (r) =>
            this.accounts.some((a) => a.id === r.to) ||
            this.agents.some((a) => a.id === r.from),
        );
      },
      transfers() {
        return this.s.fundingTransfers.filter(
          (r) =>
            this.accounts.some((a) => a.id === r.from) &&
            this.accounts.some((a) => a.id === r.to),
        );
      },
      invoices() {
        return this.s.batchInvoices.filter((i) => this.e.allowed(i.agent));
      },
      reservations() {
        return this.s.reservations.filter((r) =>
          this.vm.visiblePOS.some((p) => p.id === r.pos),
        );
      },
      printRequests() {
        return this.s.printOverrides.filter((r) =>
          this.vm.visiblePOS.some((p) => p.id === r.pos),
        );
      },
      orders() {
        return this.s.serviceOrders.filter(
          (o) => this.e.allowed(o.agent) && this.vm.actor.role !== "pos",
        );
      },
      integrations() {
        return this.s.integrations.filter((i) => this.e.allowed(i.agent));
      },
      headers() {
        return this.files[0]?.rows[0] || [];
      },
      hasPanel() {
        return [
          "wallets",
          "inventory",
          "import",
          "products",
          "providers",
          "sell",
          "exceptions",
          "claims",
          "pos",
          "integrations",
          "notifications",
          "monitoring",
          "security",
          "branding",
        ].includes(this.page);
      },
      balanceTotal() {
        return this.accounts.reduce(
          (n, a) => n + this.e.serviceBalance(a.id, this.service),
          0,
        );
      },
      debt() {
        if (!this.account) return 0;
        return (
          this.invoices
            .filter((i) => i.agent === this.account && i.status !== "معكوسة")
            .reduce((n, i) => n + i.amount, 0) -
          this.s.collections
            .filter((c) => c.account === this.account)
            .reduce((n, c) => n + c.amount, 0)
        );
      },
    },
    watch: {
      page() {
        this.reset();
      },
      "vm.currentUser"() {
        this.reset();
      },
      product() {
        this.productDraft = Masal.clone(
          this.s.products.find((p) => p.id === this.product) || null,
        );
        if (this.productDraft) {
          this.productDraft.allowedAgents ??= [];
          this.productDraft.allowedCities ??= [];
        }
      },
      device() {
        this.deviceDraft = Masal.clone(
          this.s.pos.find((p) => p.id === this.device) || null,
        );
      },
      integration() {
        this.integrationDraft = Masal.clone(
          this.s.integrations.find((i) => i.id === this.integration) || null,
        );
      },
    },
    mounted() {
      this.reset();
    },
    methods: {
      can(k) {
        return this.vm.can(k);
      },
      money(v) {
        return this.vm.money(v);
      },
      name(id) {
        return (
          [
            ...this.s.agents,
            ...this.s.pos,
            ...this.s.products,
            ...this.s.providers,
          ].find((x) => x.id === id)?.name || id
        );
      },
      serviceName(id) {
        return this.services.find((s) => s.id === id)?.name || id;
      },
      reset() {
        this.collectionForm = {
          account: "",
          amount: "",
          method: "",
          reference: "",
        };
        this.collectionKey = Masal.id("COLLECT");
        this.collectionBusy = false;
        this.from = this.vm.actor.agent || this.agents[0]?.id || "";
        this.to = this.vm.visiblePOS[0]?.id || "";
        this.importAgent = this.agents.find((a) => !a.parent)?.id || "";
        this.product = this.s.products[0]?.id || "";
        this.productDraft = Masal.clone(
          this.s.products.find((p) => p.id === this.product) || null,
        );
        this.device = this.vm.visiblePOS[0]?.id || "";
        this.deviceDraft = Masal.clone(
          this.s.pos.find((p) => p.id === this.device) || null,
        );
        this.integration = this.integrations[0]?.id || "";
        this.integrationDraft = Masal.clone(this.integrations[0] || null);
        this.publicDraft = Masal.clone(this.s.publicContent);
        this.files = [];
        this.preview = [];
        this.exception = "";
        this.notificationTargets = [];
        this.backupPassword = "";
        this.key = Masal.id("ACTION");
      },
      act(fn, message = "تم حفظ العملية") {
        return this.vm.run(fn, message);
      },
      newKey() {
        this.key = Masal.id("ACTION");
      },
      newApiKey() {
        this.apiKey = Masal.id("API");
      },
      authorize() {
        this.act(() => {
          this.exception = this.e.authorizeFunding(this.from, this.reason).id;
        }, "تم توثيق استثناء واحد للتمويل");
      },
      fund() {
        this.act(() => {
          this.e.fund(
            this.from,
            this.to,
            this.amount,
            this.service,
            this.key,
            this.exception,
          );
          this.exception = "";
          this.newKey();
        });
      },
      request() {
        this.act(() => {
          this.e.requestFunding(
            this.to,
            this.from,
            this.amount,
            this.service,
            this.reason,
            this.key,
          );
          this.newKey();
        });
      },
      credit() {
        this.act(() => {
          this.e.serviceCredit(
            this.to,
            this.service,
            this.amount,
            this.reference,
            this.key,
          );
          this.newKey();
        });
      },
      reverse(t) {
        this.act(() => this.e.reverseFunding(t.id, this.reason));
      },
      process(r) {
        this.act(() => {
          this.e.reserveFunding(
            r.id,
            this.fundAmounts[r.id] ?? r.amount,
            this.exception,
          );
          this.exception = "";
        });
      },
      bulk() {
        this.act(() => {
          const rows = MasalImportReader.delimited(this.bulkText).map((r) => ({
            from: this.from,
            to: r[0],
            amount: Number(r[1]),
          }));
          if (!rows.length) throw Error("أدخل المستفيد والمبلغ في كل سطر");
          this.bulkResult = this.e.bulkFunding(rows, this.service, this.key);
        });
      },
      async collect() {
        if (this.collectionBusy) return;
        this.collectionBusy = true;
        try {
          const f = this.collectionForm;
          this.e.collect(
            f.account,
            f.amount,
            f.method,
            f.reference,
            this.collectionKey,
          );
          this.collectionForm = {
            account: "",
            amount: "",
            method: "",
            reference: "",
          };
          this.collectionKey = Masal.id("COLLECT");
          this.vm.notify("تم تسجيل التحصيل وتفريغ الحقول");
        } catch (error) {
          this.vm.notify(error.message, true);
        } finally {
          await this.$nextTick();
          this.collectionBusy = false;
        }
      },
      async readBulk(event) {
        try {
          this.e.requirePermission("wallets.import");
          const files = await MasalImportReader.read(event.target.files[0]);
          const rows = files.flatMap((f) => f.rows);
          if (rows[0] && !Number.isFinite(Number(rows[0][1]))) rows.shift();
          this.bulkText = rows.map((r) => r.slice(0, 2).join(",")).join("\n");
          this.newKey();
          this.vm.notify("تمت قراءة المستفيدين والمبالغ؛ راجعها قبل التنفيذ");
        } catch (e) {
          this.vm.notify(e.message, true);
        }
        event.target.value = "";
      },
      saveTemplate() {
        this.act(() => {
          this.e.requirePermission("import.template");
          if (!this.templateName.trim() || !this.headers.length)
            throw Error("اختر ملفًا واكتب اسم القالب");
          const old = this.s.importTemplates.find(
            (t) => t.name === this.templateName,
          );
          const data = {
            name: this.templateName,
            headers: [...this.headers],
            mapping: Masal.clone(this.mapping),
          };
          if (old) Object.assign(old, data);
          else this.s.importTemplates.push(data);
        });
      },
      loadTemplate(name) {
        const t = this.s.importTemplates.find((t) => t.name === name);
        if (t) {
          if (t.headers.join("|") !== this.headers.join("|")) {
            this.vm.notify("أعمدة الملف لا تطابق القالب", true);
            return;
          }
          this.mapping = Masal.clone(t.mapping);
        }
      },
      approveRequests() {
        this.act(() => {
          const results = [];
          for (const id of this.fundSelected) {
            const r = this.requests.find((r) => r.id === id);
            if (!r) continue;
            try {
              this.e.processFunding(
                r.id,
                this.fundAmounts[r.id] ?? r.amount,
                this.exception,
              );
              results.push(this.name(r.to) + ": تم");
            } catch (e) {
              results.push(this.name(r.to) + ": " + e.message);
            }
          }
          this.vm.notify(results.join(" • "));
        }, "تمت معالجة الطلبات المحددة");
      },
      async readFiles(event) {
        const actor = this.vm.currentUser;
        try {
          this.e.requirePermission("import.preview");
          this.files = [];
          this.preview = [];
          this.importDone = false;
          this.importKey = Masal.id("IMPORT");
          for (const f of event.target.files) {
            const results = await MasalImportReader.read(f);
            if (actor !== this.vm.currentUser)
              throw Error("تغير الحساب أثناء القراءة");
            for (const result of results) {
              if (result.rows.length < 2)
                throw Error(
                  "الملف «" +
                    f.name +
                    "» لا يحتوي بطاقات. يجب أن يحتوي صف أسماء الأعمدة ثم صفًا لكل بطاقة.",
                );
              this.files.push({
                ...result,
                ...MasalFeatureUpdates.detect(
                  result,
                  this.s.products,
                  this.s.importTemplates,
                ),
                cost: this.importCost,
                loadPrice: this.loadPrice,
                expenses: this.expenses,
              });
            }
          }
          const aliases = {
            pin: ["pin", "code", "رمز", "رمز البطاقة"],
            serial: ["serial", "serialnumber", "سيريال"],
            expiry: ["expiry", "expiration", "تاريخ الانتهاء"],
            cvc: ["cvc", "cvv"],
            reference: ["reference", "مرجع"],
          };
          for (const [field, labels] of Object.entries(aliases))
            this.mapping[field] = this.headers.findIndex((h) =>
              labels.includes(String(h).toLowerCase().trim()),
            );
        } catch (e) {
          this.vm.notify(e.message, true);
        }
        event.target.value = "";
      },
      previewFiles() {
        this.act(() => {
          this.e.requirePermission("import.preview");
          this.preview = this.files.map((file, i) => {
            if (file.rows[0].join("|") !== this.headers.join("|"))
              throw Error("أعمدة الملفات مختلفة؛ استورد كل تنسيق منفصلًا");
            const meta = {
              agent: this.importAgent,
              product: file.product,
              cost: Number(file.cost),
              loadPrice: Number(file.loadPrice),
              expenses: Number(file.expenses) || 0,
              expiry: this.defaultExpiry,
              supplier: file.name,
              city:
                this.s.agents.find((a) => a.id === this.importAgent)?.city ||
                "",
              contract: this.contract,
              commission: this.commission,
              postingKey: this.importKey + "-" + i,
            };
            MasalOperations.positive(meta.loadPrice);
            const rows = file.rows
              .slice(1)
              .map((row) =>
                Object.fromEntries(
                  Object.entries(this.mapping).map(([k, index]) => [
                    k,
                    index < 0 ? "" : row[index] || "",
                  ]),
                ),
              );
            for (const row of rows) {
              const source = file.rows[rows.indexOf(row) + 1];
              for (const field of this.s.products.find(
                (p) => p.id === file.product,
              )?.extraFields || []) {
                const index = file.rows[0].indexOf(field.key);
                row[field.key] = index >= 0 ? source[index] || "" : "";
              }
            }
            return {
              file: file.name,
              meta,
              rows,
              checked: this.e.validateImport(meta, rows),
            };
          });
          if (!this.preview.length) throw Error("اختر ملفات أولًا");
        }, "اكتملت المعاينة؛ راجع الفئات والكميات قبل الاعتماد");
      },
      postFiles() {
        this.act(() => {
          this.e.requirePermission("import.approve");
          if (!this.preview.length || this.importDone)
            throw Error("أعد المعاينة قبل التحميل");
          for (const entry of this.preview) {
            const result = this.e.importBatch(entry.meta, entry.rows);
            entry.batch = result.id;
          }
          this.importDone = true;
        }, "تم تحميل الدفعات وتسجيل الفواتير والرصد التشغيلي");
      },
      saveProduct() {
        this.act(() => {
          this.e.requirePermission("products.edit");
          const p = this.s.products.find((p) => p.id === this.product);
          if (!p) throw Error("اختر فئة");
          for (const [key, fields] of Object.entries({
            "products.order": ["order"],
            "products.images": ["image", "receiptImage"],
            "products.availability": ["allowedAgents", "allowedCities"],
          }))
            if (
              fields.some(
                (k) =>
                  JSON.stringify(p[k] ?? (k.includes("allowed") ? [] : "")) !==
                  JSON.stringify(
                    this.productDraft[k] ?? (k.includes("allowed") ? [] : ""),
                  ),
              )
            )
              this.e.requirePermission(key);
          if (
            !Number.isInteger(Number(this.productDraft.order)) ||
            Number(this.productDraft.order) < 0
          )
            throw Error("الترتيب غير صالح");
          const old = Masal.clone(p);
          for (const k of [
            "image",
            "receiptImage",
            "receiptLanguage",
            "receiptHeader",
            "receiptFooter",
            "receiptWidth",
            "allowedAgents",
            "allowedCities",
            "order",
            "receiptFields",
          ])
            p[k] = Masal.clone(
              this.productDraft[k] ??
                (k.includes("allowed") || k === "receiptFields" ? [] : ""),
            );
          this.e.log("تخصيص فئة وتوفرها", p.id, old, p);
        });
      },
      image(e, target, key) {
        return this.vm.readImage(e, target, key);
      },
      saveDevice() {
        this.act(() => this.e.configureDevice(this.device, this.deviceDraft));
      },
      reserve() {
        this.act(
          () =>
            this.e.reserve(
              this.vm.saleForm.pos,
              this.vm.saleForm.product,
              this.vm.saleForm.quantity,
              Masal.id("RES"),
            ),
          "تم الحجز دون كشف الرموز أو تسجيل بيع",
        );
      },
      issue(r) {
        this.act(() => this.vm.viewReceipt(this.e.issueReservation(r.id)));
      },
      cancel(r) {
        this.act(() => this.e.cancelReservation(r.id));
      },
      requestPrint(t) {
        this.act(
          () =>
            this.e.requestReprint(
              t.id,
              this.requestReasons[t.id] || this.reason,
            ),
          "أرسل طلب إذن إعادة الطباعة لهذه العملية فقط",
        );
      },
      approvePrint(r, ok) {
        this.act(() => this.e.approveReprint(r.id, ok));
      },
      settle(c) {
        this.act(() =>
          this.e.settle(
            c.id,
            this.vm.settlements[c.id],
            this.claimDetails[c.id] || {},
          ),
        );
      },
      testIntegration() {
        this.act(() => {
          this.e.requirePermission("integrations.edit");
          const i = this.s.integrations.find((i) => i.id === this.integration);
          if (!i) throw Error("اختر تكاملًا");
          this.e.require(i.agent);
          i.tested = true;
          i.lastTest = new Date().toISOString();
          i.active = true;
          this.e.log("اختبار تكامل محلي", i.id, null, {
            result: "نجاح المحاكاة",
            environment: i.environment,
          });
        }, "نجحت محاكاة الإعداد؛ لم يتم الاتصال بالمزود");
      },
      syncCatalog() {
        this.act(() => {
          this.e.requirePermission("integrations.edit");
          const i = this.s.integrations.find((i) => i.id === this.integration);
          if (!i?.tested) throw Error("اختبر إعداد التكامل أولًا");
          this.e.require(i.agent);
          const rows = MasalImportReader.delimited(this.catalogText);
          const products = rows.map((r) => {
            if (
              r.length < 4 ||
              !r[0] ||
              !r[1] ||
              !(Number(r[2]) > 0) ||
              !(Number(r[3]) > 0)
            )
              throw Error(
                "أدخل الرمز والاسم وتكلفة الجملة وسعر البيع في كل سطر",
              );
            return {
              code: r[0],
              name: r[1],
              cost: Number(r[2]),
              price: Number(r[3]),
              active: true,
            };
          });
          if (new Set(products.map((p) => p.code)).size !== products.length)
            throw Error("رمز منتج مكرر");
          i.products = products;
          i.syncedAt = new Date().toISOString();
          this.e.log("كتالوج مزود محلي", i.id, null, {
            count: products.length,
          });
        }, "تمت مراجعة الكتالوج المحلي؛ المزامنة المباشرة تتطلب ربط المزود");
      },
      selectSKU() {
        const i = this.s.integrations.find((i) => i.id === this.integration),
          p = i?.products?.find((p) => p.code === this.serviceSKU);
        if (p) {
          this.apiCost = p.cost;
          this.apiPrice = p.price;
          this.apiKey = Masal.id("API");
        }
      },
      rotate() {
        this.act(() => {
          this.e.requirePermission("integrations.rotate");
          const i = this.s.integrations.find((i) => i.id === this.integration);
          if (!i) throw Error("اختر تكاملًا");
          this.e.require(i.agent);
          i.tokenHint = "••••" + crypto.randomUUID().slice(-6);
          i.tokenRevision = (i.tokenRevision || 0) + 1;
          i.tested = false;
          i.active = false;
          this.e.log("تدوير تعريف رمز الربط المحلي", i.id, null, {
            revision: i.tokenRevision,
          });
        }, "تم تغيير تعريف الرمز المحلي؛ إصدار الرمز الحقيقي يتم عند الربط");
      },
      serviceOrder() {
        this.act(() => {
          const i = this.s.integrations.find((i) => i.id === this.integration);
          if (!i) throw Error("اختر تكاملًا");
          this.e.startServiceOrder(
            i.agent,
            i.provider,
            this.service,
            this.apiRecipient,
            this.apiCost,
            this.apiPrice,
            this.apiKey,
          );
        });
      },
      resolve(o, status) {
        this.act(() => this.e.resolveServiceOrder(o.id, status));
      },
      sendGroup() {
        this.act(() => {
          this.e.requirePermission("notifications.send");
          if (!this.notificationTargets.length) throw Error("اختر المستلمين");
          const form = this.vm.notificationForm;
          if (!form.title.trim() || !form.body.trim())
            throw Error("العنوان والنص مطلوبان");
          const targets = new Set();
          for (const account of this.notificationTargets) {
            this.e.accountCheck(account);
            targets.add(account);
            if (
              this.includeChildren &&
              this.s.agents.some((a) => a.id === account)
            ) {
              for (const id of this.e.descendants(account)) {
                this.e.require(id);
                targets.add(id);
                this.s.pos
                  .filter((p) => p.agent === id)
                  .forEach((p) => targets.add(p.id));
              }
            }
          }
          const n = {
            id: Masal.id("NT"),
            title: form.title,
            body: form.body,
            image: form.image,
            targets: [...targets],
            target: "group",
            time: new Date().toISOString(),
            user: this.vm.currentUser,
          };
          this.s.notifications.unshift(n);
          this.e.log("إشعار لمجموعة", n.id, null, {
            targets: n.targets,
            title: n.title,
          });
        });
      },
      savePublic() {
        this.act(() => {
          this.e.requirePermission("company.edit");
          for (const key of ["android", "ios"])
            if (
              this.publicDraft[key] &&
              !/^https:\/\//i.test(this.publicDraft[key])
            )
              throw Error("روابط التطبيقات يجب أن تبدأ بـ https://");
          const old = Masal.clone(this.s.publicContent);
          this.s.publicContent = Masal.clone(this.publicDraft);
          this.e.log(
            "تعديل محتوى المنصة",
            "publicContent",
            old,
            this.s.publicContent,
          );
        });
      },
      async encryptedBackup() {
        if (this.backupBusy) return;
        this.backupBusy = true;
        const actor = this.vm.currentUser;
        try {
          this.e.requirePermission("backup.download");
          if (this.backupPassword.length < 12)
            throw Error("كلمة التشفير 12 حرفًا على الأقل");
          const output = await MasalVault.encrypt(
            JSON.stringify(this.s),
            this.backupPassword,
          );
          if (actor !== this.vm.currentUser) throw Error("تغير المستخدم");
          this.e.requirePermission("backup.download");
          download(
            "masal-backup-" + Masal.day() + ".encrypted.json",
            JSON.stringify(output),
          );
          this.backupPassword = "";
          this.vm.notify("تم تنزيل نسخة مشفرة");
        } catch (e) {
          this.vm.notify(e.message, true);
        } finally {
          this.backupBusy = false;
        }
      },
      async restoreBackup(event) {
        const file = event.target.files[0],
          actor = this.vm.currentUser;
        if (!file) return;
        try {
          this.e.requirePermission("backup.restore");
          if (file.size > 30000000) throw Error("الملف كبير جدًا");
          const content = JSON.parse(await file.text());
          const text = await MasalVault.decrypt(content, this.backupPassword);
          const state = JSON.parse(text);
          validateBackup(state);
          if (actor !== this.vm.currentUser) throw Error("تغير المستخدم");
          this.e.requirePermission("backup.restore");
          this.vm.modal = {
            kind: "confirm",
            title: "استعادة نسخة مشفرة",
            message:
              "سيتم استبدال البيانات الحالية بمحتوى النسخة بعد فك التشفير. هل تريد المتابعة؟",
            action: () => {
              this.e.requirePermission("backup.restore");
              this.vm.s = MasalOperations.initialize(state);
              this.vm.currentUser = state.users.find(
                (u) => u.role === "owner" && u.active,
              ).id;
              this.vm.go("dashboard");
            },
          };
        } catch (e) {
          this.vm.notify("تعذر فتح النسخة: " + e.message, true);
        }
        event.target.value = "";
      },
    },
  };
  const bytesTo64 = (bytes) =>
    btoa(
      Array.from(new Uint8Array(bytes), (v) => String.fromCharCode(v)).join(""),
    );
  const from64 = (str) => Uint8Array.from(atob(str), (x) => x.charCodeAt(0));
  root.MasalVault = {
    async key(password, salt, usage) {
      const material = await crypto.subtle.importKey(
        "raw",
        new TextEncoder().encode(password),
        "PBKDF2",
        false,
        ["deriveKey"],
      );
      return crypto.subtle.deriveKey(
        { name: "PBKDF2", salt, iterations: 210000, hash: "SHA-256" },
        material,
        { name: "AES-GCM", length: 256 },
        false,
        [usage],
      );
    },
    async encrypt(text, password) {
      const salt = crypto.getRandomValues(new Uint8Array(16)),
        iv = crypto.getRandomValues(new Uint8Array(12)),
        key = await this.key(password, salt, "encrypt");
      return {
        format: "masal-backup-encrypted-v1",
        salt: bytesTo64(salt),
        iv: bytesTo64(iv),
        data: bytesTo64(
          await crypto.subtle.encrypt(
            { name: "AES-GCM", iv },
            key,
            new TextEncoder().encode(text),
          ),
        ),
      };
    },
    async decrypt(data, password) {
      if (data.format !== "masal-backup-encrypted-v1")
        throw Error("صيغة غير متوافقة");
      const key = await this.key(password, from64(data.salt), "decrypt");
      return new TextDecoder().decode(
        await crypto.subtle.decrypt(
          { name: "AES-GCM", iv: from64(data.iv) },
          key,
          from64(data.data),
        ),
      );
    },
  };
  root.MasalOperationsUI = {
    install(o) {
      const data = o.data;
      o.data = function () {
        const d = data.call(this);
        MasalOperations.initialize(d.s);
        for (const p of d.s.products) {
          p.allowedAgents ??= [];
          p.allowedCities ??= [];
        }
        return d;
      };
      o.components ??= {};
      o.components["operations-panel"] = panel;
      o.computed.receiptAgent = function () {
        const t = this.modal?.tx,
          p = this.s.products.find((p) => p.id === t?.product) || {},
          provider = this.s.providers.find((v) => v.id === p.provider) || {},
          agent =
            this.s.agents.find((a) => a.id === this.engine.main(t?.agent)) ||
            {};
        return {
          ...agent,
          logo: provider.receiptImage || provider.logo || agent.logo,
          header: p.receiptHeader || agent.header,
          footer: p.receiptFooter || agent.footer,
          width: p.receiptWidth || agent.width,
          receiptLanguage: p.receiptLanguage || this.s.settings.receiptLanguage,
        };
      };
      o.computed.totalWallets = function () {
        return this.accounts.reduce(
          (n, a) => n + this.engine.serviceBalance(a.id),
          0,
        );
      };
      o.computed.visibleNotifications = function () {
        const ids = new Set(this.accounts.map((a) => a.id));
        if (this.actor.agent) {
          let a = this.s.agents.find((a) => a.id === this.actor.agent);
          while (a) {
            ids.add(a.id);
            a = this.s.agents.find((x) => x.id === a.parent);
          }
        }
        return this.s.notifications.filter(
          (n) =>
            this.actor.role === "owner" ||
            n.target === "all" ||
            ids.has(n.target) ||
            n.targets?.some((t) => ids.has(t)),
        );
      };
      const settle = o.methods.settle;
      o.methods.settle = function (c) {
        if (["تعويض", "استبدال"].includes(this.settlements[c.id])) {
          this.notify(
            "أكمل مبلغ التعويض أو الدفعة البديلة في نموذج التسوية أعلى الصفحة",
            true,
          );
          return;
        }
        return settle.call(this, c);
      };

      o.methods.sell = function () {
        this.run(() => {
          this.engine.requirePermission("sell.create");
          this.engine.requirePermission("data.pin");
          const tx = this.engine.sell(
            this.saleForm.pos,
            this.saleForm.product,
            this.saleForm.quantity,
            this.saleKey,
            this.saleForm.retailPrice,
          );
          this.saleKey = Masal.id("SALE");
          this.viewReceipt(tx);
          this.notify(
            "تم الإصدار والخصم التشغيلي مرة واحدة؛ سجل نتيجة التسليم",
          );
        });
      };
    },
  };
})(globalThis);

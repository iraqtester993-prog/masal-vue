(function (root) {
  "use strict";
  // Reset only after a successful mutation callback, never after validation or a caught error.
  const frames = new WeakMap();
  function wire(options, runner, resets, guardOnly = []) {
    const data = options.data;
    options.data = function () {
      return { ...data.call(this), formPending: {} };
    };
    const run = options.methods[runner];
    options.methods[runner] = function (fn, ...args) {
      const frame = frames.get(this);
      return run.call(
        this,
        () => {
          const result = fn();
          if (frame) frame.succeeded = true;
          return result;
        },
        ...args,
      );
    };
    for (const name of new Set([...Object.keys(resets), ...guardOnly])) {
      const original = options.methods[name];
      if (!original) continue;
      options.methods[name] = function (...args) {
        if (this.formPending[name]) return;
        this.formPending[name] = true;
        const previous = frames.get(this),
          frame = { succeeded: false };
        frames.set(this, frame);
        const finish = () => {
          if (frames.get(this) === frame) {
            if (previous) frames.set(this, previous);
            else frames.delete(this);
          }
          this.$nextTick(() => {
            this.formPending[name] = false;
          });
        };
        try {
          const result = original.apply(this, args);
          if (result?.then) {
            return result.finally(finish);
          }
          if (frame.succeeded && resets[name]) resets[name].apply(this, args);
          finish();
          return result;
        } catch (error) {
          finish();
          throw error;
        }
      };
    }
  }
  function saleReset(vm) {
    vm.saleForm = {
      pos: vm.actor.role === "pos" ? vm.actor.pos : "",
      product: "",
      quantity: "",
      retailPrice: "",
    };
    vm.saleKey = Masal.id("SALE");
  }
  function fundingReset() {
    this.from = "";
    this.to = "";
    this.amount = "";
    this.reason = "";
    this.reference = "";
    this.exception = "";
    this.newKey();
  }
  function install(o) {
    o.methods.printReady = function (t) {
      return (
        t?.status === "Print Requested" ||
        (t?.status === "Reprint Requested" &&
          this.s.printOverrides?.some(
            (r) => r.id === t.reprintApproval && r.status === "معتمد",
          ))
      );
    };

    wire(
      o,
      "run",
      {
        sell() {
          saleReset(this);
        },
        confirmTransfer() {
          this.transferForm = { from: "", to: "", amount: "" };
        },
        transfer() {
          this.transferForm = { from: "", to: "", amount: "" };
        },
        deposit() {
          this.depositForm = { account: "", amount: "", reference: "" };
        },
        nextImport() {
          if (this.importStep === 0) {
            this.imp = {
              agent: "",
              product: "",
              city: "",
              supplier: "",
              expiry: "",
              cost: "",
              loadPrice: "",
              expenses: 0,
            };
            this.importText = "";
            this.importPreview = [];
          }
        },
        createClaim() {
          this.claimForm = { batch: "", reason: "" };
        },
        settle(c) {
          delete this.settlements[c.id];
        },
        sendNotification() {
          this.notificationForm = {
            target: "",
            title: "",
            body: "",
            image: "",
            translations: {
              en: { title: "", body: "" },
              ckb: { title: "", body: "" },
            },
          };
        },
        saveTicket() {
          this.ticketForm = { recipient: "", title: "", description: "" };
        },
        replyTicket() {
          this.reply = "";
        },
        submitPrices() {
          this.priceDraft = {};
        },
        savePermissionProfile() {
          this.permissionReason = "";
        },
        saveNetworkPermissions() {
          this.networkPermissionReason = "";
          this.networkPermissionDraft = {};
          this.networkPermissionInitial = {};
          this.networkPermissionSearch = "";
        },
      },
      [
        "submitReprint",
        "printResult",
        "confirmAction",
        "saveSettings",
        "saveBrand",
        "exportEncrypted",
        "saveIntegration",
        "saveEntity",
        "saveStaff",
      ],
    );
    const close = o.methods.closeModal;
    o.methods.closeModal = function () {
      const old = this.modal;
      const result = close.call(this);
      if (old && !this.modal) {
        if (old.kind === "edit") this.editForm = {};
        if (old.kind === "staff") this.staffForm = {};
      }
      return result;
    };
    const ops = o.components["operations-panel"];
    ops.methods.completeRequest = function (r) {
      this.act(() =>
        this.e.completeFunding(
          this.s.fundingHolds.find(
            (h) => h.request === r.id && h.status === "محجوز",
          ).id,
        ),
      );
    };
    ops.methods.cancelRequestHold = function (r) {
      this.act(() =>
        this.e.cancelFundingHold(
          this.s.fundingHolds.find(
            (h) => h.request === r.id && h.status === "محجوز",
          ).id,
          this.reason,
        ),
      );
    };
    ops.methods.approveRequests = function () {
      if (!this.fundSelected.length) {
        this.vm.notify("اختر طلبًا واحدًا على الأقل", true);
        return;
      }
      const done = [],
        failed = [];
      for (const id of this.fundSelected) {
        const r = this.requests.find((r) => r.id === id);
        if (!r) continue;
        try {
          this.e.processFunding(
            r.id,
            this.fundAmounts[r.id] ?? r.amount,
            this.exception,
          );
          done.push(id);
          delete this.fundAmounts[id];
        } catch (error) {
          failed.push(this.name(r.to) + ": " + error.message);
        }
      }
      this.fundSelected = this.fundSelected.filter((id) => !done.includes(id));
      if (!failed.length) {
        this.exception = "";
        this.reason = "";
      }
      this.vm.notify(
        "تم تنفيذ " +
          done.length +
          " طلب" +
          (failed.length ? " • " + failed.join(" • ") : ""),
        !!failed.length,
      );
    };
    wire(
      ops,
      "act",
      {
        fund: fundingReset,
        request: fundingReset,
        credit: fundingReset,
        reverse() {
          this.reason = "";
        },
        process(r) {
          delete this.fundAmounts[r.id];
          this.exception = "";
          this.reason = "";
        },
        completeRequest(r) {
          delete this.fundAmounts[r.id];
          this.fundSelected = this.fundSelected.filter((id) => id !== r.id);
        },
        cancelRequestHold() {
          this.reason = "";
        },
        saveTemplate() {
          this.templateName = "";
        },
        bulk() {
          if (
            this.bulkResult.length &&
            this.bulkResult.every((r) => r.status === "منفذ")
          ) {
            this.bulkText = "";
            this.from = "";
            this.newKey();
          }
        },
        postFiles() {
          this.files = [];
          this.preview = [];
          this.mapping = {};
          this.importAgent = "";
          this.product = "";
          this.importCost = "";
          this.loadPrice = "";
          this.expenses = 0;
          this.defaultExpiry = "";
          this.templateName = "";
          this.importKey = Masal.id("IMPORT");
        },
        reserve() {
          saleReset(this.vm);
        },
        requestPrint(t) {
          delete this.requestReasons[t.id];
          this.reason = "";
        },
        settle(c) {
          delete this.claimDetails[c.id];
          delete this.vm.settlements[c.id];
        },
        sendGroup() {
          this.notificationTargets = [];
          this.vm.notificationForm = {
            target: "",
            title: "",
            body: "",
            image: "",
            translations: {
              en: { title: "", body: "" },
              ckb: { title: "", body: "" },
            },
          };
        },
        serviceOrder() {
          this.apiRecipient = "";
          this.apiCost = "";
          this.apiPrice = "";
          this.serviceSKU = "";
          this.newApiKey();
        },
        syncCatalog() {
          this.catalogText = "";
        },
      },
      [
        "authorize",
        "approveRequests",
        "saveProduct",
        "saveDevice",
        "approvePrint",
        "issue",
        "cancel",
        "resolve",
        "testIntegration",
        "rotate",
        "savePublic",
      ],
    );
    const flow = o.components["workflow-panel"];
    wire(
      flow,
      "act",
      {
        savePrice() {
          this.price = {
            agent: "",
            product: "",
            city: "",
            price: "",
            rate: 1,
            effective: "",
          };
        },
        setStaff() {
          this.staff = "";
          this.staffAgent = "";
        },
        requestExport() {
          this.batch = "";
          this.reason = "";
        },
        bulkSell() {
          if (
            this.bulkStatus.length &&
            this.bulkStatus.every((r) => r.status === "صادر")
          ) {
            this.bulkQuantity = "";
            this.bulkKey = Masal.id("BULK");
            saleReset(this.vm);
          }
        },
      },
      [
        "saveLimits",
        "saveProvider",
        "saveExtra",
        "setCredit",
        "approveExport",
        "deliver",
        "scan",
        "saveSecurity",
        "resetPassword",
        "loginTest",
        "verifyOTP",
      ],
    );
    const completion = o.components["completion-panel"];
    if (completion) {
      completion.methods.act = function (fn, msg) {
        return this.vm.run(fn, msg);
      };
      completion.methods.addRoute = function () {
        this.act(
          () => this.e.saveAlertRoute(this.route),
          "تم حفظ قاعدة التوجيه",
        );
      };
      wire(completion, "act", {
        addRoute() {
          this.route = {
            severity: "مرتفعة",
            channel: "داخل النظام",
            target: "",
            delay: 0,
          };
        },
      });
    }
  }
  root.MasalFormLifecycle = { install };
})(globalThis);

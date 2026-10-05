(function (root) {
  "use strict";
  const A = root.MasalAccess,
    S = root.MasalStaff,
    M = root.Masal;
  function linked(state, page, id) {
    return state.users.find((u) =>
      page === "pos"
        ? u.role === "pos" && u.pos === id
        : ["main", "sub"].includes(u.role) && u.agent === id,
    );
  }
  function validate(e, page, v, login) {
    if (String(v.notes || "").length > 2000)
      throw Error("الملاحظات بحد أقصى 2000 حرف");
    const actor = { ...e.actor(), role: A.managementRole(e.s, e.actor()) },
      old = e.s[page].find((x) => x.id === v.id);
    if (page === "agents" && !old && A.branchCreationBlocked(e.s, e.actor()))
      throw Error(
        "الفرعي التابع لفرعي يمكنه إنشاء نقاط بيع فقط، ولا يمكنه إنشاء فروع",
      );
    e.requirePermission(page + "." + (old ? "edit" : "create"));
    if (page === "pos") {
      const deviceKeys = [
        "bindingRequired",
        "boundSerial",
        "osVersion",
        "geoPolicy",
        "allowedCity",
        "geoExceptionUntil",
        "installationId",
        "certificateHint",
      ];
      if (
        deviceKeys.some(
          (k) => JSON.stringify(v[k]) !== JSON.stringify(old?.[k]),
        )
      ) {
        e.requirePermission("pos.device");
        e.requirePermission("pos.location");
      }
    }
    if (old?.archivedAt || v.archivedAt || v.archiveId)
      throw Error("الحساب المؤرشف لا يقبل التعديل");
    root.MasalFeatureUpdates.attachment(v.image);
    if (
      JSON.stringify(v.productRules || {}) !==
      JSON.stringify(old?.productRules || {})
    )
      throw Error("تعديل الفئات يتم من نافذة فئات التابع");
    if (
      JSON.stringify(v.networkRules || {}) !==
      JSON.stringify(old?.networkRules || {})
    )
      throw Error("تعديل الصلاحيات يتم من نافذة صلاحيات التابع");
    if (
      !["owner", "main", "sub"].includes(actor.role) &&
      !(old && ["employee", "supervisor"].includes(e.actor().role))
    )
      throw Error(
        "إنشاء وإدارة حسابات الشبكة متاحة لمدير النظام والوكيل المسؤول فقط",
      );
    if (old) e.require(page === "agents" ? old.id : old.agent);
    if (
      ["agents", "pos"].includes(page) &&
      v.city &&
      (!old || old.city !== v.city) &&
      !root.MasalRegions.isActive(e.s, v.city)
    )
      throw Error("اختر محافظة مفعلة");
    if (
      page === "pos" &&
      JSON.stringify(v.allowedProductIds) !==
        JSON.stringify(old?.allowedProductIds)
    )
      throw Error("تعديل الفئات يتم من نافذة فئات التابع");
    if (page === "agents") {
      if (
        JSON.stringify(v.allowedProductIds) !==
          JSON.stringify(old?.allowedProductIds) &&
        e.actor().role !== "owner"
      )
        throw Error("تحديد الفئات خاص بمدير النظام");
      if (v.type === "رئيسي" && e.actor().role === "owner") {
        if (!Array.isArray(v.allowedProductIds) || !v.allowedProductIds.length)
          throw Error("اختر فئة واحدة على الأقل للوكيل");
        if (
          new Set(v.allowedProductIds).size !== v.allowedProductIds.length ||
          v.allowedProductIds.some(
            (id) => !e.s.products.some((p) => p.id === id),
          )
        )
          throw Error("اختيار فئات غير صالح");
      }
      if (!String(v.city || "").trim()) throw Error("اختر محافظة الوكيل");
      if (!["رئيسي", "فرعي"].includes(v.type))
        throw Error("نوع الوكيل غير صالح");
      if (!old && !e.s.settings.registration)
        throw Error("تسجيل الوكلاء موقوف");
      if (old && (old.type !== v.type || old.parent !== v.parent))
        throw Error("لا يمكن تغيير مستوى الوكيل أو تبعيته من التعديل");
      if (v.type === "رئيسي") {
        if (v.parent) throw Error("الوكيل الرئيسي لا يتبع وكيلاً آخر");
        if (!old && actor.role !== "owner")
          throw Error("إنشاء الوكيل الرئيسي خاص بمدير النظام");
      } else {
        const parent = e.s.agents.find((a) => a.id === v.parent);
        if (!parent) throw Error("اختر الوكيل الأعلى");
        e.require(parent.id);
        if (
          !old &&
          ["main", "sub"].includes(actor.role) &&
          v.parent !== actor.agent
        )
          throw Error("الوكيل الفرعي يجب أن يتبع حسابك");
      }
    } else {
      const agent = e.s.agents.find((a) => a.id === v.agent);
      if (!agent) throw Error("اختر الوكيل التابع");
      e.require(agent.id);
      if (!old && actor.role !== "owner" && v.agent !== actor.agent)
        throw Error("نقطة البيع الجديدة يجب أن تتبع حسابك مباشرة");
      if (old && old.agent !== v.agent)
        throw Error("نقل نقطة بين الوكلاء يحتاج تسوية مستقلة");
    }
    const existing = old && linked(e.s, page, old.id);
    const identifier = String(login.email || "")
      .trim()
      .toLowerCase();
    const isEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(identifier),
      isUsername = /^[a-z][a-z0-9._-]{2,79}$/i.test(identifier);
    if (!isEmail && !isUsername)
      throw Error("أدخل بريدًا إلكترونيًا أو اسم مستخدم صحيحًا");
    if (
      e.s.users.some(
        (u) =>
          u.id !== existing?.id &&
          [u.email, u.username].some(
            (v) =>
              String(v || "")
                .trim()
                .toLowerCase() === identifier,
          ),
      )
    )
      throw Error("بريد أو اسم المستخدم مستخدم لحساب آخر");
    if (existing) {
      const current = String(existing.email || existing.username || "")
        .trim()
        .toLowerCase();
      if (identifier !== current && e.actor().role !== "owner")
        throw Error("تغيير بريد أو اسم مستخدم الحساب متاح لمدير النظام فقط");
      if (login.password)
        root.MasalPasswordAdmin.validate(
          e,
          existing.id,
          login.password,
          login.confirmPassword,
        );
      return { existing, identifier, isEmail };
    }
    if (String(login.password || "").length < 8)
      throw Error("كلمة المرور يجب أن تكون 8 أحرف على الأقل");
    return {
      identifier,
      isEmail,
      role: page === "pos" ? "pos" : v.type === "رئيسي" ? "main" : "sub",
    };
  }
  function attach(e, page, entity, checked, credentials) {
    if (checked.existing) {
      const before = {
        email: checked.existing.email || "",
        username: checked.existing.username || "",
      };
      if (checked.isEmail) {
        checked.existing.email = checked.identifier;
        delete checked.existing.username;
      } else {
        checked.existing.username = checked.identifier;
        delete checked.existing.email;
      }
      if (credentials)
        root.MasalPasswordAdmin.apply(e, checked.existing, credentials);
      if (
        before.email !== checked.existing.email ||
        before.username !== checked.existing.username
      )
        e.log("تعديل معرف تسجيل الدخول", checked.existing.id, before, {
          email: checked.existing.email || "",
          username: checked.existing.username || "",
        });
      return checked.existing;
    }
    if (linked(e.s, page, entity.id))
      throw Error("لهذه الجهة حساب مرتبط مسبقًا");
    const user = {
      id: M.id("USER"),
      createdAt: entity.createdAt || new Date().toISOString(),
      name: entity.name,
      email: checked.isEmail ? checked.identifier : "",
      username: checked.isEmail ? "" : checked.identifier,
      role: checked.role,
      active: true,
      agent: page === "agents" ? entity.id : entity.agent,
      pos: page === "pos" ? entity.id : "",
      credentials,
    };
    // Ancestor limits are evaluated live, so later restrictions and restorations reach existing descendants.
    e.s.users.push(user);
    e.log(
      "إنشاء حساب " +
        { main: "وكيل رئيسي", sub: "وكيل فرعي", pos: "نقطة بيع" }[user.role],
      user.id,
      null,
      {
        name: user.name,
        email: user.email,
        role: user.role,
        agent: user.agent,
        pos: user.pos,
      },
    );
    return user;
  }
  function permissionTarget(e, page, id) {
    e.requirePermission("agents.permissions");
    if (
      !["agents", "pos"].includes(page) ||
      !["owner", "main", "sub"].includes(A.managementRole(e.s, e.actor()))
    )
      throw Error("غير مسموح بإدارة صلاحيات هذه الجهة");
    const record = e.s[page].find((x) => x.id === id),
      user = linked(e.s, page, id);
    if (!record || !user) throw Error("أنشئ حساب دخول للجهة أولًا");
    const path = A.networkPath(e.s, user);
    if (!path) throw Error("تبعية غير صالحة");
    if (
      A.managementRole(e.s, e.actor()) !== "owner" &&
      (!path.some((x) => x.id === e.actor().agent) ||
        (page === "agents" && id === e.actor().agent))
    )
      throw Error(
        "يمكن تعديل صلاحيات التابعين فقط، وليس حسابك أو الأعلى أو شبكة أخرى",
      );
    e.require(page === "agents" ? record.id : record.agent);
    return { record, user, path };
  }
  function candidateRules(e, target, key, value) {
    const rules = M.clone(target.record.networkRules || {}),
      authority = e.actor().role === "owner" ? "@owner" : e.actor().agent;
    const rank = (id) =>
        id === "@owner" ? Infinity : target.path.findIndex((x) => x.id === id),
      level = rank(authority);
    // Higher authorities may replace lower decisions; lower authorities cannot erase higher decisions.
    for (const [id, entries] of Object.entries(rules))
      if (rank(id) <= level) {
        delete entries[key];
        if (!Object.keys(entries).length) delete rules[id];
      }
    rules[authority] ??= {};
    rules[authority][key] = value ? "allow" : "deny";
    return rules;
  }
  function targetCan(user, key, state) {
    return ["main", "sub"].includes(user.role)
      ? A.canDelegate(user, key, state)
      : A.can(user, key, state);
  }
  function canEnable(e, page, id, key, draft = {}) {
    try {
      const target = permissionTarget(e, page, id);
      if (
        !A.defaults(target.user.role, key) ||
        (e.actor().role !== "owner" && !A.canDelegate(e.actor(), key, e.s))
      )
        return false;
      const candidate = {
        ...target.record,
        networkRules: M.clone(target.record.networkRules || {}),
      };
      for (const [other, value] of Object.entries(draft))
        candidate.networkRules = candidateRules(
          e,
          { ...target, record: candidate },
          other,
          value,
        );
      candidate.networkRules = candidateRules(
        e,
        { ...target, record: candidate },
        key,
        true,
      );
      const state = {
        ...e.s,
        [page]: e.s[page].map((r) => (r.id === id ? candidate : r)),
      };
      return targetCan({ ...target.user, active: true }, key, state);
    } catch {
      return false;
    }
  }
  function saveNetworkPermissions(e, page, id, changes, reason) {
    const target = permissionTarget(e, page, id);
    if (!String(reason || "").trim()) throw Error("اكتب سبب تعديل الصلاحيات");
    const entries = Object.entries(changes);
    if (!entries.length) throw Error("لم تغير أي صلاحية");
    // Work on a copy so a rejected grant never leaves partial changes.
    const candidate = {
        ...target.record,
        networkRules: M.clone(target.record.networkRules || {}),
      },
      state = {
        ...e.s,
        [page]: e.s[page].map((r) => (r.id === id ? candidate : r)),
      },
      temp = new M.Engine(state, e.user);
    for (const [key, value] of entries) {
      if (
        typeof value !== "boolean" ||
        !A.catalog.some((p) => p.key === key) ||
        !A.defaults(target.user.role, key)
      )
        throw Error("صلاحية غير قابلة للإسناد لهذا الدور");
      if (
        value &&
        e.actor().role !== "owner" &&
        !A.canDelegate(e.actor(), key, e.s)
      )
        throw Error("لا يمكنك منح صلاحية لا تملكها");
      candidate.networkRules = candidateRules(
        temp,
        { ...target, record: candidate },
        key,
        value,
      );
    }
    for (const [key, value] of entries)
      if (value && !targetCan({ ...target.user, active: true }, key, state))
        throw Error("هذه الصلاحية ممنوعة من الأعلى أو تتطلب صلاحية عرض القسم");
    const before = M.clone(target.record.networkRules || {});
    target.record.networkRules = candidate.networkRules;
    e.log("تعديل صلاحيات تابع", id, before, {
      rules: candidate.networkRules,
      changes,
      reason: reason.trim(),
    });
    return target.record;
  }
  function install(o) {
    const data = o.data,
      open = o.methods.openEdit,
      save = o.methods.saveEntity,
      close = o.methods.closeModal,
      options = o.methods.optionsFor;
    o.data = function () {
      return {
        ...data.call(this),
        networkLogin: { email: "", password: "", confirmPassword: "" },
        networkSaving: false,
        networkImageBusy: false,
        networkPermissionDraft: {},
        networkPermissionInitial: {},
        networkPermissionReason: "",
        networkPermissionSearch: "",
      };
    };
    o.methods.canManageNetwork = function (page, id) {
      try {
        permissionTarget(this.engine, page, id);
        return true;
      } catch {
        return false;
      }
    };
    o.methods.openNetworkPermissions = function (page, id) {
      this.run(() => {
        const target = permissionTarget(this.engine, page, id);
        this.networkPermissionDraft = Object.fromEntries(
          A.catalog
            .filter((p) => A.defaults(target.user.role, p.key))
            .map((p) => [
              p.key,
              targetCan({ ...target.user, active: true }, p.key, this.s),
            ]),
        );
        this.networkPermissionInitial = { ...this.networkPermissionDraft };
        this.networkPermissionReason = "";
        this.networkPermissionSearch = "";
        this.modal = {
          kind: "networkPermissions",
          title: "صلاحيات التابع — " + target.record.name,
          page,
          id,
        };
      });
    };
    o.computed.networkPermissionGroups = function () {
      if (this.modal?.kind !== "networkPermissions") return [];
      const q = this.networkPermissionSearch.trim();
      const labels = {
        "agents.create": "إنشاء وكلاء فرعيين",
        "pos.create": "إنشاء نقاط بيع",
        "agents.toggle": "إيقاف وتفعيل الوكلاء التابعين",
        "pos.toggle": "إيقاف وتفعيل نقاط البيع",
        "wallets.transfer": "تمويل التابعين",
        "agents.permissions": "إدارة صلاحيات التابعين",
      };
      const order = ["agents", "pos", "wallets"];
      const groups = [...A.groups].sort(
        (a, b) =>
          (order.includes(a[0]) ? order.indexOf(a[0]) : order.length) -
          (order.includes(b[0]) ? order.indexOf(b[0]) : order.length),
      );
      return groups
        .map(([module, title]) => ({
          module,
          title,
          items: A.catalog
            .filter(
              (p) =>
                p.module === module &&
                p.key in this.networkPermissionDraft &&
                (!q ||
                  ((labels[p.key] || p.label) + " " + p.group).includes(q)),
            )
            .map((p) => ({
              ...p,
              label: labels[p.key] || p.label,
              locked:
                !this.networkPermissionDraft[p.key] &&
                !canEnable(
                  this.engine,
                  this.modal.page,
                  this.modal.id,
                  p.key,
                  this.networkPermissionDraft,
                ),
            })),
        }))
        .filter((g) => g.items.length);
    };
    o.methods.saveNetworkPermissions = function () {
      this.run(() => {
        const changes = Object.fromEntries(
          Object.entries(this.networkPermissionDraft).filter(
            ([key, value]) => value !== this.networkPermissionInitial[key],
          ),
        );
        saveNetworkPermissions(
          this.engine,
          this.modal.page,
          this.modal.id,
          changes,
          this.networkPermissionReason,
        );
        this.closeModal();
        this.persist();
      }, "تم تحديث صلاحيات التابع ضمن شبكته");
    };
    o.methods.startLocalPOSSession = function () {
      this.run(() => {
        this.engine.requirePermission("sell.create");
        const p = this.s.pos.find((p) => p.id === this.saleForm.pos);
        if (!p) throw Error("اختر نقطة البيع");
        this.engine.require(p.agent);
        if (this.actor.role === "pos" && this.actor.pos !== p.id)
          throw Error("لا يمكنك تشغيل جلسة نقطة أخرى");
        if (!p.active) throw Error("نقطة البيع موقوفة؛ راجع الوكيل المسؤول");
        if (!this.s.settings.sales)
          throw Error("البيع موقوف من إعدادات النظام");
        if (!this.s.settings.printing)
          throw Error("الطباعة موقوفة من إعدادات النظام");
        for (const a of this.s.agents)
          if (this.engine.descendants(a.id).includes(p.agent) && !a.active)
            throw Error("أحد وكلاء النقطة موقوف");
        this.engine.checkDevice(p.id);
        if (p.online) return;
        p.online = true;
        p.lastSeen = new Date().toISOString();
        this.engine.log(
          "بدء جلسة جهاز تجريبية",
          p.id,
          { online: false },
          { online: true, mode: "local-demo" },
        );
      }, "بدأت جلسة الجهاز التجريبية؛ يمكنك متابعة الإصدار");
    };
    o.computed.networkLinkedAccount = function () {
      return ["agents", "pos"].includes(this.page) && this.editForm.id
        ? linked(this.s, this.page, this.editForm.id)
        : null;
    };
    o.methods.networkLoginEmail = function (page, id) {
      const account = linked(this.s, page, id);
      return account?.email || account?.username || "غير مسجل";
    };
    o.methods.showNetworkDetails = function (page, id) {
      this.run(() => {
        if (!["agents", "pos"].includes(page)) throw Error("جهة غير صالحة");
        this.engine.requirePermission(page + ".view");
        const r = this.s[page].find((r) => r.id === id);
        if (!r) throw Error("الحساب غير موجود");
        this.engine.require(page === "pos" ? r.agent : r.id);
        this.modal = {
          kind: "inspect",
          title: "تفاصيل " + r.name,
          data: {
            الاسم: r.name,
            "بريد تسجيل الدخول": this.networkLoginEmail(page, id),
            المحافظة: r.city || "—",
            الهاتف: r.phone || "—",
            الحالة: r.active ? "مفعل" : "موقوف",
            الملاحظات: r.notes || "—",
          },
        };
      });
    };
    o.methods.networkFieldLocked = function (f) {
      return (
        ["agents", "pos"].includes(this.page) &&
        ((!!this.editForm.id && ["type", "parent", "agent"].includes(f.key)) ||
          (!this.editForm.id &&
            this.managementRole !== "owner" &&
            ["type", "parent", "agent"].includes(f.key)))
      );
    };
    o.methods.optionsFor = function (f) {
      let out = options.call(this, f);
      if (this.page === "agents" && f.key === "parent" && this.editForm.id)
        out = out.filter(
          (x) => !this.engine.descendants(this.editForm.id).includes(x.value),
        );
      return out;
    };
    o.methods.openEdit = function (row) {
      if (
        !row &&
        this.page === "agents" &&
        A.branchCreationBlocked(this.s, this.actor)
      ) {
        this.notify(
          "الفرعي التابع لفرعي يمكنه إنشاء نقاط بيع فقط، ولا يمكنه إنشاء فروع",
          true,
        );
        return;
      }
      open.call(this, row);
      if (!["agents", "pos"].includes(this.page) || this.modal?.kind !== "edit")
        return;
      const account = row ? linked(this.s, this.page, row.id) : null;
      this.networkLogin = {
        email: account?.email || account?.username || "",
        password: "",
        confirmPassword: "",
      };
      if (!row && this.managementRole !== "owner") {
        if (this.page === "agents") {
          this.editForm.type = "فرعي";
          this.editForm.parent = this.actor.agent;
        } else this.editForm.agent = this.actor.agent;
      }
    };
    o.methods.closeModal = function () {
      if (this.networkSaving) return;
      this.networkLogin = { email: "", password: "" };
      return close.call(this);
    };
    o.methods.saveEntity = async function () {
      if (!["agents", "pos"].includes(this.page)) return save.call(this);
      if (this.networkSaving || this.networkImageBusy) return;
      const page = this.page,
        entity = M.clone(this.editForm),
        login = { ...this.networkLogin },
        actor = this.currentUser,
        state = this.s,
        dialog = this.modal;
      this.networkSaving = true;
      try {
        const first = validate(this.engine, page, entity, login);
        const passwordBefore = JSON.stringify(first.existing?.credentials);
        const credentials = login.password
          ? await S.passwordHash(login.password)
          : null;
        if (
          this.s !== state ||
          this.currentUser !== actor ||
          this.modal !== dialog ||
          this.page !== page ||
          JSON.stringify(entity) !== JSON.stringify(this.editForm)
        )
          throw Error("تغير الحساب أو البيانات أثناء الحفظ؛ أعد المحاولة");
        if (
          first.existing &&
          JSON.stringify(first.existing.credentials) !== passwordBefore
        )
          throw Error("تغيرت كلمة المرور؛ أعد المحاولة");
        const checked = validate(this.engine, page, entity, login),
          ids = new Set(this.s[page].map((x) => x.id));
        // Existing entity validation remains in the original save path. Nothing is created if it fails.
        this.networkSaving = false;
        save.call(this);
        if (this.modal === dialog) return;
        const record = entity.id
          ? this.s[page].find((x) => x.id === entity.id)
          : this.s[page].find((x) => !ids.has(x.id));
        if (record) {
          if (!entity.id) record.createdAt = new Date().toISOString();
          attach(this.engine, page, record, checked, credentials);
          this.persist();
          this.notify(
            checked.existing
              ? "تم حفظ البيانات"
              : "تم حفظ البيانات وإنشاء حساب الدخول المرتبط",
          );
        }
      } catch (error) {
        this.notify(error.message, true);
      } finally {
        login.password = "";
        login.confirmPassword = "";
        this.networkSaving = false;
      }
    };
  }
  root.MasalNetworkAccounts = {
    install,
    validate,
    attach,
    linked,
    permissionTarget,
    canEnable,
    saveNetworkPermissions,
  };
  if (typeof module !== "undefined") module.exports = root.MasalNetworkAccounts;
})(globalThis);

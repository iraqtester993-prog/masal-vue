(function (root) {
  "use strict";
  const A = root.MasalAccess,
    S = root.MasalStaff;
  Masal.Engine.prototype.renameOwnerAccount = function (id, value, email) {
    this.requirePermission("users.edit");
    const user = this.s.users.find((u) => u.id === id);
    if (this.actor().role !== "owner" || user?.role !== "owner")
      throw Error("تعديل حساب مدير النظام متاح لمدير النظام فقط");
    const name = String(value ?? "").trim(),
      mail = String(email ?? user.email ?? "")
        .trim()
        .toLowerCase();
    if (!name || name.length > 120)
      throw Error("أدخل اسمًا من 1 إلى 120 حرفًا");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(mail))
      throw Error("أدخل بريد تسجيل الدخول بصورة صحيحة");
    if (
      this.s.users.some(
        (u) =>
          u.id !== id &&
          String(u.email || "")
            .trim()
            .toLowerCase() === mail,
      )
    )
      throw Error("البريد الإلكتروني مستخدم لحساب آخر");
    const before = { name: user.name, email: user.email || "" };
    user.name = name;
    user.email = mail;
    this.log("تعديل حساب مدير النظام", id, before, { name, email: mail });
    return user;
  };
  const overrides = (keys) =>
    Object.fromEntries(
      A.catalog.map((p) => [p.key, keys.includes(p.key) ? "allow" : "deny"]),
    );
  function install(o) {
    schemas.users.add = "موظف جديد";
    schemas.users.columns = [
      F("name", "اسم الموظف"),
      F("email", "البريد الإلكتروني"),
      F("permissionProfileId", "نوع الصلاحية"),
      F("active", "الحالة"),
    ];
    const base = o.data;
    o.data = function () {
      const data = base.call(this);
      S.initialize(data.s);
      return {
        ...data,
        permissionDetailsOpen: false,
        permissionModuleDialog: false,
        permissionPreview: false,
        permissionLoadedVersion: 0,
        permissionModule: "dashboard",
        profileName: "",
        profileActive: true,
        staffForm: {},
        staffSaving: false,
        staffShowPassword: false,
      };
    };
    const previousRows = o.computed.filteredRows;
    Object.assign(o.computed, {
      managementRole() {
        return A.managementRole(this.s, this.actor);
      },
      permissionUsers() {
        return this.s.users.filter((u) => S.scopedUser(this.engine, u));
      },
      filteredRows() {
        if (this.page !== "users") return previousRows.call(this);
        const q = this.search.trim().toLowerCase();
        return this.permissionUsers.filter(
          (u) =>
            !q ||
            [u.name, u.email, this.profileNameFor(u)]
              .join(" ")
              .toLowerCase()
              .includes(q),
        );
      },
      visibleUsers() {
        return this.permissionUsers;
      },
      accountSummary() {
        return [
          {
            label: "مستخدمو النظام",
            value: this.visibleUsers.length,
            page: "users",
          },
          {
            label: "المستخدمون الموقوفون",
            value: this.visibleUsers.filter((u) => !u.active).length,
            page: "users",
          },
        ].filter((x) => this.can(x.page + ".view"));
      },
      accountDetails() {
        if (
          this.actor.role !== "owner" ||
          this.modal?.kind !== "accountDetails"
        )
          return null;
        const u = this.s.users.find((u) => u.id === this.modal.user);
        if (!u) return null;
        const scope = A.scope(this.s, { ...u, active: true }),
          agents = this.s.agents.filter((a) => scope.includes(a.id)),
          pos = this.s.pos.filter(
            (p) =>
              scope.includes(p.agent) && (u.role !== "pos" || p.id === u.pos),
          );
        return {
          user: u,
          agents,
          pos,
          fields: [
            {
              key: "اسم تسجيل الدخول",
              value: u.username || u.email || u.name || "—",
            },
            ...Object.entries(u)
              .filter(([key]) =>
                [
                  "name",
                  "username",
                  "email",
                  "role",
                  "active",
                  "agent",
                  "pos",
                  "permissionProfileId",
                ].includes(key),
              )
              .map(([key, value]) => ({
                key:
                  {
                    id: "المعرف",
                    name: "اسم الموظف",
                    username: "اسم المستخدم",
                    email: "البريد الإلكتروني",
                    role: "الدور",
                    active: "الحالة",
                    agent: "الوكيل المرتبط",
                    pos: "نقطة البيع المرتبطة",
                    assigned: "الوكلاء المكلف بهم",
                    permissionProfileId: "نوع الصلاحية",
                  }[key] || key,
                value:
                  key === "role"
                    ? this.displayField(u, { key: "role" })
                    : key === "active"
                      ? value
                        ? "مفعل"
                        : "موقوف"
                      : key === "agent"
                        ? this.nameOf("agents", value)
                        : key === "pos"
                          ? this.nameOf("pos", value)
                          : key === "assigned"
                            ? (value || [])
                                .map((id) => this.nameOf("agents", id))
                                .join("، ")
                            : key === "permissionProfileId"
                              ? this.profileNameFor(u)
                              : typeof value === "object"
                                ? JSON.stringify(value)
                                : value,
              })),
          ],
          permissions: A.catalog.filter((p) => A.can(u, p.key, this.s)),
          audit: this.s.audit
            .filter((r) => r.user === u.id)
            .slice()
            .reverse(),
        };
      },
      permissionProfiles() {
        return (this.s.permissionProfiles || []).filter(
          (p) =>
            this.actor.role === "owner" ||
            p.builtin ||
            p.ownerAccount === A.staffAccount(this.actor) ||
            p.id === this.actor.permissionProfileId,
        );
      },
      permissionGroups() {
        const q = this.permissionSearch.trim().toLowerCase();
        return A.groups
          .map(([module, title]) => ({
            module,
            title,
            items: A.catalog.filter(
              (p) =>
                p.module === module &&
                !["integrations", "backup"].includes(module) &&
                (!q ||
                  [p.key, this.tr(p.label), this.tr(p.group)]
                    .join(" ")
                    .toLowerCase()
                    .includes(q)) &&
                (this.permissionMode === "all" ||
                  (this.permissionMode === "sensitive" && p.sensitive) ||
                  (this.permissionMode === "changed" &&
                    this.permissionEffective(p.key))),
            ),
          }))
          .filter((g) => g.items.length);
      },
      shownPermissionGroups() {
        return this.permissionGroups.filter(
          (g) =>
            g.module ===
            (this.permissionGroups.some(
              (g) => g.module === this.permissionModule,
            )
              ? this.permissionModule
              : this.permissionGroups[0]?.module),
        );
      },
      selectedPermissionProfile() {
        return this.permissionProfiles.find(
          (p) => p.id === this.permissionUser,
        );
      },
      permissionTarget() {
        return {
          id: this.permissionUser || "NEW-PROFILE",
          name: this.profileName,
          role: "employee",
          active: true,
          access: {
            overrides: overrides(
              this.selectedPermissionProfile?.permissions || [],
            ),
          },
        };
      },
      permissionEditable() {
        return (
          !this.permissionPreview &&
          S.canEditProfile(this.engine, this.selectedPermissionProfile) &&
          this.can(
            this.selectedPermissionProfile
              ? "permissions.edit"
              : "permissions.create",
          )
        );
      },
      profileMembers() {
        return S.profileUsers(this.s, this.permissionUser);
      },
      permissionStats() {
        const saved = this.selectedPermissionProfile,
          selected = A.catalog
            .filter((p) => this.permissionEffective(p.key))
            .map((p) => p.key);
        return {
          allowed: selected.length,
          denied: A.catalog.length - selected.length,
          changes:
            A.catalog.filter(
              (p) =>
                selected.includes(p.key) !==
                (saved?.permissions || []).includes(p.key),
            ).length +
            (this.profileName.trim() !== (saved?.name || "") ? 1 : 0) +
            (this.profileActive !== (saved?.active ?? true) ? 1 : 0),
        };
      },
      assignableProfiles() {
        return this.permissionProfiles.filter(
          (p) =>
            p.active &&
            (p.id === this.staffForm.permissionProfileId ||
              p.permissions.every((k) => this.can(k))),
        );
      },
    });
    o.methods.openPermissionModule = function (id) {
      this.permissionModule = id;
      this.permissionModuleDialog = true;
    };
    o.watch.permissionModuleDialog = function (value) {
      if (value) {
        this._permissionModuleFocus = document.activeElement;
        this.$nextTick(() => {
          const d = this.$refs.permissionModuleDialog;
          if (d && !d.open) d.showModal();
        });
      } else
        this.$nextTick(
          () =>
            this._permissionModuleFocus?.isConnected &&
            this._permissionModuleFocus.focus(),
        );
    };
    for (const key of [
      "page",
      "currentUser",
      "permissionUser",
      "permissionDetailsOpen",
    ]) {
      const before = o.watch[key];
      o.watch[key] = function (...args) {
        this.permissionModuleDialog = false;
        if (typeof before === "function") before.apply(this, args);
        else before?.handler?.apply(this, args);
      };
    }
    const oldOpen = o.methods.openEdit,
      oldSave = o.methods.saveEntity,
      oldDisplay = o.methods.displayField,
      oldClose = o.methods.closeModal,
      oldSwitch = o.methods.switchUser;
    Object.assign(o.methods, {
      permissionBuckets(items) {
        const groups = [
          { title: "العرض والاطلاع", items: [] },
          { title: "الإضافة والتعديل", items: [] },
          { title: "التنفيذ والاعتماد", items: [] },
          { title: "التصدير والطباعة", items: [] },
        ];
        for (const p of items) {
          const a = p.key.split(".")[1];
          const i = ["export", "download", "print", "receipt"].includes(a)
            ? 3
            : ["view", "details", "cost", "profit", "pin"].includes(a)
              ? 0
              : [
                    "create",
                    "edit",
                    "toggle",
                    "role",
                    "scope",
                    "device",
                    "location",
                    "identity",
                  ].includes(a)
                ? 1
                : 2;
          groups[i].items.push(p);
        }
        return groups.filter((g) => g.items.length);
      },
      permissionHint(p) {
        const a = p.key.split(".")[1];
        if (a === "view") return "عرض القسم والمعلومات ضمن نطاق الحساب";
        if (a === "edit") return "تعديل البيانات المحفوظة في هذا القسم";
        if (a === "create") return "إضافة سجلات جديدة ضمن نطاق الحساب";
        if (a === "export") return "تنزيل بيانات القسم كملف";
        if (a === "delete") return "حذف السجلات المسموح بإزالتها";
        return p.label + " ضمن " + p.group + " وحسب نطاق الحساب";
      },
      permissionEffective(key) {
        return A.can(
          {
            ...this.permissionTarget,
            active: true,
            staffAccount: A.staffAccount(this.actor),
            agent: this.actor.agent || "",
            pos: this.actor.pos || "",
            access: this.permissionDraft,
          },
          key,
          this.s,
        );
      },

      showAccountDetails(user) {
        this.run(() => {
          this.engine.requirePermission("users.view");
          if (this.actor.role !== "owner")
            throw Error("تفاصيل الحساب الشاملة خاصة بمدير النظام");
          this.modal = {
            kind: "accountDetails",
            title: "تفاصيل الحساب — " + user.name,
            user: user.id,
          };
        });
      },
      editOwnerName(user) {
        this.run(() => {
          this.engine.requirePermission("users.edit");
          const target = this.s.users.find((u) => u.id === user.id);
          if (this.actor.role !== "owner" || target?.role !== "owner")
            throw Error("غير مسموح");
          this.staffForm = {
            id: target.id,
            name: target.name,
            email: target.email || "",
            role: "owner",
            permissionProfileId: "",
          };
          this.modal = { kind: "staff", title: "تعديل حساب مدير النظام" };
        });
      },
      saveOwnerName() {
        if (this.modal?.kind !== "ownerName") return;
        this.run(() => {
          this.engine.renameOwnerAccount(
            this.staffForm.id,
            this.staffForm.name,
            this.staffForm.email,
          );
          this.persist();
          this.closeModal();
        }, "تم تعديل حساب مدير النظام");
      },
      accountAuditValue(value) {
        return (
          JSON.stringify(
            value,
            (key, v) =>
              /password|credentials|secret|token|pin|cvc|hash|salt/i.test(key)
                ? "••••"
                : v,
            2,
          ) || "—"
        );
      },
      loadPermissions() {
        const p = this.selectedPermissionProfile;
        this.permissionLoadedVersion = p?.version || 0;
        this.profileName = p?.name || "";
        this.profileActive = p?.active ?? true;
        this.permissionDraft = { overrides: overrides(p?.permissions || []) };
        this.permissionReason = "";
        this.permissionSearch = "";
      },
      openPermissionProfile(profile, preview = true) {
        if (!this.permissionProfiles.some((p) => p.id === profile.id)) return;
        this.permissionPreview = preview;
        this.permissionUser = profile.id;
        this.loadPermissions();
        this.permissionDetailsOpen = true;
      },
      closePermissionDetails() {
        this.permissionDetailsOpen = false;
        this.loadPermissions();
      },
      newPermissionProfile() {
        this.run(() => {
          this.engine.requirePermission("permissions.create");
          this.permissionPreview = false;
          this.permissionUser = "";
          this.loadPermissions();
          this.permissionDetailsOpen = true;
        });
      },
      canChangeProfile(profile, action) {
        return (
          this.can("permissions." + action) &&
          S.canEditProfile(this.engine, profile)
        );
      },
      profileMemberCount(profile) {
        return S.profileUsers(this.s, profile.id).length;
      },
      toggleProfileRow(profile) {
        this.run(() => {
          S.toggleProfile(this.engine, profile.id, profile.version || 0);
        }, "تم تحديث حالة الصلاحية");
      },
      askDeleteProfile(profile) {
        this.run(() => {
          this.engine.requirePermission("permissions.delete");
          if (!S.canEditProfile(this.engine, profile))
            throw Error("لا يمكن حذف هذه الصلاحية");
          if (this.profileMemberCount(profile))
            throw Error(
              "الصلاحية مرتبطة بموظفين؛ غيّر صلاحياتهم أولًا أو عطّل الصلاحية",
            );
          const id = profile.id,
            version = profile.version || 0;
          this.modal = {
            kind: "confirm",
            title: "حذف الصلاحية",
            message: "حذف «" + profile.name + "»؟",
            action: () => S.deleteProfile(this.engine, id, version),
          };
        });
      },
      savePermissionDetails() {
        if (!this.permissionDetailsOpen || this.permissionPreview) return;
        this.run(() => {
          const old = this.selectedPermissionProfile;
          if ((old?.version || 0) !== this.permissionLoadedVersion)
            throw Error("تغيرت الصلاحية؛ افتحها مجددًا");
          const draft = {
            id: this.permissionUser || undefined,
            name: this.profileName,
            active: this.profileActive,
            permissions: A.catalog
              .filter((p) => this.permissionEffective(p.key))
              .map((p) => p.key),
          };
          const p = S.saveProfile(
            this.engine,
            draft,
            old ? "تعديل الاسم أو الصلاحيات من نموذج التعديل" : "",
          );
          this.permissionUser = p.id;
          this.loadPermissions();
          this.permissionDetailsOpen = false;
          this.profileName = "";
          this.permissionDraft = { overrides: overrides([]) };
        }, "تم حفظ الصلاحية");
      },
      resetPermissionSelection() {
        if (this.permissionEditable)
          this.permissionDraft = { overrides: overrides([]) };
      },
      profileNameFor(user) {
        return (
          this.s.permissionProfiles.find(
            (p) => p.id === user.permissionProfileId,
          )?.name || this.displayField(user, { key: "role" })
        );
      },
      editPermissions(user) {
        this.go("permissions");
        if (this.page !== "permissions") return;
        this.permissionPreview = false;
        this.permissionDetailsOpen = true;
        if (user.permissionProfileId) {
          this.permissionUser = user.permissionProfileId;
          this.loadPermissions();
        } else {
          this.permissionUser = "";
          this.loadPermissions();
          this.notify("اختر نوع صلاحية للموظف من نموذج التعديل");
        }
      },
      reviewPermissions() {
        this.run(() => {
          const draft = {
            id: this.permissionUser || undefined,
            name: this.profileName,
            active: this.profileActive,
            permissions: A.catalog
              .filter((p) => this.permissionEffective(p.key))
              .map((p) => p.key),
          };
          const e = new Masal.Engine(Masal.clone(this.s), this.currentUser);
          S.saveProfile(e, draft, this.permissionReason);
          const old = this.selectedPermissionProfile;
          this.modal = {
            kind: "profileReview",
            title: "مراجعة نوع الصلاحية",
            draft: Masal.clone(draft),
            reason: this.permissionReason,
            count: this.profileMembers.length,
            version: old?.version || 0,
            changes: A.catalog
              .filter(
                (p) =>
                  draft.permissions.includes(p.key) !==
                  (old?.permissions || []).includes(p.key),
              )
              .map((p) => ({
                label: p.group + " • " + p.label,
                allow: draft.permissions.includes(p.key),
              })),
          };
        });
      },
      savePermissionProfile() {
        this.run(() => {
          const m = this.modal,
            current = this.permissionProfiles.find((p) => p.id === m.draft.id);
          if ((current?.version || 0) !== m.version)
            throw Error("تغيرت الصلاحية؛ راجع التغييرات مجددًا");
          const p = S.saveProfile(this.engine, m.draft, m.reason);
          this.permissionUser = p.id;
          this.closeModal();
          this.loadPermissions();
          this.permissionDetailsOpen = false;
          if (!this.can("permissions.view")) this.switchUser();
        }, "تم حفظ نوع الصلاحية");
      },
      exportPermissions() {
        this.run(() => {
          this.engine.requirePermission("permissions.export");
          const p = this.selectedPermissionProfile;
          if (!p) throw Error("احفظ نوع الصلاحية أولًا");
          download(
            "masal-role-" + p.id + ".csv",
            csv(
              A.catalog.map((k) => ({
                profile: p.name,
                module: this.tr(k.group),
                permission: this.tr(k.label),
                key: k.key,
                allowed: p.permissions.includes(k.key),
              })),
            ),
            "text/csv;charset=utf-8",
          );
          this.engine.log("تصدير نوع صلاحية", p.id, null, { name: p.name });
        });
      },
      displayField(row, col) {
        if (col.key === "accountScope")
          return row.role === "owner"
            ? "جميع الفروع"
            : A.scope(this.s, { ...row, active: true })
                .map((id) => this.nameOf("agents", id))
                .join("، ") || "دون نطاق مسند";
        if (this.page === "users" && col.key === "permissionProfileId")
          return this.profileNameFor(row);
        if (col.key === "credentialStatus")
          return row.credentials ? "محددة" : "غير محددة";
        return oldDisplay.call(this, row, col);
      },
      openEdit(row) {
        if (
          row?.role === "owner" &&
          this.s.users.some((u) => u.id === row.id && u.role === "owner")
        )
          return this.editOwnerName(row);
        if (this.page !== "users") return oldOpen.call(this, row);
        this.run(() => {
          this.engine.requirePermission(row ? "users.edit" : "users.create");
          if (
            row &&
            this.actor.role !== "owner" &&
            !this.permissionUsers.some((u) => u.id === row.id)
          )
            throw Error("الموظف خارج نطاقك");
          this.staffForm = {
            id: row?.id,
            name: row?.name || "",
            email: row?.email || "",
            notes: row?.notes || "",
            password: "",
            confirmPassword: "",
            permissionProfileId: row?.permissionProfileId || "",
            assigned: Masal.clone(
              row?.access?.scope?.roots || row?.assigned || [],
            ),
            includeDescendants: row?.access?.scope?.descendants !== false,
            role: row?.role || "employee",
          };
          this.staffShowPassword = false;
          this.modal = {
            kind: "staff",
            title: row ? "تعديل الموظف" : "إضافة موظف",
          };
        });
      },
      async saveStaff() {
        if (this.staffSaving) return;
        this.staffSaving = true;
        const actor = this.currentUser,
          e = this.engine,
          draft = Masal.clone(this.staffForm),
          dialog = this.modal;
        try {
          if (draft.role === "owner") {
            e.renameOwnerAccount(draft.id, draft.name, draft.email);
            this.persist();
            if (this.modal === dialog) this.closeModal();
            this.notify("تم تعديل حساب مدير النظام");
            return;
          }
          const user = await S.saveEmployee(
            e,
            draft,
            () =>
              this.currentUser === actor &&
              this.s === e.s &&
              this.modal === dialog,
          );
          if (this.currentUser === actor) {
            if (this.modal === dialog) this.closeModal();
            this.notify("تم حفظ بيانات الموظف");
          }
        } catch (error) {
          this.notify(error.message, true);
        } finally {
          draft.password = "";
          draft.confirmPassword = "";
          this.staffSaving = false;
        }
      },
      saveEntity() {
        if (this.page === "users") return this.saveStaff();
        return oldSave.call(this);
      },
      closeModal() {
        oldClose.call(this);
        this.staffForm.password = "";
        this.staffForm.confirmPassword = "";
        this.staffShowPassword = false;
      },
      switchUser() {
        oldSwitch.call(this);
        this.permissionDetailsOpen = false;
        this.permissionUser = this.permissionProfiles[0]?.id || "";
        this.loadPermissions();
      },
    });
    const mounted = o.mounted;
    o.mounted = function () {
      mounted.call(this);
      this.permissionUser = this.permissionProfiles[0]?.id || "";
      this.loadPermissions();
    };
    const watchModal = o.watch.modal;
    o.watch.modal = function (value, ...args) {
      watchModal.call(this, value, ...args);
      if (!value || value.kind !== "staff") {
        this.staffForm.password = "";
        this.staffShowPassword = false;
      }
    };
  }
  root.MasalStaffUI = { install };
})(globalThis);

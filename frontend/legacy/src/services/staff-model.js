(function (root) {
  "use strict";
  const A = root.MasalAccess || null;
  function initialize(s) {
    if (s.permissionProfiles !== undefined) {
      for (const p of s.permissionProfiles) {
        p.ownerAccount ??= "@system";
        if (!p.detailVersion) {
          for (const [key, parent] of Object.entries(A.detailParents || {}))
            if (p.permissions.includes(parent) && !p.permissions.includes(key))
              p.permissions.push(key);
          p.detailVersion = 1;
        }
      }
      return;
    }
    const samples = [
      [
        "ROLE-READ",
        "قراءة وتقارير",
        ["dashboard.view", "reports.view", "reports.sales", "sales.view"],
      ],
      [
        "ROLE-SALES",
        "موظف مبيعات",
        [
          "dashboard.view",
          "sell.view",
          "sell.create",
          "sell.receipt",
          "sell.print",
          "sell.result",
          "sell.reprint",
          "sales.view",
          "exceptions.view",
          "data.pin",
        ],
      ],
      [
        "ROLE-SUPPORT",
        "موظف دعم",
        [
          "dashboard.view",
          "support.view",
          "support.create",
          "support.reply",
          "support.close",
          "support.escalate",
          "pos.view",
        ],
      ],
    ];
    s.permissionProfiles = samples.map(([id, name, permissions]) => ({
      id,
      name,
      permissions,
      active: true,
      version: 1,
      ownerAccount: "@system",
      builtin: true,
      detailVersion: 1,
    }));
  }
  function scopedUser(e, user) {
    if (!["employee", "supervisor", "owner"].includes(user.role)) return false;
    if (e.actor().role === "owner") return true;
    if (user.role === "owner") return false;
    if (user.staffAccount === "@system")
      return (
        A.staffAccount(e.actor()) === "@system" &&
        A.scope(e.s, { ...user, active: true }).every((a) => e.allowed(a))
      );
    const scope = A.scope(e.s, { ...user, active: true });
    return (
      user.role !== "owner" &&
      scope.length > 0 &&
      scope.every((a) => e.allowed(a))
    );
  }
  function profileUsers(s, id) {
    return s.users.filter((u) => u.permissionProfileId === id);
  }
  function canEditProfile(e, profile) {
    return (
      e.can("permissions.manage") &&
      (!profile ||
        ((e.actor().role === "owner" ||
          profile.ownerAccount === A.staffAccount(e.actor())) &&
          e.actor().permissionProfileId !== profile.id &&
          profileUsers(e.s, profile.id).every((u) => scopedUser(e, u))))
    );
  }
  function normalizeProfile(e, draft) {
    e.requirePermission("permissions.manage");
    const old = e.s.permissionProfiles?.find((p) => p.id === draft.id);
    if (draft.id && !old) throw Error("نوع الصلاحية غير موجود");
    if (!canEditProfile(e, old))
      throw Error("لا يمكن تعديل نوع صلاحية حسابك الحالي أو موظفين خارج نطاقك");
    e.requirePermission(old ? "permissions.edit" : "permissions.create");
    if (old && old.active !== (draft.active !== false))
      e.requirePermission("permissions.toggle");
    const name = String(draft.name || "").trim();
    if (!name) throw Error("اسم الصلاحية مطلوب");
    if (name.length > 80) throw Error("اسم الصلاحية طويل");
    if (
      e.s.permissionProfiles.some(
        (p) =>
          p.id !== draft.id &&
          (p.ownerAccount || "@system") ===
            (old?.ownerAccount || A.staffAccount(e.actor())) &&
          p.name.trim().toLocaleLowerCase() === name.toLocaleLowerCase(),
      )
    )
      throw Error("اسم الصلاحية مستخدم مسبقًا");
    const permissions = [...new Set(draft.permissions || [])];
    if (!permissions.length) throw Error("اختر صلاحية واحدة على الأقل");
    for (const key of permissions) {
      const p = A.catalog.find((p) => p.key === key);
      if (!p || p.module === "backup") throw Error("صلاحية غير قابلة للإسناد");
      if (
        !e.can(key) &&
        (!old?.permissions.includes(key) ||
          (!old.active && draft.active !== false))
      )
        throw Error("لا يمكنك منح صلاحية لا تملكها: " + key);
      if (
        p.module !== "data" &&
        key !== "sell.receipt" &&
        !key.endsWith(".view") &&
        !permissions.includes(p.module + ".view")
      )
        throw Error("اختر صلاحية عرض «" + p.group + "» قبل «" + p.label + "»");
    }
    return {
      id: old?.id || root.Masal.id("ROLE"),
      name,
      permissions,
      active: draft.active !== false,
      version: (old?.version || 0) + 1,
      ownerAccount: old?.ownerAccount || A.staffAccount(e.actor()),
      detailVersion: 1,
    };
  }
  function saveProfile(e, draft, reason) {
    initialize(e.s);
    const next = normalizeProfile(e, draft),
      old = e.s.permissionProfiles.find((p) => p.id === next.id);
    if (old && !String(reason || "").trim()) throw Error("سبب التغيير مطلوب");
    const before = old ? root.Masal.clone(old) : null;
    if (old) Object.assign(old, next);
    else e.s.permissionProfiles.push(next);
    e.log(old ? "تعديل نوع صلاحية" : "إضافة نوع صلاحية", next.id, before, {
      ...next,
      reason: String(reason || "").trim(),
      affectedUsers: profileUsers(e.s, next.id).map((u) => u.id),
    });
    return next;
  }
  function profileAction(e, id, key, version) {
    initialize(e.s);
    e.requirePermission(key);
    const p = e.s.permissionProfiles.find((p) => p.id === id);
    if (!p) throw Error("الصلاحية غير موجودة");
    if (!canEditProfile(e, p))
      throw Error("لا يمكن تغيير صلاحية حسابك أو صلاحية خارج نطاقك");
    if (version !== undefined && (p.version || 0) !== version)
      throw Error("تغيرت الصلاحية؛ افتحها مجددًا");
    return p;
  }
  function toggleProfile(e, id, version) {
    const p = profileAction(e, id, "permissions.toggle", version);
    if (!p.active && p.permissions.some((k) => !e.can(k)))
      throw Error("لا يمكنك تفعيل صلاحيات لا تملكها");
    const before = root.Masal.clone(p);
    p.active = !p.active;
    p.version = (p.version || 0) + 1;
    e.log(
      p.active ? "تفعيل نوع صلاحية" : "تعطيل نوع صلاحية",
      p.id,
      before,
      root.Masal.clone(p),
    );
    return p;
  }
  function deleteProfile(e, id, version) {
    const p = profileAction(e, id, "permissions.delete", version);
    if (profileUsers(e.s, id).length)
      throw Error(
        "الصلاحية مرتبطة بموظفين؛ غيّر صلاحياتهم أولًا أو عطّل الصلاحية",
      );
    const before = root.Masal.clone(p);
    e.s.permissionProfiles.splice(e.s.permissionProfiles.indexOf(p), 1);
    e.log("حذف نوع صلاحية", p.id, before, null);
  }
  function validateEmployee(e, draft) {
    if (String(draft.notes || "").length > 2000)
      throw Error("الملاحظات بحد أقصى 2000 حرف");
    e.requirePermission(draft.id ? "users.edit" : "users.create");
    const old = e.s.users.find((u) => u.id === draft.id);
    if (draft.id && !old) throw Error("الموظف غير موجود");
    if (old?.archivedAt) throw Error("الحساب مؤرشف");
    if (old && !scopedUser(e, old)) throw Error("الموظف خارج نطاقك");
    const name = String(draft.name || "").trim(),
      email = String(draft.email || "")
        .trim()
        .toLowerCase();
    if (!name) throw Error("اسم الموظف مطلوب");
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email))
      throw Error("أدخل بريدًا إلكترونيًا صحيحًا");
    if (
      e.s.users.some(
        (u) =>
          u.id !== draft.id &&
          String(u.email || "")
            .trim()
            .toLowerCase() === email,
      )
    )
      throw Error("البريد الإلكتروني مستخدم مسبقًا");
    const profile = e.s.permissionProfiles?.find(
      (p) => p.id === draft.permissionProfileId,
    );
    if (
      draft.permissionProfileId &&
      !profile?.active &&
      !(old && old.permissionProfileId === draft.permissionProfileId)
    )
      throw Error("اختر نوع صلاحية فعالًا");
    if (!draft.permissionProfileId && (!old || old.role === "employee"))
      throw Error("نوع الصلاحية مطلوب");
    if (
      old &&
      (old.permissionProfileId || "") !== (draft.permissionProfileId || "")
    ) {
      if (old.id === e.user || old.role === "owner")
        throw Error("لا يمكن تغيير صلاحية حسابك الحالي أو مدير النظام");
      e.requirePermission("users.role");
    }
    if (
      profile &&
      (profile.ownerAccount || "@system") !== A.staffAccount(e.actor()) &&
      !profile.builtin &&
      e.actor().role !== "owner"
    )
      throw Error("نوع الصلاحية تابع لجهة أخرى");
    if (
      profile &&
      profile.id !== old?.permissionProfileId &&
      profile.permissions.some((k) => !e.can(k))
    )
      throw Error("نوع الصلاحية يتضمن صلاحيات لا تملكها");
    const assigned = [...new Set(draft.assigned || [])];
    if (
      assigned.some((a) => !e.s.agents.some((x) => x.id === a) || !e.allowed(a))
    )
      throw Error("نطاق الموظف خارج الصلاحيات");
    if (
      old &&
      (JSON.stringify(assigned) !==
        JSON.stringify(old.access?.scope?.roots || old.assigned || []) ||
        (draft.includeDescendants !== false) !==
          (old.access?.scope?.descendants !== false))
    )
      e.requirePermission("users.scope");
    if (old && draft.password)
      root.MasalPasswordAdmin.validate(
        e,
        old.id,
        draft.password,
        draft.confirmPassword,
      );
    if (draft.password && draft.password.length < 8)
      throw Error("كلمة المرور يجب أن تكون 8 أحرف على الأقل");
    if (!old && !draft.password) throw Error("كلمة المرور مطلوبة");
    return { old, name, email, assigned, profile };
  }
  async function passwordHash(password) {
    const bytes = new TextEncoder().encode(password),
      salt = crypto.getRandomValues(new Uint8Array(16)),
      key = await crypto.subtle.importKey("raw", bytes, "PBKDF2", false, [
        "deriveBits",
      ]);
    const hash = await crypto.subtle.deriveBits(
      { name: "PBKDF2", salt, iterations: 210000, hash: "SHA-256" },
      key,
      256,
    );
    const hex = (a) =>
      Array.from(new Uint8Array(a), (x) =>
        x.toString(16).padStart(2, "0"),
      ).join("");
    return {
      algorithm: "PBKDF2-SHA256",
      iterations: 210000,
      salt: hex(salt),
      hash: hex(hash),
    };
  }
  async function verifyPassword(password, credentials) {
    if (!credentials) return false;
    const salt = Uint8Array.from(credentials.salt.match(/../g), (v) =>
      parseInt(v, 16),
    );
    const key = await crypto.subtle.importKey(
      "raw",
      new TextEncoder().encode(password),
      "PBKDF2",
      false,
      ["deriveBits"],
    );
    const bits = await crypto.subtle.deriveBits(
      {
        name: "PBKDF2",
        salt,
        iterations: credentials.iterations,
        hash: "SHA-256",
      },
      key,
      256,
    );
    const hash = Array.from(new Uint8Array(bits), (x) =>
      x.toString(16).padStart(2, "0"),
    ).join("");
    return hash === credentials.hash;
  }
  async function saveEmployee(e, draft, stillCurrent = () => true) {
    initialize(e.s);
    if (e.s.users.some((u) => u.id === draft.id && u.role === "owner"))
      throw Error("حساب مدير النظام يدعم تعديل الاسم فقط");
    const first = validateEmployee(e, draft),
      passwordBefore = JSON.stringify(first.old?.credentials);
    const credentials = draft.password
      ? await passwordHash(draft.password)
      : null;
    if (!stillCurrent()) throw Error("تغير الحساب أثناء الحفظ");
    const { old, name, email, assigned } = validateEmployee(e, draft);
    if (
      old &&
      draft.password &&
      JSON.stringify(old.credentials) !== passwordBefore
    )
      throw Error("تغيرت كلمة المرور؛ أعد المحاولة");
    const before = old ? summary(old) : null;
    const user = {
      ...(old || {
        id: root.Masal.id("USER"),
        role: "employee",
        active: true,
        agent: "",
        pos: "",
      }),
      name,
      email,
      assigned,
      notes: String(draft.notes ?? old?.notes ?? "").trim(),
      permissionProfileId: draft.permissionProfileId || "",
    };
    if (!old) {
      user.staffAccount = A.staffAccount(e.actor());
      user.agent = user.staffAccount === "@system" ? "" : user.staffAccount;
      user.employment = user.agent ? "agent" : "central";
    }
    if (["employee", "supervisor"].includes(user.role))
      user.access = {
        ...(old?.access || {}),
        scope: {
          roots: assigned,
          descendants: draft.includeDescendants !== false,
        },
      };
    if (credentials) user.credentials = credentials;
    if (old) Object.assign(old, user);
    else e.s.users.push(user);
    if (old && credentials) root.MasalPasswordAdmin.apply(e, old, credentials);
    e.log(old ? "تعديل موظف" : "إضافة موظف", user.id, before, {
      ...summary(user),
      passwordChanged: !!credentials,
    });
    return user;
  }
  function summary(u) {
    return {
      id: u.id,
      name: u.name,
      email: u.email || "",
      role: u.role,
      permissionProfileId: u.permissionProfileId || "",
      assigned: u.assigned || [],
      active: u.active,
      notes: u.notes || "",
      hasPassword: !!u.credentials,
    };
  }
  root.MasalStaff = {
    toggleProfile,
    deleteProfile,
    initialize,
    profileUsers,
    canEditProfile,
    normalizeProfile,
    saveProfile,
    scopedUser,
    validateEmployee,
    saveEmployee,
    passwordHash,
    verifyPassword,
    summary,
  };
})(globalThis);

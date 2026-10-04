(function (root) {
  "use strict";
  const M = root.Masal,
    P = M.Engine.prototype,
    now = () => Date.now();
  const runtimes = new WeakMap();
  function init(s) {
    s.securityPolicy ??= {
      sensitive2FA: false,
      enforceSessions: false,
      blockVPN: false,
    };
    s.localSessions ??= [];
    s.securityEvents ??= [];
    s.alertRoutes ??= [];
    s.alertOutbox ??= [];
    s.deviceEnrollments ??= [];
    if (!runtimes.has(s)) {
      for (const session of s.localSessions) session.active = false;
      runtimes.set(s, { challenges: new Map(), proofs: new Map() });
    }
    return runtimes.get(s);
  }
  async function digest(value) {
    const hash = await crypto.subtle.digest(
      "SHA-256",
      new TextEncoder().encode(value),
    );
    return Array.from(new Uint8Array(hash), (x) =>
      x.toString(16).padStart(2, "0"),
    ).join("");
  }
  function code() {
    return String(
      crypto.getRandomValues(new Uint32Array(1))[0] % 1000000,
    ).padStart(6, "0");
  }
  function event(s, user, action) {
    s.securityEvents.unshift({
      id: M.id("SEC"),
      user,
      action,
      time: new Date().toISOString(),
    });
  }
  function user(s, id) {
    const u = s.users.find((u) => u.id === id && u.active && !u.archivedAt);
    if (!u) throw Error("الحساب غير متاح");
    if (u.role !== "owner" && u.staffAccount !== "@system")
      new M.Engine(s, id).checkOperation?.(u.pos || u.agent, "login");
    return u;
  }
  function passwordPolicy(password) {
    if (typeof password !== "string" || password.length < 8)
      throw Error("كلمة المرور قصيرة؛ يجب أن تتكون من 8 أحرف على الأقل");
    if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password))
      throw Error("كلمة المرور يجب أن تحتوي على حرف إنكليزي ورقم على الأقل");
  }
  function revoke(s, id) {
    init(s).proofs.delete(id);
    for (const session of s.localSessions.filter((x) => x.user === id))
      session.active = false;
    for (const [key, c] of init(s).challenges)
      if (c.user === id) init(s).challenges.delete(key);
    event(s, id, "إنهاء جميع جلسات الحساب");
  }
  async function challenge(s, id, purpose) {
    init(s);
    user(s, id);
    const value = code(),
      key = crypto.randomUUID();
    init(s).challenges.set(key, {
      user: id,
      purpose,
      hash: await digest(value),
      expires: now() + 120000,
      attempts: 0,
    });
    return { id: key, demoCode: value, expires: now() + 120000, purpose };
  }
  async function verify(s, key, value) {
    const store = init(s).challenges,
      c = store.get(key);
    if (!c || c.expires < now() || c.attempts >= 5) {
      store.delete(key);
      throw Error("انتهت صلاحية الرمز أو عدد المحاولات");
    }
    c.attempts++;
    if (c.hash !== (await digest(value))) {
      if (c.attempts >= 5) store.delete(key);
      throw Error("رمز التحقق غير صحيح");
    }
    user(s, c.user);
    store.delete(key);
    return c;
  }
  function checkNetwork(s, network) {
    const ip = String(network?.ip || "").trim();
    const blocked = String(s.settings.blockedIPs || "")
      .split(/[\s,;]+/)
      .filter(Boolean);
    if (ip && blocked.includes(ip)) throw Error("عنوان الشبكة محظور");
    if (s.securityPolicy.blockVPN && network?.vpn)
      throw Error("شبكة VPN محظورة حسب السياسة");
  }
  function createSession(s, id, network) {
    checkNetwork(s, network);
    const session = {
      id: crypto.randomUUID(),
      user: id,
      created: now(),
      lastActivity: now(),
      active: true,
      network: { ip: String(network?.ip || ""), vpn: !!network?.vpn },
      local: true,
    };
    s.localSessions.push(session);
    event(s, id, "دخول محلي ناجح");
    return session;
  }
  const Auth = {
    init,
    passwordPolicy,
    revoke,
    checkNetwork,
    async start(s, id, password, purpose = "login", network = {}) {
      init(s);
      const u = user(s, id);
      if (!s.settings.login) throw Error("تسجيل الدخول موقوف");
      checkNetwork(s, network);
      if (!(await root.MasalStaff.verifyPassword(password, u.credentials)))
        throw Error("بيانات الدخول غير صحيحة");
      if (!["login", "enroll", "stepup"].includes(purpose))
        throw Error("إجراء غير صالح");
      if (purpose === "login" && u.mustChangePassword)
        throw Error("يجب تغيير كلمة المرور المؤقتة أولًا");
      if (purpose === "login" && !u.twoFactor) {
        return { session: createSession(s, id, network) };
      }
      const c = await challenge(s, id, purpose);
      init(s).challenges.get(c.id).network = network;
      return c;
    },
    async confirm(s, id, value) {
      const c = await verify(s, id, value),
        u = user(s, c.user);
      if (c.purpose === "reset") throw Error("استخدم نموذج إعادة التعيين");
      if (c.purpose === "enroll") {
        u.twoFactor = true;
        const recovery = Array.from({ length: 6 }, () => crypto.randomUUID());
        u.recoveryHashes = await Promise.all(recovery.map(digest));
        event(s, u.id, "تفعيل التحقق بخطوتين");
        return { recovery };
      }
      if (c.purpose === "stepup") {
        init(s).proofs.set(u.id, now() + 300000);
        event(s, u.id, "تأكيد إجراء حساس");
        return { verified: true };
      }
      return { session: createSession(s, u.id, c.network) };
    },
    async forgot(s, identity) {
      init(s);
      const u = s.users.find(
        (u) =>
          u.active &&
          (u.id === identity ||
            u.email?.toLowerCase() === String(identity).trim().toLowerCase()),
      );
      if (!u || u.role === "owner")
        return {
          message:
            "إذا كان الحساب موجودًا فستظهر رسالة استعادة في قناة الاختبار",
        };
      const c = await challenge(s, u.id, "reset");
      return {
        ...c,
        message: "تم إنشاء رسالة استعادة محلية؛ لم يتم إرسال بريد أو SMS",
      };
    },
    async reset(s, key, value, password) {
      passwordPolicy(password);
      const pending = init(s).challenges.get(key);
      if (
        pending?.purpose !== "reset" ||
        s.users.find((u) => u.id === pending.user)?.role === "owner"
      )
        throw Error("طلب استعادة غير صالح");
      const c = await verify(s, key, value),
        u = user(s, c.user);
      const hash = await root.MasalStaff.passwordHash(password);
      u.credentials = hash;
      u.mustChangePassword = false;
      revoke(s, u.id);
      event(s, u.id, "تغيير كلمة المرور عبر الاستعادة");
    },
    async recovery(s, id, value, password) {
      passwordPolicy(password);
      const u = user(s, id),
        hash = await digest(value),
        index = u.recoveryHashes?.indexOf(hash) ?? -1;
      if (index < 0) throw Error("رمز الاستعادة غير صالح أو مستخدم");
      u.recoveryHashes.splice(index, 1);
      const credentials = await root.MasalStaff.passwordHash(password);
      u.credentials = credentials;
      u.mustChangePassword = false;
      revoke(s, u.id);
      event(s, u.id, "استخدام رمز استعادة لمرة واحدة");
    },
    async firstPassword(s, id, old, password) {
      passwordPolicy(password);
      if (old === password) throw Error("اختر كلمة مختلفة");
      const u = user(s, id);
      if (!(await root.MasalStaff.verifyPassword(old, u.credentials)))
        throw Error("كلمة المرور غير صحيحة");
      u.credentials = await root.MasalStaff.passwordHash(password);
      u.mustChangePassword = false;
      revoke(s, id);
    },
    expire(s, time = now()) {
      init(s);
      const idle = Math.max(1, Number(s.settings.idle) || 60) * 60000;
      for (const x of s.localSessions)
        if (x.active && time - x.lastActivity >= idle) {
          x.active = false;
          event(s, x.user, "انتهت الجلسة بسبب الخمول");
        }
    },
    touch(s, id) {
      this.expire(s);
      const session = s.localSessions.find((x) => x.id === id && x.active);
      if (session) {
        checkNetwork(s, session.network);
        session.lastActivity = now();
      }
    },
    valid(s, id) {
      this.expire(s);
      const session = s.localSessions
        .filter((x) => x.user === id && x.active)
        .at(-1);
      if (session) checkNetwork(s, session.network);
      return session;
    },
    proof(s, id) {
      return (init(s).proofs.get(id) || 0) > now();
    },
  };
  const permission = P.requirePermission;
  P.requirePermission = function (key, agent) {
    permission.call(this, key, agent);
    init(this.s);
    if (["security.app"].includes(key)) return;
    if (this.s.securityPolicy.enforceSessions && !Auth.valid(this.s, this.user))
      throw Error("الجلسة منتهية؛ افتح أمان حسابي وسجل الدخول");
    if (
      this.s.securityPolicy.sensitive2FA &&
      [
        "wallets.transfer",
        "wallets.deposit",
        "wallets.reverse",
        "wallets.exception",
        "prices.approve",
        "prices.policy",
        "exports.encrypt",
        "exports.approve",
        "claims.settle",
        "permissions.manage",
        "users.role",
        "users.reset",
        "users.resetDirect",
        "security.fundingRequests",
        "security.fundingRecovery",
        "backup.download",
        "backup.restore",
        "security.policies",
      ].includes(key) &&
      !Auth.proof(this.s, this.user)
    )
      throw Error("يلزم تأكيد إضافي؛ افتح أمان حسابي ثم تأكيد إجراء حساس");
  };
  P.saveSecurityPolicy = function (policy) {
    this.requirePermission("security.policies");
    const u = this.actor();
    if (policy.sensitive2FA && !u.twoFactor)
      throw Error("فعّل التحقق بخطوتين لحسابك أولًا");
    if (policy.enforceSessions && !Auth.valid(this.s, this.user))
      throw Error("اختبر تسجيل الدخول أولًا قبل إلزام الجلسة");
    for (const key of ["sensitive2FA", "enforceSessions", "blockVPN"])
      this.s.securityPolicy[key] = !!policy[key];
    this.log("سياسة أمان محلية", "securityPolicy", null, this.s.securityPolicy);
  };
  P.saveAlertRoute = function (rule) {
    init(this.s);
    this.requirePermission("notifications.rules");
    if (
      !["متوسطة", "مرتفعة", "حرجة"].includes(rule.severity) ||
      !["داخل النظام", "بريد إلكتروني", "Push"].includes(rule.channel)
    )
      throw Error("قناة أو خطورة غير صالحة");
    if (rule.target === "all")
      this.requirePermission("notifications.broadcast");
    else this.accountCheck(rule.target);
    const delay = Number(rule.delay);
    if (!Number.isFinite(delay) || delay < 0 || delay > 1440)
      throw Error("مهلة التصعيد من 0 إلى 1440 دقيقة");
    const r = {
      id: M.id("ROUTE"),
      severity: rule.severity,
      channel: rule.channel,
      target: rule.target,
      delay,
      active: true,
    };
    this.s.alertRoutes.push(r);
    this.log("قاعدة توجيه تنبيه", r.id, null, r);
    return r;
  };
  P.routeAlerts = function (time = now()) {
    init(this.s);
    this.requirePermission("notifications.rules");
    for (const n of this.s.notifications.filter(
      (n) =>
        n.user === "SYSTEM" &&
        (n.target === "all" || this.allowed(this.accountAgent(n.target))),
    )) {
      for (const route of this.s.alertRoutes.filter(
        (r) => r.active && r.severity === (n.severity || "متوسطة"),
      )) {
        if (route.target === "all" && !this.can("notifications.broadcast"))
          continue;
        if (
          route.target !== "all" &&
          !this.allowed(this.accountAgent(route.target))
        )
          continue;
        if (
          this.s.alertOutbox.some(
            (o) => o.notification === n.id && o.route === route.id,
          )
        )
          continue;
        this.s.alertOutbox.push({
          id: M.id("OUT"),
          notification: n.id,
          route: route.id,
          target: route.target,
          channel: route.channel,
          title: n.title,
          due: Date.parse(n.time) + route.delay * 60000,
          status: "بانتظار الموعد",
        });
      }
    }
    for (const item of this.s.alertOutbox.filter(
      (o) =>
        o.status === "بانتظار الموعد" &&
        o.due <= time &&
        (o.target === "all"
          ? this.can("notifications.broadcast")
          : this.allowed(this.accountAgent(o.target))),
    )) {
      if (item.channel === "داخل النظام") {
        this.s.notifications.push({
          id: M.id("NT"),
          target: item.target,
          title: item.title,
          body: "تصعيد تنبيه • " + item.notification,
          time: new Date(time).toISOString(),
          user: this.user,
        });
        item.status = "تم التسليم محليًا";
      } else item.status = "جاهز للربط — لم يرسل";
    }
    this.log("توجيه وتصعيد التنبيهات", "alertOutbox", null, {
      processed: true,
    });
  };
  root.MasalAuth = Auth;
})(globalThis);

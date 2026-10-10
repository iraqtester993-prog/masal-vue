(function (root) {
  "use strict";
  function requireTarget(e, id) {
    e.requirePermission("users.reset");
    if (!["owner", "employee", "supervisor"].includes(e.actor().role))
      throw Error("إعادة تعيين كلمة المرور لمدير النظام أو الموظف المخوّل فقط");
    const u = e.s.users.find((u) => u.id === id);
    if (!u || u.archivedAt || !u.active)
      throw Error("الحساب غير موجود أو موقوف أو مؤرشف");
    if (u.role === "owner")
      throw Error("إعادة تعيين كلمة المرور غير متاحة لحساب مدير النظام");
    if (e.actor().role !== "owner") {
      if (u.role === "owner") throw Error("كلمة مرور مدير النظام محمية");
      if (["employee", "supervisor"].includes(u.role)) {
        if (!MasalStaff.scopedUser(e, u))
          throw Error("المستخدم خارج نطاق صلاحياتك");
      } else {
        const account =
          u.role === "pos"
            ? e.s.pos.find((p) => p.id === u.pos)?.agent
            : u.agent;
        if (!account || !e.allowed(account))
          throw Error("الحساب خارج نطاق صلاحياتك");
      }
    }
    return u;
  }
  function validate() {
    throw Error(
      "استخدم طلب إعادة التعيين؛ صاحب الحساب يختار كلمة مروره بعد التحقق",
    );
  }
  function apply() {
    throw Error("تغيير كلمة المرور يتطلب تحقق صاحب الحساب");
  }
  const proofs = new WeakMap();
  const digits = (value) => {
    let p = String(value ?? "")
      .replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 1632))
      .replace(/[۰-۹]/g, (c) => String(c.charCodeAt(0) - 1776))
      .replace(/[^\d]/g, "");
    if (p.startsWith("00964")) p = p.slice(2);
    if (p.startsWith("964")) p = "0" + p.slice(3);
    return p;
  };
  function accountPhone(s, u) {
    if (u?.role === "owner") return "";
    if (u?.phone) return u.phone;
    if (u?.role === "pos")
      return s.pos.find((p) => p.id === u.pos)?.phone || "";
    if (["main", "sub"].includes(u?.role) && u?.agent)
      return s.agents.find((a) => a.id === u.agent)?.phone || "";
    return "";
  }
  function phoneMatches(s, u, phone) {
    const entered = digits(phone);
    return /^07\d{9}$/.test(entered) && digits(accountPhone(s, u)) === entered;
  }
  function phoneLabel(s, u) {
    const p = digits(accountPhone(s, u));
    return p.length === 11
      ? p.slice(0, 3) + " *** " + p.slice(-2)
      : "رقم الهاتف المسجل";
  }
  function phoneTail(s, u) {
    const p = digits(accountPhone(s, u));
    return p.length >= 3 ? p.slice(-3) : "غير مسجل";
  }
  function request(e, id, phone) {
    const u = target(e, id),
      selfRequest = !!phone,
      destination = digits(phone || accountPhone(e.s, u));
    if (!phoneMatches(e.s, u, destination))
      throw Error("لا يوجد رقم هاتف صالح ومطابق لهذا الحساب");
    u.passwordResetRequest = {
      id: crypto.randomUUID(),
      requestedAt: Date.now(),
      by: selfRequest ? "self" : "admin",
      attempts: 0,
      phone: destination,
      otpDestination: destination,
    };
    e.log("طلب إعادة تعيين كلمة المرور", id, null, {
      channel: "otp",
      phoneVerified: selfRequest,
      otpDestination: destination,
      demo: true,
    });
    return u.passwordResetRequest;
  }
  function requestFromLogin(s, id) {
    const u = s.users.find((u) => u.id === id && u.active && !u.archivedAt);
    if (!u || u.role === "owner")
      throw Error("الحساب غير موجود أو غير مؤهل لإعادة التعيين");
    const phone = digits(accountPhone(s, u));
    if (phone.length !== 11 || !/^07\d{9}$/.test(phone))
      throw Error("لا يوجد رقم هاتف صالح مرتبط بهذا الحساب؛ راجع مدير النظام");
    const now = Date.now(),
      rate = u.resetOtpRate;
    if (rate && now - rate.last < 60000)
      throw Error("انتظر دقيقة قبل إعادة إرسال الرمز");
    if (rate && now - rate.start < 3600000 && rate.count >= 5)
      throw Error("تجاوزت عدد طلبات الرمز؛ حاول بعد ساعة");
    u.resetOtpRate =
      rate && now - rate.start < 3600000
        ? { ...rate, last: now, count: rate.count + 1 }
        : { start: now, last: now, count: 1 };
    u.passwordResetRequest = {
      id: crypto.randomUUID(),
      requestedAt: Date.now(),
      by: "self",
      attempts: 0,
      phone,
      otpDestination: phone,
    };
    new Masal.Engine(s, id).log("طلب رمز إعادة تعيين كلمة المرور", id, null, {
      channel: "otp",
      otpDestination: phone,
      demo: true,
    });
    return u.passwordResetRequest;
  }
  function target(e, id) {
    if (["main", "sub"].includes(e.actor().role)) {
      e.requirePermission("users.reset");
      const u = e.s.users.find((u) => u.id === id && u.active && !u.archivedAt),
        own = e.actor().agent,
        a =
          u?.role === "pos"
            ? e.s.pos.find((p) => p.id === u.pos)?.agent
            : u?.agent;
      if (
        !u ||
        !["sub", "pos"].includes(u.role) ||
        !a ||
        !e.descendants(own).includes(a) ||
        u.id === e.user
      )
        throw Error("الحساب خارج نطاق التابعين");
      return u;
    }
    const u = requireTarget(e, id);
    if (!u.active) throw Error("الحساب موقوف");
    return u;
  }
  function pending(s, id) {
    const u = s.users.find((u) => u.id === id && u.active && !u.archivedAt);
    if (u?.role === "owner" || !u?.passwordResetRequest)
      throw Error("لا يوجد طلب إعادة تعيين لهذا الحساب");
    if (digits(accountPhone(s, u)) !== u.passwordResetRequest.otpDestination)
      throw Error("تغيّر رقم الهاتف؛ اطلب رمزًا جديدًا");
    if (
      u.passwordResetRequest.by === "self" &&
      findByPhone(s, u.passwordResetRequest.otpDestination).id !== u.id
    )
      throw Error("تغيّر الحساب المرتبط بالرقم");
    return u;
  }
  function verify(s, id, code) {
    const u = pending(s, id),
      r = u.passwordResetRequest;
    if (Date.now() - r.requestedAt > 300000)
      throw Error("انتهت مهلة التحقق؛ اطلب إعادة التعيين مجددًا");
    if (r.verified) throw Error("استُخدم الرمز؛ اطلب إعادة التعيين مجددًا");
    if (r.attempts >= 5)
      throw Error("انتهت المحاولات؛ اطلب إعادة التعيين مجددًا");
    r.attempts++;
    if (String(code) !== "123456") throw Error("رمز التحقق غير صحيح");
    r.verified = true;
    const token = crypto.randomUUID();
    let m = proofs.get(s);
    if (!m) {
      m = new Map();
      proofs.set(s, m);
    }
    m.set(token, { user: id, request: r.id, expires: Date.now() + 300000 });
    return token;
  }
  async function complete(
    s,
    token,
    password,
    confirmation,
    stillCurrent = () => true,
  ) {
    const proof = proofs.get(s)?.get(token);
    if (!proof || proof.expires < Date.now())
      throw Error("تحقق من الرمز مجددًا");
    const u = pending(s, proof.user);
    if (u.passwordResetRequest.id !== proof.request)
      throw Error("تم استبدال طلب التحقق");
    MasalAuth.passwordPolicy(password);
    if (password !== confirmation) throw Error("تأكيد كلمة المرور غير مطابق");
    const before = JSON.stringify(u.credentials),
      credentials = await MasalStaff.passwordHash(password);
    if (
      !stillCurrent() ||
      proofs.get(s)?.get(token) !== proof ||
      proof.expires < Date.now() ||
      !u.active ||
      u.archivedAt ||
      u.passwordResetRequest?.id !== proof.request ||
      JSON.stringify(u.credentials) !== before ||
      digits(accountPhone(s, u)) !== u.passwordResetRequest?.otpDestination
    )
      throw Error("تغير الطلب أو الحساب؛ أعد التحقق");
    proofs.get(s).delete(token);
    u.credentials = credentials;
    u.mustChangePassword = false;
    delete u.passwordResetRequest;
    MasalAuth.revoke(s, u.id);
    new Masal.Engine(s, u.id).log(
      "تعيين كلمة المرور بواسطة صاحب الحساب",
      u.id,
      null,
      { channel: "otp", sessionsRevoked: true },
    );
    return u.id;
  }
  async function directReset(e, id, password, confirmation) {
    e.requirePermission("users.resetDirect");
    const u = requireTarget(e, id);
    MasalAuth.passwordPolicy(password);
    if (password !== confirmation) throw Error("تأكيد كلمة المرور غير مطابق");
    const credentials = await MasalStaff.passwordHash(password);
    e.requirePermission("users.resetDirect");
    requireTarget(e, id);
    u.credentials = credentials;
    u.mustChangePassword = false;
    delete u.passwordResetRequest;
    MasalAuth.revoke(e.s, u.id);
    e.log("تغيير كلمة المرور مباشرة بواسطة مدير النظام", u.id, null, {
      direct: true,
      sessionsRevoked: true,
    });
    return u.id;
  }
  const panel = {
    data: () => ({
      open: false,
      id: "",
      identifier: "",
      phone: "",
      code: "",
      password: "",
      confirmation: "",
      showPassword: false,
      showConfirmation: false,
      token: "",
      phase: "identify",
      error: "",
      busy: false,
    }),
    computed: {
      vm() {
        return this.$root;
      },
      showLoginReset() {
        const q = String(this.vm.loginName || "")
          .trim()
          .toLowerCase();
        return !this.vm.s.users.some(
          (u) =>
            u.role === "owner" &&
            [u.username, u.email].some((v) => v && v.toLowerCase() === q),
        );
      },
      pendingId() {
        return (
          !this.vm.loginScreen &&
          this.vm.actor?.role !== "owner" &&
          this.vm.actor?.passwordResetRequest?.id
        );
      },
      user() {
        return this.vm.s.users.find((u) => u.id === this.id);
      },
      name() {
        return this.user?.name || "";
      },
      phoneHint() {
        return this.user ? phoneLabel(this.vm.s, this.user) : "";
      },
    },
    watch: {
      pendingId(v) {
        if (v) this.start(this.vm.currentUser);
      },
      "vm.currentUser"() {
        this.close();
      },
      "vm.loginScreen"(v) {
        if (v) this.close();
      },
    },
    mounted() {
      if (this.pendingId) this.start(this.vm.currentUser);
    },
    methods: {
      start(id) {
        this.showPassword = false;
        this.showConfirmation = false;
        this.id = id || "";
        this.identifier = "";
        this.phone = "";
        this.code = "";
        this.password = "";
        this.confirmation = "";
        this.token = "";
        this.phase = "identify";
        this.error = "";
        this.open = true;
        this.$nextTick(() => {
          if (this.open && !this.$refs.dialog.open)
            this.$refs.dialog.showModal();
        });
      },
      close() {
        this.showPassword = false;
        this.showConfirmation = false;
        this.open = false;
        this.id = "";
        this.token = "";
        this.phase = "identify";
        this.password = "";
        this.confirmation = "";
        this.$refs.dialog?.close();
      },
      identify() {
        const q = this.identifier.trim().toLowerCase(),
          users = this.vm.s.users.filter(
            (u) =>
              u.active &&
              !u.archivedAt &&
              [u.email, u.username, u.name].some(
                (v) => v && v.toLowerCase() === q,
              ),
          );
        if (users.length !== 1) throw Error("اسم الحساب أو البريد غير صحيح");
        const u = users[0];
        if (!phoneMatches(this.vm.s, u, this.phone))
          throw Error("رقم الهاتف لا يطابق الحساب المحدد");
        request(this.vm.engine, u.id, this.phone);
        this.id = u.id;
        this.phase = "otp";
        this.error = "";
        this.vm.persist();
      },
      async submit() {
        if (this.busy) return;
        this.busy = true;
        this.error = "";
        try {
          if (this.phase === "identify") {
            this.identify();
            return;
          }
          if (this.phase === "otp") {
            this.token = verify(this.vm.s, this.id, this.code);
            this.code = "";
            this.phase = "password";
            this.vm.persist();
            return;
          }
          const s = this.vm.s,
            id = this.id,
            token = this.token,
            password = this.password;
          await complete(
            s,
            token,
            this.password,
            this.confirmation,
            () =>
              this.open &&
              this.vm.s === s &&
              this.id === id &&
              this.token === token,
          );
          this.vm.persist();
          this.close();
          try {
            if (!MasalLoginScreen.deviceAllowed(s, id))
              throw Error("تم تغيير كلمة المرور؛ الجهاز غير مطابق للحساب");
            const result = await MasalAuth.start(s, id, password);
            this.vm.loginName =
              s.users.find((u) => u.id === id)?.username ||
              s.users.find((u) => u.id === id)?.email ||
              "";
            if (result.session) this.vm.finishLogin(id);
            else {
              this.vm.logoutToLogin();
              this.vm.loginChallenge = result;
            }
            this.vm.persist();
          } catch (error) {
            this.vm.logoutToLogin();
            this.vm.loginError = error.message;
          }
        } catch (e) {
          this.error = e.message;
          this.vm.persist();
        } finally {
          this.busy = false;
        }
      },
    },
  };
  // Resolve exactly one account from its normalized registered phone.
  function findByPhone(s, phone) {
    const p = digits(phone);
    if (!/^07\d{9}$/.test(p))
      throw Error(
        "أدخل رقم هاتف عراقي صحيح، مثل 07712345678 أو +9647712345678",
      );
    const users = s.users.filter(
      (u) => u.role !== "owner" && !u.archivedAt && phoneMatches(s, u, p),
    );
    if (users.length > 1)
      throw Error("الرقم مرتبط بأكثر من حساب؛ راجع مدير النظام لتصحيح الأرقام");
    if (users.length !== 1 || !users[0].active)
      throw Error("الرقم غير مسجل لحساب فعال داخل النظام");
    return users[0];
  }
  panel.computed.phoneHint = function () {
    return (
      this.user?.passwordResetRequest?.otpDestination?.slice(-3) || "غير مسجل"
    );
  };
  const baseStart = panel.methods.start;
  panel.methods.start = function (id) {
    if (id && this.vm.s.users.find((u) => u.id === id)?.role === "owner")
      return;
    baseStart.call(this, id);
    if (id && this.vm.s.users.find((u) => u.id === id)?.passwordResetRequest)
      this.phase = "otp";
  };
  panel.methods.identify = function () {
    const u = findByPhone(this.vm.s, this.phone);
    requestFromLogin(this.vm.s, u.id);
    this.id = u.id;
    this.phase = "otp";
    this.error = "";
    this.vm.persist();
  };
  panel.methods.resend = function () {
    try {
      const u = this.user;
      if (!u || findByPhone(this.vm.s, accountPhone(this.vm.s, u)).id !== u.id)
        throw Error("تغيّر الحساب");
      requestFromLogin(this.vm.s, u.id);
      this.code = "";
      this.token = "";
      this.error = "";
      this.vm.persist();
    } catch (error) {
      this.error = error.message;
    }
  };
  function install(o) {
    o.components["password-reset-panel"] = panel;
    o.methods.canResetUserPassword = function (id) {
      try {
        target(this.engine, id);
        return true;
      } catch {
        return false;
      }
    };
    o.methods.requestPasswordReset = function (id) {
      this.run(() => {
        request(this.engine, id);
        this.persist();
      }, "تم طلب رمز التحقق؛ يظهر إدخاله لصاحب الحساب عند تسجيل الدخول");
    };
  }
  root.MasalPasswordAdmin = {
    requireTarget: target,
    validate,
    apply,
    install,
    request,
    verify,
    complete,
    directReset,
    phoneMatches,
    findByPhone,
    requestFromLogin,
  };
})(globalThis);

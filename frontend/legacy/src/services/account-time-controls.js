(function (root) {
  "use strict";
  const P = Masal.Engine.prototype,
    clockKey = "masal-account-session-clock";
  const eligible = (u) => !!u && ["employee", "supervisor"].includes(u.role);
  const empty = () => ({
    enabled: false,
    dateEnabled: false,
    startAt: "",
    endAt: "",
    hoursEnabled: false,
    startTime: "08:00",
    endTime: "16:00",
    idleEnabled: false,
    idleMinutes: 60,
    sessionEnabled: false,
    sessionMinutes: 60,
  });
  const instant = (v) => {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(v || "")) return NaN;
    const n = Date.parse(v + ":00+03:00");
    return Number.isFinite(n) &&
      new Date(n + 3 * 3600000).toISOString().slice(0, 16) === v
      ? n
      : NaN;
  };
  const minutes = (v) =>
    /^([01]\d|2[0-3]):[0-5]\d$/.test(v || "")
      ? Number(v.slice(0, 2)) * 60 + Number(v.slice(3))
      : NaN;
  function clock() {
    try {
      return JSON.parse(sessionStorage.getItem(clockKey) || "null");
    } catch {
      return null;
    }
  }
  function write(c) {
    sessionStorage.setItem(clockKey, JSON.stringify(c));
  }
  function reason(u, time = Date.now(), session = null) {
    const d = u?.timePolicy;
    if (!u?.active) return "الحساب موقوف";
    if (!eligible(u) || !d?.enabled) return "";
    if (
      d.dateEnabled &&
      (!Number.isFinite(instant(d.startAt)) ||
        !Number.isFinite(instant(d.endAt)) ||
        time < instant(d.startAt) ||
        time >= instant(d.endAt))
    )
      return "الدخول خارج مدة صلاحية الحساب";
    if (d.hoursEnabled) {
      const parts = new Intl.DateTimeFormat("en-GB", {
          timeZone: "Asia/Baghdad",
          hour: "2-digit",
          minute: "2-digit",
          hourCycle: "h23",
        }).format(time),
        m = minutes(parts),
        start = minutes(d.startTime),
        end = minutes(d.endTime);
      if (!(start < end ? m >= start && m < end : m >= start || m < end))
        return "الدخول خارج ساعات الدوام — توقيت بغداد";
    }
    if (session && session.user === u.id) {
      if (
        d.sessionEnabled &&
        time - session.started >= d.sessionMinutes * 60000
      )
        return "انتهت مدة الجلسة؛ سجّل الدخول مجددًا";
      if (d.idleEnabled && time - session.lastActivity >= d.idleMinutes * 60000)
        return "تم تسجيل الخروج بسبب الخمول";
    }
    return "";
  }
  function audit(s, id, action, details) {
    new Masal.Engine(s, id).log(action, id, null, details);
  }
  P.saveAccountTimePolicy = function (id, draft) {
    this.requirePermission("security.policies");
    if (this.actor().role !== "owner")
      throw Error("ضوابط وقت الحساب لمدير النظام فقط");
    const u = this.s.users.find((u) => u.id === id);
    if (!eligible(u))
      throw Error(
        "أوقات صلاحية الحساب لمستخدمي النظام والموظفين فقط، وليست لحسابات الوكلاء أو نقاط البيع",
      );
    const d = { ...empty() };
    for (const key of Object.keys(d)) if (key in draft) d[key] = draft[key];
    for (const k of [
      "enabled",
      "dateEnabled",
      "hoursEnabled",
      "idleEnabled",
      "sessionEnabled",
    ])
      d[k] = !!d[k];
    if (d.enabled) {
      if (
        !["dateEnabled", "hoursEnabled", "idleEnabled", "sessionEnabled"].some(
          (k) => d[k],
        )
      )
        throw Error("فعّل قيدًا واحدًا على الأقل");
      if (
        d.dateEnabled &&
        (!Number.isFinite(instant(d.startAt)) ||
          !Number.isFinite(instant(d.endAt)) ||
          instant(d.endAt) <= instant(d.startAt))
      )
        throw Error("حدد بداية ونهاية صالحتين؛ النهاية بعد البداية");
      if (
        d.hoursEnabled &&
        (!Number.isFinite(minutes(d.startTime)) ||
          !Number.isFinite(minutes(d.endTime)) ||
          d.startTime === d.endTime)
      )
        throw Error("حدد ساعات دوام مختلفة للبداية والنهاية");
      for (const [flag, key] of [
        ["idleEnabled", "idleMinutes"],
        ["sessionEnabled", "sessionMinutes"],
      ])
        if (d[flag]) {
          const n = Number(d[key]);
          if (!Number.isSafeInteger(n) || n < 1 || n > 525600)
            throw Error("المدة بالدقائق عدد صحيح موجب");
          d[key] = n;
        }
    }
    const before = Masal.clone(u.timePolicy || null);
    u.timePolicy = d;
    this.log("تعديل أوقات صلاحية الحساب", id, before, d);
    return d;
  };
  const required = P.require;
  P.require = function (...args) {
    required.apply(this, args);
    const message = reason(this.actor(), Date.now(), clock());
    if (message) throw Error(message);
  };
  const challenges = new Map();
  MasalAuth.expire = function (s, time = Date.now()) {
    this.init(s);
    for (const session of s.localSessions) {
      if (!session.active) continue;
      const u = s.users.find((u) => u.id === session.user),
        custom = eligible(u) && u.timePolicy?.enabled,
        message =
          reason(u, time, {
            user: session.user,
            started: session.created,
            lastActivity: session.lastActivity,
          }) ||
          (!custom &&
          time - session.lastActivity >=
            Math.max(1, Number(s.settings.idle) || 60) * 60000
            ? "انتهت الجلسة بسبب الخمول"
            : "");
      if (message) {
        session.active = false;
        (s.securityEvents ??= []).unshift({
          id: Masal.id("SEC"),
          user: session.user,
          action: message,
          time: new Date(time).toISOString(),
        });
      }
    }
  };
  for (const method of ["start", "confirm"]) {
    const original = MasalAuth[method];
    MasalAuth[method] = async function (s, ...args) {
      let id = method === "start" ? args[0] : challenges.get(args[0]);
      try {
        const result = await original.call(this, s, ...args);
        id = result.session?.user || id;
        if (method === "start" && result.id) challenges.set(result.id, id);
        const u = s.users.find((u) => u.id === id);
        if (u) {
          const message = reason(u);
          if (message) {
            if (result.session) result.session.active = false;
            throw Error(message);
          }
        }
        if (method === "confirm") challenges.delete(args[0]);
        return result;
      } catch (e) {
        const u = s.users.find((u) => u.id === id),
          message = u && reason(u);
        if (message)
          audit(s, id, "دخول مرفوض بسبب وقت الحساب", { reason: message });
        throw e;
      }
    };
  }
  const panel = {
    data() {
      return { userId: "", query: "", draft: empty() };
    },
    computed: {
      vm() {
        return this.$root;
      },
      users() {
        return this.vm.s.users.filter(
          (u) =>
            eligible(u) &&
            (u.id === this.userId ||
              !this.query ||
              [u.name, u.username, u.id].some((x) =>
                String(x || "").includes(this.query),
              )),
        );
      },
      saved() {
        return this.vm.s.users.filter((u) => eligible(u) && u.timePolicy);
      },
    },
    watch: {
      userId() {
        this.load();
      },
      "vm.currentUser"() {
        this.userId = "";
        this.load();
      },
    },
    methods: {
      load() {
        this.draft = {
          ...empty(),
          ...this.vm.s.users.find((u) => u.id === this.userId)?.timePolicy,
        };
      },
      save() {
        this.vm.run(() => {
          this.vm.engine.saveAccountTimePolicy(this.userId, this.draft);
          this.vm.persist();
        }, "تم حفظ أوقات صلاحية الحساب");
      },
      edit(u) {
        this.query = "";
        this.userId = u.id;
        this.load();
      },
    },
  };
  function install(o) {
    NAV.find((g) => g.title === "إدارة النظام").items.push({
      id: "accountTime",
      label: "أوقات صلاحية الحساب",
      icon: "◷",
      subtitle: "",
    });
    o.components["account-time-settings"] = panel;
    const can = o.methods.can;
    o.methods.can = function (k) {
      if (k.startsWith("accountTime."))
        return (
          this.actor.role === "owner" && this.engine.can("security.policies")
        );
      return can.call(this, k);
    };
    const finish = o.methods.finishLogin;
    o.methods.finishLogin = function (id) {
      const u = this.s.users.find((u) => u.id === id),
        message = reason(u);
      if (message) {
        audit(this.s, id, "دخول مرفوض بسبب وقت الحساب", { reason: message });
        this.persist();
        throw Error(message);
      }
      write({ user: id, started: Date.now(), lastActivity: Date.now() });
      return finish.call(this, id);
    };
    const logout = o.methods.logoutToLogin;
    o.methods.logoutToLogin = function (...args) {
      sessionStorage.removeItem(clockKey);
      for (const s of this.s.localSessions || [])
        if (s.user === this.currentUser) s.active = false;
      return logout.apply(this, args);
    };
    o.methods.checkAccountTime = function () {
      if (this.loginScreen) return false;
      const u = this.actor;
      let c = clock();
      if (!c || c.user !== u.id) {
        c = { user: u.id, started: Date.now(), lastActivity: Date.now() };
        write(c);
      }
      const message = reason(u, Date.now(), c);
      if (!message) return true;
      audit(this.s, u.id, "خروج تلقائي بسبب ضوابط الوقت", { reason: message });
      this.logoutToLogin();
      this.loginError = message;
      this.persist();
      return false;
    };
    const mounted = o.mounted;
    o.mounted = function () {
      const id = sessionStorage.getItem("masal-login-user"),
        u = this.s.users.find((u) => u.id === id);
      let message = "";
      if (u) {
        const c = clock();
        message = reason(u, Date.now(), c);
        if (
          !message &&
          eligible(u) &&
          u.timePolicy?.enabled &&
          (u.timePolicy.idleEnabled || u.timePolicy.sessionEnabled) &&
          (!c || c.user !== id)
        )
          message = "سجّل الدخول لبدء جلسة جديدة";
        if (message) {
          sessionStorage.removeItem("masal-login-user");
          sessionStorage.removeItem(clockKey);
          audit(this.s, id, "جلسة مرفوضة بسبب ضوابط الوقت", {
            reason: message,
          });
        }
      }
      mounted?.call(this);
      if (message) {
        this.logoutToLogin();
        this.loginError = message;
        this.persist();
      }
      this.accountTimer = setInterval(() => this.checkAccountTime(), 1000);
      this.accountActivity = () => {
        if (!this.checkAccountTime()) return;
        const c = clock();
        if (c && Date.now() - c.lastActivity >= 1000) {
          c.lastActivity = Date.now();
          write(c);
          for (const session of this.s.localSessions || [])
            if (session.active && session.user === c.user)
              session.lastActivity = c.lastActivity;
        }
      };
      for (const event of ["pointerdown", "keydown", "touchstart", "wheel"])
        window.addEventListener(event, this.accountActivity, { passive: true });
      this.accountFocus = () => this.checkAccountTime();
      window.addEventListener("focus", this.accountFocus);
      document.addEventListener("visibilitychange", this.accountFocus);
    };
    const unmount = o.beforeUnmount;
    o.beforeUnmount = function () {
      clearInterval(this.accountTimer);
      for (const event of ["pointerdown", "keydown", "touchstart", "wheel"])
        window.removeEventListener(event, this.accountActivity);
      window.removeEventListener("focus", this.accountFocus);
      document.removeEventListener("visibilitychange", this.accountFocus);
      unmount?.call(this);
    };
  }
  root.MasalAccountTime = { install, reason, empty, instant, eligible };
})(globalThis);

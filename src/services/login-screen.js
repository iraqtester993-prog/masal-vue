(function (root) {
  function currentDeviceSerial() {
    try {
      return String(
        root.MasalDevice?.serial ||
          root.MASAL_DEVICE_SERIAL ||
          localStorage.getItem("masal-device-serial") ||
          "",
      ).trim();
    } catch {
      return String(
        root.MasalDevice?.serial || root.MASAL_DEVICE_SERIAL || "",
      ).trim();
    }
  }
  function deviceAllowed(state, id) {
    const user = state.users.find((u) => u.id === id);
    if (user?.role !== "pos") return true;
    const point = state.pos.find((p) => p.id === user.pos);
    if (!point?.serialBinding) return true;
    const actual = currentDeviceSerial(),
      expected = String(point.serial || "").trim();
    return (
      !!actual && !!expected && actual.toLowerCase() === expected.toLowerCase()
    );
  }
  function install(o) {
    const data = o.data;
    o.data = function () {
      let remembered = "";
      try {
        remembered = localStorage.getItem("masal-login-name") || "";
      } catch {}
      return {
        ...data.call(this),
        loginScreen: true,
        loginName: remembered,
        loginPassword: "",
        loginRemember: !!remembered,
        loginReveal: false,
        loginBusy: false,
        loginError: "",
        loginChallenge: null,
        loginCode: "",
      };
    };
    const mounted = o.mounted;
    o.mounted = function () {
      // Restore the actor before the existing hash router checks page permissions.
      try {
        const id = sessionStorage.getItem("masal-login-user");
        if (
          location.hash !== "#login" &&
          this.s.users.some((u) => u.id === id && u.active) &&
          deviceAllowed(this.s, id)
        ) {
          this.currentUser = id;
          this.switchUser();
          this.loginScreen = false;
        } else if (id && !deviceAllowed(this.s, id))
          sessionStorage.removeItem("masal-login-user");
      } catch {}
      mounted?.call(this);
    };
    const switchUser = o.methods.switchUser;
    o.methods.switchUser = function (...args) {
      const result = switchUser.apply(this, args);
      if (!this.loginScreen) {
        try {
          sessionStorage.setItem("masal-login-user", this.currentUser);
        } catch {}
      }
      return result;
    };
    o.computed.loginDemoAvailable = function () {
      return this.s.users.some(
        (u) => u.role === "owner" && u.active && !u.credentials,
      );
    };
    o.methods.finishLogin = function (id) {
      if (!deviceAllowed(this.s, id)) {
        const user = this.s.users.find((u) => u.id === id),
          point = this.s.pos.find((p) => p.id === user?.pos),
          actual = currentDeviceSerial();
        throw Error(
          actual
            ? "الرقم التسلسلي لهذا الجهاز لا يطابق الرقم المعتمد لنقطة البيع"
            : "تعذر قراءة الرقم التسلسلي للجهاز؛ لا يمكن تشغيل هذا الحساب لأن مطابقة الجهاز مفعلة",
        );
      }
      this.currentUser = id;
      this.switchUser();
      this.loginScreen = false;
      this.loginPassword = "";
      this.loginCode = "";
      this.loginChallenge = null;
      this.modal = null;
      this.go("dashboard");
      try {
        sessionStorage.setItem("masal-login-user", id);
        if (this.loginRemember)
          localStorage.setItem("masal-login-name", this.loginName.trim());
        else localStorage.removeItem("masal-login-name");
      } catch {}
    };
    o.methods.submitLogin = async function () {
      if (this.loginBusy) return;
      this.loginBusy = true;
      this.loginError = "";
      try {
        if (this.loginChallenge) {
          const result = await MasalAuth.confirm(
            this.s,
            this.loginChallenge.id,
            this.loginCode,
          );
          if (result.session) this.finishLogin(result.session.user);
          return;
        }
        const name = this.loginName.trim().toLowerCase();
        const matches = this.s.users.filter(
          (u) =>
            u.active &&
            [u.email, u.username, u.name].some(
              (v) => v && v.toLowerCase() === name,
            ),
        );
        if (matches.length !== 1) throw Error("بيانات الدخول غير صحيحة");
        const u = matches[0];
        const result = await MasalAuth.start(this.s, u.id, this.loginPassword);
        if (result.session) this.finishLogin(u.id);
        else {
          this.loginChallenge = result;
          this.loginPassword = "";
        }
        this.persist();
      } catch (e) {
        this.loginError = e.message;
      } finally {
        this.loginBusy = false;
      }
    };
    o.methods.enterLoginDemo = function () {
      const u = this.s.users.find(
        (u) => u.role === "owner" && u.active && !u.credentials,
      );
      if (u) this.finishLogin(u.id);
    };
    o.methods.logoutToLogin = function () {
      try {
        sessionStorage.removeItem("masal-login-user");
      } catch {}
      this.modal = null;
      this.loginScreen = true;
      this.loginPassword = "";
      this.loginError = "";
      this.loginCode = "";
      this.loginChallenge = null;
      location.hash = "login";
    };
  }
  root.MasalLoginScreen = { install, currentDeviceSerial, deviceAllowed };
})(globalThis);

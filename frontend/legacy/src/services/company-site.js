(function (root) {
  "use strict";
  const sections = [
    ["slides", "السلايدر"],
    ["about", "عن الشركة"],
    ["activities", "النشاطات"],
    ["offers", "العروض"],
    ["projects", "المشاريع"],
    ["social", "مواقع التواصل"],
    ["care", "خدمة العملاء"],
  ];
  function profile(p = {}) {
    const result = {
      name: "ماسال",
      tagline: "البطاقات والخدمات الإلكترونية",
      about: "",
      phone: "",
      email: "",
      address: "",
      website: "",
      whatsapp: "",
      hours: "",
      logo: "",
      slides: [],
      activities: [],
      offers: [],
      projects: [],
      social: [],
      ...Masal.clone(p),
      visibility: Object.fromEntries(
        sections.map(([k]) => [k, p.visibility?.[k] !== false]),
      ),
    };
    for (const key of ["slides", "activities", "offers", "projects"])
      result[key] = result[key].map((x) => ({
        ...x,
        title: x.title || x.caption || "",
        visible: x.visible !== false,
      }));
    return result;
  }
  function url(s) {
    if (!s) return "";
    try {
      const u = new URL(String(s));
      if (u.protocol !== "https:") throw Error();
      return u.href;
    } catch {
      throw Error("أدخل رابطًا صحيحًا يبدأ بـ https://");
    }
  }
  function manager(e) {
    e.requirePermission("company.edit");
    if (!(
      e.actor().role === "owner" ||
      (e.actor().role === "employee" && e.actor().staffAccount === "@system")
    ))
      throw Error("إدارة موقع الشركة للإدارة فقط");
  }
  Masal.Engine.prototype.saveCompany = function (draft) {
    manager(this);
    const p = profile(draft);
    for (const k of [
      "name",
      "tagline",
      "about",
      "phone",
      "email",
      "address",
      "hours",
      "whatsapp",
    ])
      p[k] = String(p[k] || "").trim();
    if (!p.name) throw Error("اسم الشركة مطلوب");
    if (p.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(p.email))
      throw Error("البريد الإلكتروني غير صحيح");
    p.website = url(p.website);
    p.logo = MasalFeatureUpdates.attachment(p.logo);
    for (const key of ["slides", "activities", "offers", "projects"]) {
      if (p[key].length > 30) throw Error("الحد الأقصى 30 عنصرًا في القسم");
      p[key] = p[key].map((x) => {
        const title = String(
          x.title || x.caption || (key === "slides" && x.image ? p.name : ""),
        ).trim();
        if (!title)
          throw Error(
            "أكمل عنوان كل عنصر في " + sections.find((s) => s[0] === key)[1],
          );
        return {
          title,
          caption: title,
          description: String(x.description || "").trim(),
          image: MasalFeatureUpdates.attachment(x.image),
          link: url(x.link),
          visible: x.visible !== false,
        };
      });
    }
    p.social = p.social.map((x) => {
      if (!String(x.name || "").trim() || !x.url)
        throw Error("أكمل اسم ورابط موقع التواصل");
      return { name: String(x.name).trim(), url: url(x.url) };
    });
    this.s.companyProfile = p;
    this.log("تحديث موقع الشركة", "company", null, { sections: p.visibility });
  };
  Masal.Engine.prototype.companyInquiry = function (form) {
    this.requirePermission("company.view");
    if (profile(this.s.companyProfile).visibility.care === false)
      throw Error("خدمة العملاء غير متاحة حاليًا");
    const name = String(form.name || "").trim(),
      contact = String(form.contact || "").trim(),
      message = String(form.message || "").trim();
    if (!name || !contact || !message)
      throw Error("أكمل الاسم ووسيلة التواصل والرسالة");
    if (name.length > 120 || contact.length > 150 || message.length > 3000)
      throw Error("الرسالة أطول من الحد المسموح");
    const r = {
      id: Masal.id("CARE"),
      name,
      contact,
      message,
      user: this.user,
      time: new Date().toISOString(),
      status: "جديدة",
    };
    (this.s.companyInquiries ??= []).unshift(r);
    this.log("طلب خدمة عملاء", r.id, null, { name });
    return r;
  };
  Masal.Engine.prototype.resolveCompanyInquiry = function (id) {
    manager(this);
    const r = this.s.companyInquiries?.find((x) => x.id === id);
    if (!r) throw Error("الطلب غير موجود");
    r.status = r.status === "جديدة" ? "تمت المتابعة" : "جديدة";
    this.log("متابعة طلب خدمة عملاء", id, null, { status: r.status });
  };
  const site = {
    data() {
      let siteTheme = this.$root.companyTheme || "light";
      try {
        siteTheme = localStorage.getItem("masal-company-theme") || siteTheme;
      } catch {}
      if (
        this.$root.currentUser === null &&
        /^masal-site-theme:(dark|light)$/.test(window.name)
      )
        siteTheme = window.name.split(":")[1];
      return {
        siteTheme: siteTheme === "dark" ? "dark" : "light",
        profileNavOpen: false,
        saveError: "",
        opening: false,
        introTimer: null,
        editing: false,
        tab: "about",
        draft: null,
        index: 0,
        paused: false,
        autoplayPaused: false,
        busy: {},
        timer: null,
        inquiry: { name: "", contact: "", message: "" },
        sent: false,
        sending: false,
        details: null,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      p() {
        return profile(this.vm.s.companyProfile);
      },
      editable() {
        return (
          this.vm.can("company.edit") &&
          (this.vm.actor.role === "owner" ||
            this.vm.actor.staffAccount === "@system")
        );
      },
      sections() {
        return sections;
      },
      slides() {
        return this.p.visibility.slides
          ? this.p.slides.filter((x) => x.visible !== false)
          : [];
      },
      current() {
        return this.slides[this.index % Math.max(1, this.slides.length)];
      },
      links() {
        return sections.filter(([k]) => k !== "slides" && this.visible(k));
      },
      pending() {
        return Object.values(this.busy).some(Boolean);
      },
      inbox() {
        return this.editable ? this.vm.s.companyInquiries || [] : [];
      },
    },
    watch: {
      "vm.page"(page) {
        if (page === "company") this.openProfile();
        else this.closeProfile();
      },
      "vm.currentUser"() {
        this.editing = false;
        this.draft = null;
        this.details = null;
        this.inquiry = { name: "", contact: "", message: "" };
        this.sent = false;
      },
    },
    mounted() {
      if (this.vm.page === "company") this.openProfile();
    },
    beforeUnmount() {
      this.closeProfile();
    },
    methods: {
      toggleSiteTheme() {
        this.siteTheme = this.siteTheme === "dark" ? "light" : "dark";
        try {
          localStorage.setItem("masal-company-theme", this.siteTheme);
        } catch {}
        if (this.vm.currentUser === null)
          window.name = "masal-site-theme:" + this.siteTheme;
        this.vm.saveCompanyTheme?.(this.siteTheme);
      },
      endIntro() {
        clearTimeout(this.introTimer);
        this.$refs.introDialog?.close();
        this.opening = false;
        this.$nextTick(() => this.$refs.themeButton?.focus());
      },
      openProfile() {
        clearTimeout(this.introTimer);
        this.editing = false;
        this.details = null;
        this.opening = true;
        this.vm.companyIntroSeen = true;
        this.start();
        if (this.opening) {
          this.$nextTick(() => {
            this.$refs.introDialog?.showModal();
          });
          this.introTimer = setTimeout(() => this.endIntro(), 3000);
        }
      },
      closeProfile() {
        clearTimeout(this.introTimer);
        this.opening = false;
        this.stop();
      },
      dismiss() {
        const target = this.vm.companyReturn || "dashboard";
        this.vm.go(target === "company" ? "dashboard" : target);
      },
      visible(k) {
        return (
          this.p.visibility[k] &&
          (k === "care" ||
            (k === "about" && !!this.p.about) ||
            (Array.isArray(this.p[k]) &&
              this.p[k].some((x) => x.visible !== false)))
        );
      },
      items(k) {
        return this.p[k].filter((x) => x.visible !== false);
      },
      safe(s) {
        try {
          return url(s);
        } catch {
          return "";
        }
      },
      phone(s) {
        return "tel:" + String(s || "").replace(/[^+\d]/g, "");
      },
      wa() {
        return "https://wa.me/" + this.p.whatsapp.replace(/\D/g, "");
      },
      start() {
        this.stop();
        this.timer = setInterval(() => {
          if (
            !this.editing &&
            !this.paused &&
            !this.autoplayPaused &&
            !document.hidden &&
            !matchMedia("(prefers-reduced-motion: reduce)").matches &&
            this.slides.length > 1
          )
            this.next(1);
        }, 5500);
      },
      stop() {
        clearInterval(this.timer);
      },
      next(n) {
        if (this.slides.length)
          this.index =
            (this.index + n + this.slides.length) % this.slides.length;
      },
      jump(k) {
        this.profileNavOpen = false;
        this.$el.querySelector("#company-" + k)?.scrollIntoView({
          behavior: matchMedia("(prefers-reduced-motion: reduce)").matches
            ? "auto"
            : "smooth",
          block: "start",
        });
      },
      edit() {
        this.saveError = "";
        this.draft = profile(this.p);
        this.tab = "about";
        this.editing = true;
        this.busy = {};
      },
      add() {
        this.draft[this.tab].push({
          title: "",
          caption: "",
          description: "",
          image: "",
          link: "",
          visible: true,
        });
      },
      move(i, n) {
        const a = this.draft[this.tab],
          j = i + n;
        if (j >= 0 && j < a.length) [a[i], a[j]] = [a[j], a[i]];
      },
      save() {
        if (this.pending) return;
        this.saveError = "";
        try {
          this.vm.engine.saveCompany(this.draft);
          this.vm.persist();
          this.index = 0;
          this.editing = false;
          this.vm.notify("تم حفظ موقع الشركة");
        } catch (e) {
          this.saveError = e.message;
          this.vm.notify(e.message, true);
        }
      },
      async send() {
        if (this.sending) return;
        this.sending = true;
        try {
          await this.vm.engine.companyInquiry(this.inquiry);
          this.inquiry = { name: "", contact: "", message: "" };
          this.sent = true;
          this.saveError = "";
        } catch (e) {
          this.saveError = e.message;
          this.vm.notify(e.message, true);
        } finally {
          this.sending = false;
        }
      },
      resolve(id) {
        this.vm.run(
          () => this.vm.engine.resolveCompanyInquiry(id),
          "تم تحديث الطلب",
        );
      },
    },
  };

  function serializePublic(value) {
    if (typeof value === "function") {
      let source = value.toString();
      if (!/^function\b|^async\s+function\b/.test(source))
        source =
          (source.startsWith("async ") ? "async function " : "function ") +
          source.slice(source.indexOf("("));
      return "(" + source + ")";
    }
    if (Array.isArray(value))
      return "[" + value.map(serializePublic).join(",") + "]";
    if (value && typeof value === "object")
      return (
        "{" +
        Object.entries(value)
          .map(([k, v]) => JSON.stringify(k) + ":" + serializePublic(v))
          .join(",") +
        "}"
      );
    return JSON.stringify(value);
  }
  function openPublic(vm) {
    if (!vm.can("company.view")) return;
    try {
      const clean = profile(vm.s.companyProfile);
      for (const key of [
        "slides",
        "activities",
        "offers",
        "projects",
        "social",
      ])
        clean[key] = clean.visibility[key]
          ? clean[key].filter((x) => x.visible !== false)
          : [];
      if (!clean.visibility.about) clean.about = "";
      if (!clean.visibility.care)
        for (const key of ["phone", "email", "address", "whatsapp", "hours"])
          clean[key] = "";
      const publicSite = {
        ...site,
        components: {},
        template: site.template.replace(
          '<button @click="dismiss">رجوع إلى الصفحة السابقة ←</button>',
          "",
        ),
      };
      let seen = false;
      try {
        seen = sessionStorage.getItem("masal-company-public-intro") === "yes";
        sessionStorage.setItem("masal-company-public-intro", "yes");
      } catch {}
      delete publicSite.render;
      const channelName = "masal-care-" + crypto.randomUUID();
      const receiveInquiry = (event) => {
        const m = event.data;
        if (event.source !== w || m?.channel !== channelName) return;
        if (m.type === "theme" && ["dark", "light"].includes(m.theme)) {
          try {
            localStorage.setItem("masal-company-theme", m.theme);
          } catch {}
          return;
        }
        if (m.type !== "inquiry" || typeof m.id !== "string") return;
        try {
          vm.engine.companyInquiry(m.form || {});
          vm.persist();
          w.postMessage({ channel: channelName, id: m.id, ok: true }, "*");
        } catch (error) {
          w.postMessage(
            { channel: channelName, id: m.id, ok: false, error: error.message },
            "*",
          );
        }
      };
      const bridge =
        "const careParent=window.opener;window.opener=null;const careToken=" +
        JSON.stringify(channelName) +
        ';const companyInquiry=form=>new Promise((resolve,reject)=>{if(!careParent||careParent.closed){reject(Error("افتح البروفايل مجدداً من النظام حتى يتم حفظ رسالتك."));return}const id=Date.now().toString(36)+Math.random().toString(36).slice(2);const timer=setTimeout(()=>{window.removeEventListener("message",receive);reject(Error("افتح النظام الأصلي حتى يتم حفظ رسالتك، ثم حاول مجدداً."))},8000);function receive(event){if(event.source!==careParent||event.data?.channel!==careToken||event.data?.id!==id)return;clearTimeout(timer);window.removeEventListener("message",receive);event.data.ok?resolve():reject(Error(event.data.error))}window.addEventListener("message",receive);careParent.postMessage({channel:careToken,type:"inquiry",id,form:JSON.parse(JSON.stringify(form))},"*")});';
      let companyTheme = "light";
      try {
        companyTheme = localStorage.getItem("masal-company-theme") || "light";
      } catch {}
      const bootstrap =
        MasalCompanyVueSource +
        bridge +
        ";const publicLabels=" +
        JSON.stringify(MasalLocale.dictionaries[vm.lang] || {}) +
        ";const Masal={clone:v=>JSON.parse(JSON.stringify(v))};const sections=" +
        JSON.stringify(sections) +
        ";const profile=" +
        profile.toString() +
        ";const url=" +
        url.toString() +
        ";const site=" +
        serializePublic(publicSite) +
        ";Vue.createApp({components:{CompanyPage:site},data(){return {engine:{companyInquiry},companyTheme:" +
        JSON.stringify(companyTheme) +
        ',page:"company",companyIntroSeen:' +
        seen +
        ',currentUser:null,actor:{role:"visitor"},s:{companyProfile:' +
        JSON.stringify(clean) +
        ',companyInquiries:[]}}},methods:{tr(value){if(typeof value!==\"string\")return value;const text=value.trim();return publicLabels[text]?value.replace(text,()=>publicLabels[text]):value},saveCompanyTheme(theme){careParent?.postMessage({channel:careToken,type:"theme",theme},"*")},can(){return false},go(){window.close()},notify(){},money(v){return String(v)}},template:"<company-page></company-page>"}).mount("#public-profile");';
      const styles = [
        ...document.querySelectorAll('style,link[rel="stylesheet"]'),
      ]
        .map((el) => {
          const clone = el.cloneNode(true);
          if (clone.tagName === "LINK") clone.href = el.href;
          return clone.outerHTML;
        })
        .join("");
      const title = clean.name.replace(
        /[&<>]/g,
        (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;" })[c],
      );
      const publicHtml =
        '<!doctype html><html lang="ar" dir="rtl" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' +
        title +
        "</title>" +
        styles +
        '<style>body{margin:0;background:#f3f7f8}#public-profile{width:100%;min-height:100dvh;margin:0;padding:0}.site-tools{display:none}.company-profile-content{padding:0}.site-surface{border-radius:0;border-top:0}.site-care{grid-template-columns:1fr 1fr}@media(max-width:760px){.site-care{grid-template-columns:1fr}}.site-contact-links{grid-template-columns:repeat(auto-fit,minmax(180px,1fr))}@media(max-width:600px){#public-profile{padding:0}}</style></head><body><div id="public-profile"></div><script>' +
        bootstrap.replace(/<\/script/gi, "<\\/script") +
        "</script></body></html>";
      const pageUrl = URL.createObjectURL(
        new Blob([publicHtml], { type: "text/html;charset=utf-8" }),
      );
      const w = window.open(pageUrl, "_blank");
      if (w) {
        window.addEventListener("message", receiveInquiry);
        const closedCheck = setInterval(() => {
          if (w.closed) {
            clearInterval(closedCheck);
            window.removeEventListener("message", receiveInquiry);
            URL.revokeObjectURL(pageUrl);
          }
        }, 2000);
      } else {
        window.removeEventListener("message", receiveInquiry);
        URL.revokeObjectURL(pageUrl);
        vm.notify("اسمح بفتح تبويب بروفايل الشركة", true);
      }
    } catch (error) {
      console.error("Company profile could not open", error);
      vm.notify(
        "تعذر فتح بروفايل الشركة. حدّث صفحة النظام وحاول مجدداً.",
        true,
      );
    }
  }

  function install(o) {
    const nav = o.computed.navGroups;
    o.computed.navGroups = function () {
      const groups = nav.call(this);
      if (
        this.can("company.edit") &&
        (this.actor.role === "owner" || this.actor.staffAccount === "@system")
      ) {
        let group = groups.find((g) => g.title === "إدارة النظام");
        if (!group) {
          group = { title: "إدارة النظام", items: [] };
          groups.push(group);
        }
        if (!this.navSearch || "تعديل بروفايل الشركة".includes(this.navSearch))
          group.items.push({
            id: "company-settings",
            label: "تعديل بروفايل الشركة",
            icon: "✎",
            subtitle: "",
          });
      }
      return groups;
    };
    const previousGo = o.methods.go;
    o.methods.openCompanyManager = function () {
      if (
        !this.can("company.edit") ||
        !(this.actor.role === "owner" || this.actor.staffAccount === "@system")
      )
        return;
      previousGo.call(this, "company");
      this.$nextTick(() => {
        const page = this.$refs.companyPage;
        if (page?.editable) {
          page.closeProfile();
          page.edit();
        }
      });
    };
    o.methods.go = function (page, ...args) {
      if (page === "company-settings") return this.openCompanyManager();
      if (page === "company") return openPublic(this);
      return previousGo.call(this, page, ...args);
    };
    site.components = { "image-attachment": o.components["image-attachment"] };
    o.components["company-page"] = site;
    const data = o.data;
    o.data = function () {
      const d = data.call(this);
      d.companyIntroSeen = false;
      d.s.companyProfile = profile(d.s.companyProfile);
      d.s.companyInquiries ??= [];
      return d;
    };
  }
  root.MasalCompanySite = { install };
})(globalThis);

(function (root) {
  "use strict";
  const P = Masal.Engine.prototype;
  const valid = (p) =>
    p &&
    typeof p.lat === "number" &&
    typeof p.lng === "number" &&
    Number.isFinite(p.lat) &&
    Number.isFinite(p.lng) &&
    Math.abs(p.lat) <= 85 &&
    Math.abs(p.lng) <= 180;
  const kinds = {
    owner: "مدير النظام",
    supervisor: "موظف",
    employee: "موظف",
    main: "وكيل رئيسي",
    sub: "فرع",
    pos: "نقطة بيع",
  };
  const symbols = {
    owner: "★",
    supervisor: "●",
    employee: "●",
    main: "◆",
    sub: "■",
    pos: "▲",
  };
  const connected = (u, now = Date.now()) => {
    const p = u?.mapPresence,
      elapsed = now - Date.parse(p?.lastSeen);
    return (
      !!u?.active &&
      !u.archivedAt &&
      p?.connected === true &&
      elapsed >= 0 &&
      elapsed < 120000
    );
  };
  P.mapUsers = function (now = Date.now()) {
    this.requirePermission("map.view");
    const me = this.actor(),
      agents = new Map(this.s.agents.map((a) => [a.id, a])),
      points = new Map(this.s.pos.map((p) => [p.id, p]));
    return this.s.users
      .filter((u) => {
        if (u.role === "owner") return false;
        if (me.role === "owner") return true;
        const a =
          u.role === "pos"
            ? points.get(u.pos)?.agent
            : u.staffAccount || u.agent;
        if (!a || a === "@system") return u.id === me.id;
        return this.allowed(a);
      })
      .map((u) => {
        const account =
            u.role === "pos"
              ? points.get(u.pos)
              : agents.get(u.staffAccount || u.agent),
          presence = u.mapPresence || {},
          device = valid(presence.location) ? presence.location : null;
        const fixed =
            account &&
            account.lat !== "" &&
            account.lng !== "" &&
            account.lat != null &&
            account.lng != null
              ? { lat: Number(account.lat), lng: Number(account.lng) }
              : null,
          location = device || (valid(fixed) ? fixed : null),
          last = Date.parse(presence.lastSeen);
        const network = account
            ? agents.get(this.main(account.agent || account.id))
            : null,
          networkColor = /^#[0-9a-f]{6}$/i.test(network?.color || "")
            ? network.color
            : "#0898b5";
        const parentId = u.role === "pos" ? account?.agent : account?.parent,
          parent = parentId ? agents.get(parentId) : null;
        return {
          networkId: network?.id || "",
          networkName: network?.name || "حسابات النظام",
          networkColor,
          id: u.id,
          name: u.name,
          role: u.role,
          kind: kinds[u.role] || "مستخدم",
          symbol: symbols[u.role] || "●",
          account: account?.name || "إدارة النظام",
          agent: u.role === "pos" ? account?.agent : u.staffAccount || u.agent,
          parentName: parent?.name || "",
          phone: account?.phone || u.phone || "",
          city: account?.city || "",
          address: account?.address || "",
          login: u.email || u.username || "",
          active: u.active,
          online: connected(u, now) && account?.active !== false,
          lastSeen: presence.lastSeen || "",
          location,
          source: device
            ? "آخر موقع للجهاز"
            : location
              ? "موقع الحساب المسجل"
              : "بدون موقع",
          locationTime: device?.time || "",
          accuracy: device?.accuracy,
        };
      });
  };
  function install(o) {
    const permission = MasalAccess.catalog.find((p) => p.key === "map.view");
    permission.label = "عرض خريطة المستخدمين ومواقعهم";
    permission.sensitive = true;
    o.components["user-map"] = component;
    o.components["location-gate"] = {
      computed: {
        vm() {
          return this.$root;
        },
        required() {
          return (
            !this.vm.loginScreen &&
            this.vm.actor?.active &&
            ["main", "sub", "pos"].includes(this.vm.actor.role) &&
            !this.vm.locationReady
          );
        },
      },
      watch: {
        required() {
          this.sync();
        },
      },
      mounted() {
        this.sync();
      },
      beforeUnmount() {
        this.$refs.dialog?.close();
      },
      methods: {
        sync() {
          this.$nextTick(() => {
            const d = this.$refs.dialog;
            if (this.required && !d.open) d.showModal();
            else if (!this.required && d.open) d.close();
          });
        },
      },
    };
    const data = o.data;
    o.data = function () {
      return {
        ...data.call(this),
        locationSharing: false,
        locationReady: false,
        locationPermission: "",
        locationStatus: "",
      };
    };
    o.methods.stopLocationSharing = function () {
      if (this._locationWatch != null)
        navigator.geolocation?.clearWatch(this._locationWatch);
      this._locationWatch = null;
      this._locationGeneration = (this._locationGeneration || 0) + 1;
      this.locationSharing = false;
      this.locationReady = false;
      this.locationStatus = "مشاركة الموقع متوقفة";
    };
    o.methods.startLocationSharing = function () {
      if (this.loginScreen || !this.actor?.active) return;
      if (!navigator.geolocation) {
        this.locationStatus = "تحديد الموقع غير مدعوم";
        return;
      }
      this.stopLocationSharing();
      this.locationSharing = true;
      this.locationStatus = "جارٍ طلب إذن الموقع…";
      const id = this.currentUser,
        generation = this._locationGeneration,
        state = this.s;
      this._locationWatch = navigator.geolocation.watchPosition(
        (position) => {
          if (
            this.loginScreen ||
            this.currentUser !== id ||
            this.s !== state ||
            !this.locationSharing ||
            generation !== this._locationGeneration
          )
            return;
          const u = this.s.users.find((u) => u.id === id && u.active),
            c = position.coords;
          if (!u || !valid({ lat: c.latitude, lng: c.longitude })) return;
          u.mapPresence ??= {};
          u.mapPresence.location = {
            lat: c.latitude,
            lng: c.longitude,
            accuracy: c.accuracy,
            time: new Date(position.timestamp).toISOString(),
          };
          this.locationReady = true;
          this.locationPermission = "granted";
          this.locationStatus = "مشاركة الموقع مفعّلة — آخر موقع محفوظ محليًا";
          this.persist();
        },
        (error) => {
          if (generation !== this._locationGeneration) return;
          this.stopLocationSharing();
          if (error.code === 1) this.locationPermission = "denied";
          this.locationStatus =
            error.code === 1
              ? "لم يُسمح بالوصول للموقع"
              : error.code === 3
                ? "انتهت مهلة تحديد الموقع؛ أعد المحاولة"
                : "الموقع غير متاح حاليًا";
          this.notify(this.locationStatus, true);
        },
        { enableHighAccuracy: true, maximumAge: 30000, timeout: 20000 },
      );
    };
    const mounted = o.mounted;
    o.mounted = function () {
      mounted?.call(this);
      this._mapUser = null;
      this._mapPulse = () => {
        if (
          this._mapUser &&
          (this.loginScreen || this._mapUser !== this.currentUser)
        ) {
          const old = this.s.users.find((u) => u.id === this._mapUser);
          if (old?.mapPresence) old.mapPresence.connected = false;
          this.stopLocationSharing();
          this._mapUser = null;
        }
        if (!this.loginScreen && this.actor?.active) {
          this._mapUser = this.currentUser;
          this.actor.mapPresence ??= {};
          this.actor.mapPresence.lastSeen = new Date().toISOString();
          this.actor.mapPresence.connected = navigator.onLine;
        }
      };
      this._mapPulse();
      this._mapTimer = setInterval(this._mapPulse, 30000);
      this._mapExit = () => {
        const u = this.s.users.find((u) => u.id === this._mapUser);
        if (u?.mapPresence) {
          u.mapPresence.connected = false;
          this.persist();
        }
      };
      window.addEventListener("pagehide", this._mapExit);
      this._locationDisposed = false;
      this._checkLocationPermission = async () => {
        try {
          const p = await navigator.permissions?.query({ name: "geolocation" });
          if (!p || this._locationDisposed) return;
          if (this._locationPermissionObject)
            this._locationPermissionObject.onchange = null;
          this._locationPermissionObject = p;
          const changed = (event = false) => {
            this.locationPermission = p.state;
            if (
              this.locationSharing &&
              (p.state === "denied" ||
                (event && p.state === "prompt" && this.locationReady))
            ) {
              this.stopLocationSharing();
              this.locationStatus =
                p.state === "denied"
                  ? "إذن الموقع محظور؛ اسمح به من إعدادات المتصفح"
                  : "تم سحب إذن الموقع؛ اضغط تفعيل الموقع مجددًا";
            }
          };
          p.onchange = () => changed(true);
          changed();
        } catch {}
      };
      this._checkLocationPermission();
      window.addEventListener("focus", this._checkLocationPermission);
    };
    for (const key of ["currentUser", "loginScreen"]) {
      const old = o.watch[key];
      o.watch[key] = function (...args) {
        if (typeof old === "function") old.apply(this, args);
        else old?.handler?.apply(this, args);
        this._mapPulse?.();
      };
    }
    const gone = o.beforeUnmount;
    o.beforeUnmount = function () {
      this._locationDisposed = true;
      if (this._locationPermissionObject)
        this._locationPermissionObject.onchange = null;
      window.removeEventListener("focus", this._checkLocationPermission);
      clearInterval(this._mapTimer);
      this.stopLocationSharing();
      window.removeEventListener("pagehide", this._mapExit);
      gone?.call(this);
    };
    const exp = o.methods.exportCurrent;
    o.methods.exportCurrent = function (...args) {
      if (this.page === "map") {
        this.engine.requirePermission("map.view");
        this.notify("اختر المستخدم على الخريطة لعرض بيانات موقعه");
        return;
      }
      return exp.apply(this, args);
    };
  }
  const component = {
    props: ["vm"],
    data: () => ({
      query: "",
      type: "",
      branch: "",
      status: "",
      custom: false,
      selected: [],
      selectedId: "",
      detailsOpen: false,
      listPage: 1,
      now: Date.now(),
      tileError: false,
      clusterIds: [],
    }),
    computed: {
      permitted() {
        return this.vm.can("map.view");
      },
      users() {
        return this.permitted ? this.vm.engine.mapUsers(this.now) : [];
      },
      networks() {
        return [
          ...new Map(
            this.rows.map((u) => [
              u.networkId,
              { id: u.networkId, name: u.networkName, color: u.networkColor },
            ]),
          ).values(),
        ];
      },
      branches() {
        const ids = new Set(this.users.map((u) => u.agent));
        return this.vm.s.agents.filter(
          (a) => ids.has(a.id) && this.vm.engine.allowed(a.id),
        );
      },
      rows() {
        const q = this.query.trim().toLowerCase(),
          tree = this.branch
            ? new Set(this.vm.engine.descendants(this.branch))
            : null;
        return this.users.filter(
          (u) =>
            (!q || (u.name + " " + u.account).toLowerCase().includes(q)) &&
            (!this.type ||
              (this.type === "employee"
                ? ["employee", "supervisor", "owner"].includes(u.role)
                : u.role === this.type)) &&
            (!tree || tree.has(u.agent)) &&
            (!this.status ||
              (this.status === "online"
                ? u.online
                : this.status === "offline"
                  ? !u.online
                  : !u.location)) &&
            (!this.custom || this.selected.includes(u.id)),
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.rows.length / 30));
      },
      listed() {
        return this.rows.slice(
          (Math.min(this.listPage, this.pages) - 1) * 30,
          Math.min(this.listPage, this.pages) * 30,
        );
      },
      chosen() {
        return this.rows.find((u) => u.id === this.selectedId);
      },
      options() {
        const q = this.query.trim().toLowerCase();
        return this.users
          .filter(
            (u) => !q || (u.name + " " + u.account).toLowerCase().includes(q),
          )
          .slice(0, 100);
      },
    },
    watch: {
      rows() {
        this.listPage = 1;
        this.clusterIds = [];
        this.$nextTick(() => this.draw());
      },
      permitted(v) {
        if (!v) {
          this._map?.remove();
          this._map = null;
        }
      },
    },
    mounted() {
      if (!this.permitted) return;
      this._map = L.map(this.$refs.canvas, {
        minZoom: 2,
        maxZoom: 19,
        zoomAnimation: false,
        markerZoomAnimation: false,
      }).setView([33.3, 44.4], 6);
      this._layer = L.layerGroup().addTo(this._map);
      this._map.createPane("offline-background");
      this._map.getPane("offline-background").style.zIndex = 150;
      if (root.MasalMapCountries)
        L.geoJSON(root.MasalMapCountries, {
          pane: "offline-background",
          interactive: false,
          style: {
            color: "#93a99d",
            weight: 1,
            fillColor: "#e9e7dc",
            fillOpacity: 1,
          },
        }).addTo(this._map);
      this._tiles = L.tileLayer(
        "https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/{z}/{y}/{x}",
        {
          maxZoom: 19,
          maxNativeZoom: 19,
          attribution:
            'Tiles © <a href="https://www.esri.com/" target="_blank" rel="noopener">Esri</a> — Esri, HERE, Garmin, USGS, Intermap, INCREMENT P, NRCan, Esri Japan, METI, Esri China (Hong Kong), Esri Korea, Esri (Thailand), NGCC, © OpenStreetMap contributors, GIS User Community',
        },
      )
        .on("tileerror", () => (this.tileError = true))
        .on("tileload", () => (this.tileError = false))
        .addTo(this._map);
      this._map.on("moveend zoomend", () => this.draw());
      this._resize = new ResizeObserver(() => this._map?.invalidateSize());
      this._resize.observe(this.$refs.canvas);
      this._timer = setInterval(() => (this.now = Date.now()), 15000);
      this.draw();
      this.fit();
    },
    beforeUnmount() {
      clearInterval(this._timer);
      this._resize?.disconnect();
      this._map?.remove();
    },
    methods: {
      retryTiles() {
        this.tileError = false;
        this._tiles?.redraw();
      },
      time(value) {
        return value
          ? new Date(value).toLocaleString("ar-IQ", {
              timeZone: "Asia/Baghdad",
            })
          : "غير متوفر";
      },
      fit() {
        const pts = this.rows
          .filter((u) => u.location)
          .map((u) => [u.location.lat, u.location.lng]);
        if (pts.length)
          this._map?.fitBounds(pts, { padding: [40, 40], maxZoom: 13 });
      },
      closeUserPopup() {
        this.detailsOpen = false;
        this.$nextTick(
          () => this._popupFocus?.isConnected && this._popupFocus.focus(),
        );
      },
      choose(u) {
        this.selectedId = u.id;
        this.detailsOpen = false;
        this.clusterIds = [];
        if (u.location)
          this._map?.setView(
            [u.location.lat, u.location.lng],
            Math.max(15, this._map.getZoom()),
          );
        this.draw();
        this._markers?.get(u.id)?.openPopup();
      },
      showDetails(u) {
        this._popupFocus = document.activeElement;
        this.selectedId = u.id;
        this.clusterIds = [];
        this.detailsOpen = true;
        if (u.location)
          this._map?.setView(
            [u.location.lat, u.location.lng],
            Math.max(15, this._map.getZoom()),
          );
        this.$nextTick(() => {
          const ref = this.$refs.userPopup,
            target = Array.isArray(ref) ? ref.at(-1) : ref;
          target?.focus({ preventScroll: true });
        });
      },
      popupNode(u) {
        const node = document.createElement("article");
        node.className = "map-point-popup";
        node.dir = "rtl";
        node.style.setProperty("--network-color", u.networkColor);
        const head = document.createElement("header"),
          title = document.createElement("div"),
          name = document.createElement("strong"),
          meta = document.createElement("small");
        name.textContent = u.name;
        meta.textContent = u.kind + " · " + u.account;
        title.append(name, meta);
        const state = document.createElement("span");
        state.className =
          "map-popup-state " + (u.online ? "online" : "offline");
        state.textContent = u.online ? "متصل" : "غير متصل";
        head.append(title, state);
        node.append(head);
        const grid = document.createElement("dl"),
          add = (label, value) => {
            if (!value) return;
            const dt = document.createElement("dt"),
              dd = document.createElement("dd");
            dt.textContent = label;
            dd.textContent = value;
            grid.append(dt, dd);
          };
        add("الحساب التابع له", u.parentName);
        add("رقم الهاتف", u.phone);
        add("المحافظة / المدينة", u.city);
        add("العنوان", u.address);
        add("آخر ظهور", this.time(u.lastSeen));
        add("مصدر الموقع", u.source);
        if (u.location)
          add(
            "الإحداثيات",
            u.location.lat.toFixed(5) + ", " + u.location.lng.toFixed(5),
          );
        node.append(grid);
        const actions = document.createElement("div");
        actions.className = "map-popup-actions";
        const details = document.createElement("button");
        details.type = "button";
        details.className = "btn primary small";
        details.textContent = "عرض التفاصيل";
        details.addEventListener("click", (event) => {
          event.stopPropagation();
          this.showDetails(u);
        });
        actions.append(details);
        if (u.location) {
          const copy = document.createElement("button");
          copy.type = "button";
          copy.className = "btn small";
          copy.textContent = "نسخ رابط الموقع";
          copy.addEventListener("click", async (event) => {
            event.stopPropagation();
            const link =
              "https://www.google.com/maps?q=" +
              encodeURIComponent(u.location.lat + "," + u.location.lng);
            try {
              await navigator.clipboard.writeText(link);
              this.vm.notify("تم نسخ رابط الموقع ويمكن مشاركته");
            } catch {
              this.vm.notify("تعذر نسخ رابط الموقع", true);
            }
          });
          actions.append(copy);
        }
        node.append(actions);
        return node;
      },
      reset() {
        this.query = "";
        this.type = "";
        this.branch = "";
        this.status = "";
        this.custom = false;
        this.selected = [];
        this.selectedId = "";
        this.detailsOpen = false;
        this.$nextTick(() => this.fit());
      },
      draw() {
        if (!this._map || !this.permitted) return;
        this._layer.clearLayers();
        this._markers = new Map();
        this.clusterIds = [];
        const groups = new Map();
        for (const u of this.rows) {
          if (!u.location) continue;
          const key =
            u.location.lat.toFixed(5) + ":" + u.location.lng.toFixed(5);
          if (!groups.has(key)) groups.set(key, []);
          groups.get(key).push(u);
        }
        for (const us of groups.values())
          for (let index = 0; index < us.length; index++) {
            const u = us[index],
              base = this._map.latLngToLayerPoint([
                u.location.lat,
                u.location.lng,
              ]),
              angle =
                (Math.PI * 2 * index) / Math.max(1, us.length) - Math.PI / 2,
              radius = us.length > 1 ? Math.min(30, 14 + us.length * 2) : 0,
              shown = this._map.layerPointToLatLng([
                base.x + Math.cos(angle) * radius,
                base.y + Math.sin(angle) * radius,
              ]);
            const marker = L.marker(shown, {
              zIndexOffset:
                u.role === "main"
                  ? 300
                  : u.role === "sub"
                    ? 200
                    : u.role === "pos"
                      ? 100
                      : 0,
              icon: L.divIcon({
                className:
                  "account-map-marker network-marker " +
                  (u.online ? "online" : "offline") +
                  (u.id === this.selectedId ? " selected-marker" : ""),
                html:
                  '<span style="--network-color:' +
                  u.networkColor +
                  '">' +
                  u.symbol +
                  '<i class="marker-presence"></i></span>',
                iconSize: [34, 34],
              }),
              title: u.name + " — " + u.kind,
              keyboard: true,
            }).addTo(this._layer);
            this._markers.set(u.id, marker);
            marker.bindPopup(() => this.popupNode(u), {
              className: "masal-map-popup",
              maxWidth: 350,
              minWidth: 270,
              offset: [0, -12],
              autoPan: true,
              autoPanPadding: [28, 28],
              autoClose: true,
              closeOnClick: true,
            });
            marker.on("click", () => {
              this.selectedId = u.id;
              this.clusterIds = [];
            });
          }
      },
    },
  };
  root.MasalUserMap = { install, valid, connected };
})(globalThis);

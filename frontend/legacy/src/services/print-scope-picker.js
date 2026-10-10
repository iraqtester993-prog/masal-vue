(function (root) {
  "use strict";
  const picker = {
    props: ["modelValue", "choices"],
    emits: ["update:modelValue"],
    data() {
      return {
        tab: "agents",
        query: "",
        mainFilter: "",
        branchFilter: "",
        selectedOnly: false,
        page: 1,
        selectedPage: 1,
      };
    },
    computed: {
      vm() {
        return this.$root;
      },
      agentMap() {
        return new Map(this.vm.visibleAgents.map((a) => [a.id, a]));
      },
      choiceMap() {
        return new Map(this.choices.map((c) => [c.key, c.name]));
      },
      ancestry() {
        const map = new Map();
        for (const a of this.vm.visibleAgents) {
          const chain = new Set();
          let id = a.id;
          while (id && !chain.has(id)) {
            chain.add(id);
            id = this.agentMap.get(id)?.parent;
          }
          map.set(a.id, chain);
        }
        return map;
      },
      mains() {
        return this.vm.visibleAgents.filter((a) => !a.parent);
      },
      branches() {
        return this.vm.visibleAgents.filter(
          (a) =>
            a.parent &&
            (!this.mainFilter || this.ancestry.get(a.id)?.has(this.mainFilter)),
        );
      },
      filtered() {
        const items =
            this.tab === "agents" ? this.vm.visibleAgents : this.vm.visiblePOS,
          q = this.query.trim().toLocaleLowerCase(),
          scope = this.branchFilter || this.mainFilter;
        return items.filter(
          (a) =>
            (!scope ||
              this.ancestry
                .get(this.tab === "pos" ? a.agent : a.id)
                ?.has(scope)) &&
            (!this.selectedOnly || this.checked(a)) &&
            (!q ||
              [a.name, a.id, this.parent(a)].some((v) =>
                String(v).toLocaleLowerCase().includes(q),
              )),
        );
      },
      pages() {
        return Math.max(1, Math.ceil(this.filtered.length / 20));
      },
      rows() {
        return this.filtered.slice((this.page - 1) * 20, this.page * 20);
      },
      selected() {
        return this.modelValue || [];
      },
      selectedSet() {
        return new Set(this.selected);
      },
      selectedPages() {
        return Math.max(1, Math.ceil(this.selected.length / 20));
      },
      selectedRows() {
        return this.selected.slice(
          (this.selectedPage - 1) * 20,
          this.selectedPage * 20,
        );
      },
    },
    watch: {
      tab() {
        this.page = 1;
      },
      query() {
        this.page = 1;
      },
      mainFilter() {
        this.branchFilter = "";
        this.page = 1;
      },
      branchFilter() {
        this.page = 1;
      },
      selectedOnly() {
        this.page = 1;
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
      selectedPages(n) {
        this.selectedPage = Math.min(this.selectedPage, n);
      },
    },
    methods: {
      parent(a) {
        return (
          this.agentMap.get(this.tab === "pos" ? a.agent : a.parent)?.name || ""
        );
      },
      kind(a) {
        return this.tab === "pos"
          ? "نقطة بيع"
          : a.parent
            ? "فرع"
            : "وكيل رئيسي";
      },
      checked(a) {
        return this.tab === "pos"
          ? this.selectedSet.has("pos:" + a.id)
          : this.selectedSet.has("tree:" + a.id) ||
              this.selectedSet.has("agent:" + a.id);
      },
      coverage(a) {
        return this.selected.includes("tree:" + a.id) ? "tree" : "agent";
      },
      toggle(a, on) {
        const keys =
          this.tab === "pos"
            ? ["pos:" + a.id]
            : ["tree:" + a.id, "agent:" + a.id];
        this.$emit("update:modelValue", [
          ...this.selected.filter((k) => !keys.includes(k)),
          ...(on ? [keys[0]] : []),
        ]);
      },
      changeCoverage(a, kind) {
        this.$emit("update:modelValue", [
          ...this.selected.filter(
            (k) => k !== "tree:" + a.id && k !== "agent:" + a.id,
          ),
          kind + ":" + a.id,
        ]);
      },
      remove(key) {
        this.$emit(
          "update:modelValue",
          this.selected.filter((k) => k !== key),
        );
      },
      label(key) {
        return this.choiceMap.get(key) || key;
      },
    },
  };
  const scopeSummary = {
    props: ["rule", "choices"],
    data: () => ({ opened: false, query: "", page: 1 }),
    computed: {
      names() {
        return new Map(this.choices.map((c) => [c.key, c.name]));
      },
      filtered() {
        const q = this.query.trim().toLocaleLowerCase();
        return (this.rule.targets || [])
          .map((t) => ({
            key: t.kind + ":" + t.id,
            label: this.names.get(t.kind + ":" + t.id) || t.id,
          }))
          .filter((t) => !q || t.label.toLocaleLowerCase().includes(q));
      },
      pages() {
        return Math.max(1, Math.ceil(this.filtered.length / 20));
      },
      rows() {
        return this.filtered.slice((this.page - 1) * 20, this.page * 20);
      },
    },
    watch: {
      query() {
        this.page = 1;
      },
      pages(n) {
        this.page = Math.min(this.page, n);
      },
      "$root.currentUser"() {
        this.close();
      },
    },
    methods: {
      async open() {
        this.query = "";
        this.page = 1;
        this.opened = true;
        await this.$nextTick();
        const dialog = this.$refs.dialog;
        dialog.onkeydown = (e) => {
          if (e.key === "Escape") {
            e.preventDefault();
            e.stopPropagation();
            this.close();
          }
        };
        dialog.showModal();
      },
      close() {
        this.$refs.dialog?.close();
        this.opened = false;
      },
    },
  };
  root.MasalPrintScopePicker = {
    install(o) {
      const panel = o.components["print-policy-settings"];
      panel.components = {
        ...panel.components,
        "print-scope-picker": picker,
        "saved-scope-summary": scopeSummary,
      };
      void 0;
    },
  };
})(globalThis);

export default {
  beforeCreate() {
    const root = this.$root;
    const keys = new Set([
      ...Object.keys(root.$data),
      ...Object.keys(root.$options.computed || {}),
    ]);
    this.$options.computed = Object.fromEntries(
      [...keys].map((key) => [
        key,
        {
          get() {
            return root[key];
          },
          set(value) {
            root[key] = value;
          },
        },
      ]),
    );
    this.$options.methods = {
      ...this.$options.methods,
      ...Object.fromEntries(
        Object.keys(root.$options.methods || {}).map((key) => [
          key,
          (...args) => root[key](...args),
        ]),
      ),
    };
  },
  mounted() {
    this.forwardRefs();
  },
  updated() {
    this.forwardRefs();
  },
  beforeUnmount() {
    for (const [key, value] of Object.entries(this.$refs))
      if (this.$root.$refs[key] === value) delete this.$root.$refs[key];
  },
  methods: {
    forwardRefs() {
      Object.assign(this.$root.$refs, this.$refs);
    },
  },
};

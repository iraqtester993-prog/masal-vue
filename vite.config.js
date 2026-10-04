import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
export default defineConfig({
  base: "./",
  plugins: [vue()],
  resolve: {
    alias: [{ find: /^vue$/, replacement: "vue/dist/vue.esm-bundler.js" }],
  },
  // The public company popup serializes methods; preserve their closure names.
  build: { minify: false },
});

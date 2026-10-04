import "./services/vue.js";
import "./styles/index.js";
import "./services/bootstrap.js";
import { createApp } from "vue";
import App from "./App.vue";
window.app = createApp(App)
  .directive("money", globalThis.MasalMoneyInputs.directive)
  .mount("#mount-root");

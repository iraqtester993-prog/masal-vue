import { ref } from "vue";
import { usePreferences } from "../../modules/preferences/preferences-state.js";
const dark = ref(false);
export function useTheme() {
  const preferences = usePreferences();
  if (preferences) return { dark: preferences.dark, toggleTheme: preferences.toggleTheme };
  if (typeof document !== 'undefined' && !document.documentElement.dataset.theme)
    document.documentElement.dataset.theme = "light";
  function toggleTheme() {
    dark.value = !dark.value;
    if (typeof document !== 'undefined') document.documentElement.dataset.theme = dark.value ? "dark" : "light";
  }
  return { dark, toggleTheme };
}

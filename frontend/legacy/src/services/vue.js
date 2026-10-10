import * as Vue from "vue";
import * as Leaflet from "leaflet";
import markerIcon from "leaflet/dist/images/marker-icon.png";
import markerIconRetina from "leaflet/dist/images/marker-icon-2x.png";
import markerShadow from "leaflet/dist/images/marker-shadow.png";

// Existing Vue components and business modules share this runtime while their
// Options API registrations remain unchanged during the project migration.
globalThis.Vue = Vue;
globalThis.L = Leaflet;
Leaflet.Icon.Default.mergeOptions({
  iconUrl: markerIcon,
  iconRetinaUrl: markerIconRetina,
  shadowUrl: markerShadow,
});
globalThis.MasalVueManaged = true;
try {
  document.documentElement.dataset.theme =
    localStorage.getItem("masal-appearance") || "light";
} catch {
  document.documentElement.dataset.theme = "light";
}

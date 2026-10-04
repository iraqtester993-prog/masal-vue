<script>
import viewContext from "../../services/view-context.js";
export default {
  components: globalThis.MasalAppOptions.components,
  directives: globalThis.MasalAppOptions.directives,
  mixins: [viewContext],
};
</script>

<template>
  <div class="tablewrap">
    <table>
      <thead>
        <tr>
          <th>{{ tr("معرف داخلي") }}</th>
          <th>{{ tr("سيريال المزود") }}</th>
          <th>PIN</th>
          <th>{{ tr("الانتهاء") }}</th>
          <th>{{ tr("الحالة") }}</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="c in modal.cards">
          <td>{{ tr(c.internal) }}</td>
          <td>{{ tr(c.serial || "غير متوفر") }}</td>
          <td>••••••••</td>
          <td>{{ tr(c.expiry) }}</td>
          <td>{{ tr(status(c.status)) }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

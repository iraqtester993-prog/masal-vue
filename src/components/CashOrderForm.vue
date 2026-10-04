<script>
import { componentOptions } from "../services/component-registry.js";
const options = componentOptions(["cash-order-form"]);
export default options;
</script>

<template>
  <legacy-form v-if="$root.replacementClaim"></legacy-form
  ><multi-form v-else="" ref="multi"></multi-form>
</template>

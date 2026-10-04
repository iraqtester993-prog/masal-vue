export function componentOptions(keys) {
  let options = globalThis.MasalAppOptions;
  for (const key of keys) options = options.components[key];
  return options;
}

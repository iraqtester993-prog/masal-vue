function clean(value) {
  return String(value ?? "")
    .replace(/[٠-٩۰-۹]/g, (c) =>
      String(c.charCodeAt(0) - (c <= "٩" ? 1632 : 1776)),
    )
    .replace(/[,٬\s]/g, "")
    .replace(/٫/g, ".");
}
function format(value) {
  const raw = clean(value);
  if (!/^-?\d*(\.\d*)?$/.test(raw)) return raw;
  const [whole, decimal] = raw.split(".");
  return (
    whole.replace(/\B(?=(\d{3})+(?!\d))/g, ",") +
    (decimal === undefined ? "" : "." + decimal)
  );
}
function enabled(binding) {
  return binding.value !== false;
}
function render(el) {
  if (!el._moneyEnabled) return;
  const pos = el.selectionStart,
    old = el.value,
    count = clean(old.slice(0, pos ?? old.length)).length;
  el.value = format(old);
  if (document.activeElement === el && pos !== null) {
    let i = 0,
      n = 0;
    while (i < el.value.length && n < count) {
      if (el.value[i] !== ",") n++;
      i++;
    }
    el.setSelectionRange(i, i);
  }
  const raw = clean(el.value),
    n = Number(raw);
  let error = "";
  if (raw && !/^-?\d+(\.\d*)?$/.test(raw)) error = "أدخل مبلغًا صحيحًا";
  else if (raw && el.hasAttribute("min") && n < Number(el.getAttribute("min")))
    error = "المبلغ أقل من الحد المسموح";
  else if (raw && el.hasAttribute("max") && n > Number(el.getAttribute("max")))
    error = "المبلغ أكبر من الحد المسموح";
  el.setCustomValidity(error);
}
const directive = {
  created(el, binding) {
    el._moneyEnabled = enabled(binding);
    const handler = () => {
      if (!el._moneyEnabled) return;
      const caret = el.selectionStart;
      const before = clean(el.value.slice(0, caret ?? el.value.length)).length;
      el.value = clean(el.value);
      el._moneyRaw = el.value;
      if (caret !== null) el.setSelectionRange(before, before);
    };
    el.addEventListener("input", handler, true);
    el.addEventListener("change", handler, true);
  },
  mounted(el, b) {
    el._moneyEnabled = enabled(b);
    el.addEventListener("input", () => render(el));
    el.addEventListener("change", () => render(el));
    render(el);
  },
  updated(el, b) {
    el._moneyEnabled = enabled(b);
    if (
      document.activeElement === el &&
      el._moneyRaw !== undefined &&
      Number(clean(el.value)) === Number(el._moneyRaw) &&
      el.value !== ""
    )
      el.value = el._moneyRaw;
    render(el);
  },
};

export { directive as vMoney, clean, format };

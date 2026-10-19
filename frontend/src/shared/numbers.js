export function westernDigits(value) {
  return String(value ?? '').replace(/[٠-٩۰-۹]/g, digit => String(digit.charCodeAt(0) - (digit <= '٩' ? 1632 : 1776)));
}

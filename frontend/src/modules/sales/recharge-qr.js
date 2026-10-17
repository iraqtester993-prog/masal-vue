import qrcode from 'qrcode-generator';

const rechargeTemplate = /^\*(?:[0-9]{1,6}\*)+\{pin\}(?:\*[0-9]{1,6})*#$/;
const modulePixels = 6;
const quietModules = 4;
const validTemplate = template => typeof template === 'string' && template.length <= 80 && template.endsWith('#') && rechargeTemplate.test(template);

export function rechargePin(pin) {
  // Printed vouchers may group digits with one ASCII dash or ordinary space.
  // Validate the complete input before removing only those group separators.
  if (typeof pin !== 'string' || pin.length < 1 || pin.length > 127 || /[^0-9 -]/.test(pin)
    || !/^[0-9]+(?:[- ][0-9]+)*$/.test(pin)) return null;
  const digits = pin.replace(/[- ]/g, '');
  return digits.length <= 64 ? digits : null;
}

export function rechargeDial(pin, template) {
  const digits = rechargePin(pin);
  if (digits === null || !validTemplate(template)) return null;
  const code = template.replace('{pin}', digits);
  // A literal # is a URI fragment separator; the dialer must receive it as data.
  return {code, uri:`tel:${code.slice(0,-1)}%23`};
}

export function rechargeContent(pin, template) {
  const dial = rechargeDial(pin, template);
  if (dial) return {kind:'recharge', ...dial};
  // Test or non-dialable vouchers keep their complete PIN as explicit plain text.
  // No letter is mapped to a keypad digit and no arbitrary character is stripped.
  if (!validTemplate(template) || typeof pin !== 'string' || pin.length < 1 || pin.length > 127
    || /[^A-Za-z0-9 -]/.test(pin) || !/[A-Za-z]/.test(pin) || !/^[A-Za-z0-9]+(?:[- ][A-Za-z0-9]+)*$/.test(pin)) return null;
  return {kind:'pin', text:`PIN: ${pin}`};
}

// Local, synchronous and SSR-safe. PINs are never sent to an image service or persisted.
function qrImage(payload) {
  const qr = qrcode(0, 'M');
  qr.addData(payload, 'Byte');
  qr.make();
  const width = (qr.getModuleCount() + quietModules * 2) * modulePixels;
  if (width > 360) return null;
  const svg = qr.createSvgTag({cellSize:modulePixels, margin:quietModules * modulePixels, scalable:false});
  return {dataUrl:`data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`, width};
}

export function rechargeQr(pin, template) {
  const dial = rechargeDial(pin, template);
  if (!dial) return null;
  const image = qrImage(dial.uri);
  return image ? {...dial, ...image} : null;
}

export function receiptQr(pin, template) {
  const content = rechargeContent(pin, template);
  if (!content) return null;
  const image = qrImage(content.kind === 'recharge' ? content.uri : content.text);
  return image ? {...content, ...image} : null;
}

export function rechargeQrPreview(template) {
  if (!rechargeDial('0',template)) return null;
  return qrImage('PREVIEW ONLY - NOT FOR RECHARGE');
}

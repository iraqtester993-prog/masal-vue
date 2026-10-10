import { amount, minor } from './finance-model.js';

const normalize = (value) => String(value ?? '').replace(/^\uFEFF/, '').trim().toLowerCase();

export function priceTemplateRows(products, accountId) {
  return products.map((product) => ({
    version: '1',
    agent: String(accountId),
    product: String(product.product_id),
    name: product.name,
    price: product.price ?? '',
  }));
}

export function readPriceTemplate(sheets, products, accountId) {
  const allowed = new Map(products.map((product) => [String(product.product_id), product]));
  const draft = {};
  for (const sheet of sheets) {
    const rows = sheet.rows.filter((row) => row.some((cell) => String(cell ?? '').trim()));
    if (!rows.length) continue;
    const headers = rows[0].map(normalize);
    if (new Set(headers).size !== headers.length || !['version', 'agent', 'product', 'price'].every((key) => headers.includes(key))) {
      throw new Error('استخدم قالب الأسعار المعتمد: version, agent, product, name, price.');
    }
    for (const cells of rows.slice(1)) {
      const row = Object.fromEntries(headers.map((key, index) => [key, String(cells[index] ?? '').trim()]));
      const product = allowed.get(row.product);
      if (row.version !== '1' || row.agent !== String(accountId) || !product) {
        throw new Error('إصدار القالب أو الوكيل أو الفئة غير صالح.');
      }
      if (Object.hasOwn(draft, product.product_id)) throw new Error(`فئة مكررة: ${product.name}.`);
      let value;
      try { value = amount(row.price); }
      catch { throw new Error(`سعر غير صالح للفئة ${product.name}.`); }
      if (product.minimum_price !== null && product.minimum_price !== undefined && minor(value) < minor(product.minimum_price)) {
        throw new Error(`السعر أقل من الحد المسموح للفئة ${product.name}.`);
      }
      draft[product.product_id] = value;
    }
  }
  if (!Object.keys(draft).length) throw new Error('الملف لا يحتوي أسعارًا.');
  return draft;
}

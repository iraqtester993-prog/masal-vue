export const financeKey = Symbol('masal.finance');
export const accountTypes = { system: 'إدارة النظام', main_agent: 'وكيل رئيسي', sub_agent: 'وكيل فرعي', sub_branch: 'فرع فرعي', pos: 'نقطة بيع' };
export const statuses = { pending: 'بانتظار الموافقة', approved: 'منفذ', rejected: 'مرفوض', cancelled: 'ملغي', reversed: 'تم التراجع', unpaid: 'غير مسدد', partial: 'مسدد جزئيًا', paid: 'مسدد' };
export const movementKinds = { deposit: 'إيداع', transfer: 'تحويل رصيد', funding: 'تمويل', recovery: 'استرجاع الرصيد', 'invoice-settlement': 'تحصيل / تسديد فاتورة', invoice_settlement: 'تحصيل فاتورة', invoice_payment: 'تسديد فاتورة', stock_funding: 'تمويل بطلبية', stock_import: 'تحميل مخزون' };

export function cleanMoney(value) {
  return String(value ?? '').trim().replace(/[٠-٩۰-۹]/g, (c) => String(c.charCodeAt(0) - (c <= '٩' ? 1632 : 1776))).replace(/[,٬\s]/g, '').replace(/٫/g, '.');
}
export function minor(value) {
  const raw = cleanMoney(value);
  if (!/^-?\d{1,30}(?:\.\d{1,2})?$/.test(raw)) throw new Error('أدخل مبلغًا صحيحًا بمنزلتين عشريتين كحد أقصى.');
  const negative = raw.startsWith('-');
  const [whole, part = ''] = raw.replace(/^-/, '').split('.');
  const result = BigInt(whole) * 100n + BigInt(part.padEnd(2, '0'));
  return negative ? -result : result;
}
export function decimal(value) {
  const amount = BigInt(value), absolute = amount < 0n ? -amount : amount;
  return `${amount < 0n ? '-' : ''}${absolute / 100n}.${String(absolute % 100n).padStart(2, '0')}`;
}
export function amount(value, positive = true) {
  const cents = minor(value);
  if (positive ? cents <= 0n : cents < 0n) throw new Error(positive ? 'المبلغ يجب أن يكون أكبر من صفر.' : 'المبلغ لا يمكن أن يكون سالبًا.');
  const result = decimal(cents);
  if (!/^(?:0|[1-9]\d{0,12})\.\d{2}$/.test(result)) throw new Error('المبلغ يتجاوز الحد المسموح.');
  return result;
}
export function money(value) {
  if (value === null || value === undefined || value === '') return '—';
  try {
    const raw = decimal(minor(value)), [whole, fraction] = raw.split('.');
    return whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === '00' ? '' : `.${fraction}`);
  } catch { return '—'; }
}
export function sum(values) { return decimal(values.reduce((total, value) => total + minor(value), 0n)); }
export function difference(next, previous) { return decimal(minor(next) - minor(previous ?? '0')); }
export function positive(value) { try { return minor(value) > 0n; } catch { return false; } }
export function time(value) {
  if (!value || !Number.isFinite(Date.parse(value))) return '—';
  return new Intl.DateTimeFormat('ar-IQ', { timeZone: 'Asia/Baghdad', dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}
export function businessDay(value = new Date()) {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Baghdad', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date(value));
}
export function errorText(error) {
  const validation = Object.values(error?.errors ?? {}).flat().filter((value) => typeof value === 'string');
  return validation.length ? validation.join(' ') : error?.message || 'تعذر إكمال الطلب.';
}

// The same payload keeps its key after a lost response; retries cannot post twice.
export function createMutationKey(create = () => crypto.randomUUID()) {
  let fingerprint = '', key = '';
  return { for(payload) { const next = JSON.stringify(payload); if (fingerprint !== next) { fingerprint = next; key = create(); } return key; }, clear() { fingerprint = ''; key = ''; } };
}
export function transferRows(selected, drafts, choices) {
  const allowed = new Set(choices.map((row) => Number(row.id)));
  if (!selected.length || selected.length > 100) throw new Error('حدد من 1 إلى 100 مستفيد.');
  if (new Set(selected.map(Number)).size !== selected.length) throw new Error('يوجد مستفيد مكرر.');
  return selected.map((id) => {
    if (!allowed.has(Number(id))) throw new Error('المستفيد غير متاح ضمن نطاقك.');
    return { to_account_id: Number(id), amount: amount(drafts[id]) };
  });
}
export function pricePreview(products, drafts, { mode = 'individual', targetIds = [], direction = 'add', value = '' } = {}) {
  const delta = mode === 'bulk' ? minor(amount(value)) * (direction === 'subtract' ? -1n : 1n) : 0n;
  const target = new Set(targetIds.map(Number));
  return products.filter((row) => mode === 'bulk' ? target.has(Number(row.product_id)) : cleanMoney(drafts[row.product_id]) !== '').map((row) => {
    let next = '', error = '';
    try {
      if (mode === 'bulk' && row.price === null) throw new Error('حدد سعرًا فرديًا لهذه الفئة أولًا.');
      next = mode === 'bulk' ? decimal(minor(row.price) + delta) : amount(drafts[row.product_id]);
      if (!positive(next)) throw new Error('السعر يجب أن يكون أكبر من صفر.');
      if (row.minimum_price !== null && row.minimum_price !== undefined && minor(next) < minor(row.minimum_price)) throw new Error('أقل من السعر المسموح.');
      amount(next);
    } catch (failure) { error = errorText(failure); }
    return { product_id: Number(row.product_id), name: row.name, currency: row.currency, old_price: row.price, price: next, difference: next ? difference(next, row.price) : '', error };
  });
}
export function priceChanges(rows) {
  if (!rows.length || rows.length > 500 || rows.some((row) => row.error)) throw new Error('راجع الأسعار؛ يسمح بـ500 فئة كحد أقصى لكل عملية.');
  const changed = rows.filter((row) => row.old_price === null || minor(row.price) !== minor(row.old_price));
  if (!changed.length) throw new Error('الأسعار لم تتغير.');
  return changed.map((row) => ({ product_id: row.product_id, price: amount(row.price), expected_price: row.old_price }));
}
export function csv(rows) {
  return '\uFEFF' + rows.map((row) => row.map((value) => {
    // A spreadsheet must display untrusted labels, never execute them as formulas.
    const text = String(value ?? '');
    return '"' + (/^[=+@\-\t\r]/.test(text) ? "'" + text : text).replace(/"/g, '""') + '"';
  }).join(',')).join('\r\n');
}
export function downloadRows(filename, rows) {
  const url = URL.createObjectURL(new Blob([csv(rows)], { type: 'text/csv;charset=utf-8' }));
  const link = document.createElement('a'); link.href = url; link.download = filename; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
export const emptyFilters = () => ({ city: '', kind: '', network: '', active: '', query: '', account: '', reference: '', from: '', to: '' });
export function matchesAccount(account, filters, accounts) {
  if (filters.city && (account.city ?? account.governorate_name) !== filters.city) return false;
  if (filters.kind && account.type !== filters.kind) return false;
  if (filters.active && (filters.active === 'active') !== (account.status === 'active')) return false;
  if (filters.query && !`${account.name} ${account.phone || ''}`.toLocaleLowerCase('ar').includes(filters.query.trim().toLocaleLowerCase('ar'))) return false;
  if (filters.account && Number(filters.account) !== Number(account.id)) return false;
  if (filters.network) {
    let current = account;
    const seen = new Set();
    while (current && !seen.has(current.id)) {
      if (Number(current.id) === Number(filters.network)) return true;
      seen.add(current.id); current = accounts.find((row) => Number(row.id) === Number(current.parent_id));
    }
    return false;
  }
  return true;
}

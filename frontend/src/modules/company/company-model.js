export const COMPANY_SECTIONS = Object.freeze([['slides','السلايدر'],['about','عن الشركة'],['activities','النشاطات'],['offers','العروض'],['projects','المشاريع'],['social','مواقع التواصل'],['care','خدمة العملاء']]);
export const COMPANY_GALLERIES = ['slides','activities','offers','projects'];
export function imageUrl(image) { return typeof image === 'string' ? image : image?.url || ''; }
export function displayProfile(profile) {
  const result = JSON.parse(JSON.stringify(profile));
  result.logo = imageUrl(result.logo);
  for (const key of COMPANY_GALLERIES) result[key] = result[key].map(item => ({...item,image:imageUrl(item.image)}));
  return result;
}
export function profilePayload(profile) {
  const result = JSON.parse(JSON.stringify(profile));
  const id = image => typeof image === 'object' ? image?.id || null : image || null;
  result.logo = id(result.logo);
  for (const key of COMPANY_GALLERIES) result[key] = result[key].map(item => ({...item,image:id(item.image)}));
  return result;
}
export function safeHttps(value) {
  if (!value) return '';
  try { const url = new URL(value); return url.protocol === 'https:' && !url.username && !url.password ? url.href : ''; } catch { return ''; }
}
export function sectionVisible(profile,key) {
  return !!profile?.visibility?.[key] && (key === 'care' || (key === 'about' ? !!profile.about : Array.isArray(profile[key]) && profile[key].some(item => item.visible !== false)));
}
export function moveCompanyItem(items,index,offset) {
  const next = index + offset;
  if (next < 0 || next >= items.length) return false;
  [items[index],items[next]] = [items[next],items[index]]; return true;
}
export function companyTime(time) {
  if (!time) return '—';
  const date = new Date(time);
  return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat('ar-IQ',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Baghdad'}).format(date);
}

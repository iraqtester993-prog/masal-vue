export function validTrackingToken(value) {
  return typeof value === 'string' && /^[1-9][0-9]{0,18}\.[a-f0-9]{64}$/.test(value);
}
export function inquiryTrackingUrl(token, origin = 'https://dananir-iq.com') {
  return validTrackingToken(token) ? `${origin}/messages#inquiry=${token}` : '';
}
export function inquiryTokenFromHash(hash) {
  const token = new URLSearchParams(String(hash).replace(/^#/, '')).get('inquiry');
  return validTrackingToken(token) ? token : '';
}

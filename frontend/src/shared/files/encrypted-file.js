const bytesTo64 = bytes => {
  const view = new Uint8Array(bytes), chunks = [];
  for (let offset = 0; offset < view.length; offset += 16384) chunks.push(String.fromCharCode(...view.subarray(offset,offset+16384)));
  return btoa(chunks.join(''));
};
async function encryptionKey(password, salt) {
  const material = await crypto.subtle.importKey('raw',new TextEncoder().encode(password),'PBKDF2',false,['deriveKey']);
  return crypto.subtle.deriveKey({name:'PBKDF2',salt,iterations:210000,hash:'SHA-256'},material,{name:'AES-GCM',length:256},false,['encrypt']);
}
export async function encryptFile(text, password) {
  if (password.length < 12) throw Error('كلمة مرور الملف يجب أن تحتوي 12 حرفًا على الأقل.');
  const salt = crypto.getRandomValues(new Uint8Array(16)), iv = crypto.getRandomValues(new Uint8Array(12)), key = await encryptionKey(password,salt);
  return {format:'masal-backup-encrypted-v1',salt:bytesTo64(salt),iv:bytesTo64(iv),data:bytesTo64(await crypto.subtle.encrypt({name:'AES-GCM',iv},key,new TextEncoder().encode(text)))};
}

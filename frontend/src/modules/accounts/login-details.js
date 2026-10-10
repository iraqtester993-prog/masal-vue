export function createdLoginDetails(type,login,password){
  const portal=type==='system'?'admin':type==='pos'?'pos':'agents';
  return {url:`https://${portal}.dananir-iq.com/login`,login,password};
}

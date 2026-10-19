// Scope is confirmed by the server. A system employee never gains global
// authority from the account type or an action permission alone.
export function hasSystemScope(identity){
 return identity?.account?.type==='system'&&(identity.membership?.kind==='owner'||identity.capabilities?.system_scope===true);
}
export function hasOwnerAuthority(identity){return identity?.membership?.kind==='owner'||hasSystemScope(identity);}

export function hasBackupAccess(identity){
 const grant=identity?.capabilities?.backup_access;
 return hasSystemScope(identity)&&(grant===true||(grant===undefined&&identity.membership?.kind==='owner'));
}

import {reactive} from 'vue';
import {usePortal} from '../../modules/auth/session.js';
import {query} from '../../modules/reports/report-api.js';
export function useAccountLookup(providedSession){const session=providedSession||usePortal().session;const options=reactive({accounts:[]});return {options,api:{list:(_,parameters,signal)=>(parameters.lookup_ids?.length>100?session.api.request('/account-lookup',{method:'POST',body:parameters,signal}):session.api.request(`/account-lookup?${query(parameters)}`,{signal}))},rememberAccounts(rows){const ids=new Set(rows.map(row=>Number(row.id)));options.accounts=[...options.accounts.filter(row=>!ids.has(Number(row.id))),...rows].slice(-500);},failure:async error=>{if([401,403].includes(error.status))await session.refresh();return error.message;}};}

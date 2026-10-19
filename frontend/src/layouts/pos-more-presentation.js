import {navIconPath} from './navigation.js';

const paths = Object.freeze({
 language:'M3 5h12M9 3v2M5 5c0 6 5 10 9 11M13 5c0 5-4 9-10 11M14 21l4-10 4 10m-6-4h4',
 about:'M12 8h.01M11 12h1v5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
 device:'M7 2h10a2 2 0 0 1 2 2v16a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2Zm3 16h4',
 logout:'M9 4H4v16h5M14 8l4 4-4 4M8 12h13',
 topupAvailable:navIconPath('products'),topupAllocation:navIconPath('agents'),topupDistribution:navIconPath('agents'),topupCategories:navIconPath('products'),
 digital:'M8 3H5v18h14V3h-3M9 3h6v4H9ZM8 11h8m-8 4h5',
 sources:navIconPath('inventory'),representatives:navIconPath('users'),posTypes:navIconPath('pos'),
 accountTime:'M12 8v5l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z',
 deletedAccounts:navIconPath('claims'),governorates:navIconPath('map'),printPolicies:navIconPath('permissions'),
 'app-settings':'M4 8V4h16v4M6 17H3V9h18v8h-3M6 14h12v7H6Z',
 'company-settings':navIconPath('branding'),
});

export function posMoreIcon(id){return paths[id] || navIconPath(id);}

export function posMoreIdentity(identity,apiBase='/api/v1'){
 const account=identity?.account||{},name=account.owner_name||identity?.user?.name||account.name||'حسابك';
 const path=account.personal_image_url;
 const photo=typeof path==='string'&&/^\/api\/v1\/auth\/personal-image(?:\?v=\d+)?$/.test(path)
  ?apiBase.replace(/\/api\/v1\/?$/,'').replace(/\/$/,'')+path:null;
 return {name,point:account.name&&account.name!==name?account.name:'',parent:account.parent_name||'',photo,initial:Array.from(name.trim())[0]||'د'};
}

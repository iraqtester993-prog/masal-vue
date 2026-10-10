import { createRouter, createWebHistory } from "vue-router";
import {landingRoute, routeAllowed} from './access.js';

export function createPortalRouter(session) {
  const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
      { path: "/", redirect: "/dashboard" },
      {
        path: "/login",
        name: "login",
        component: () => import("../modules/auth/LoginPage.vue"),
      },
      {path:'/no-access',name:'no-access',meta:{requiresAuth:true,title:'حسابك'},component:()=>import('../modules/auth/NoAccessPage.vue')},
      {
        path: "/dashboard",
        name: "dashboard",
        meta: { requiresAuth: true, title:'لوحة التحكم', permission: 'dashboard.view' },
        component: () => import("../modules/dashboard/DashboardPage.vue"),
      },
      { path: "/accounts", redirect: "/agents" },
      { path:'/topup/allocation',name:'topupAllocation',meta:{requiresAuth:true,title:'تخصيص فئات Topup للوكلاء',permission:'integrations.edit',accountTypes:['system'],membershipKinds:['owner'],navigationId:'topupAllocation'},component:()=>import('../modules/digital/TopupDistributionPage.vue') },
      { path:'/topup/distribution',name:'topupDistribution',meta:{requiresAuth:true,title:'توزيع فئات Topup',permission:'digital.assign',accountTypes:['main_agent','sub_agent','sub_branch'],navigationId:'topupDistribution'},component:()=>import('../modules/digital/TopupDistributionPage.vue') },
      { path:'/topup/available',name:'topupAvailable',meta:{requiresAuth:true,title:'فئات Topup الممنوحة',permission:'digital.view',accountTypes:['pos'],navigationId:'topupAvailable'},props:{available:true},component:()=>import('../modules/digital/TopupDistributionPage.vue') },
      { path:'/topup/categories',name:'topupCategories',meta:{requiresAuth:true,title:'فئات شركة Topup',permission:'integrations.edit',accountTypes:['system'],membershipKinds:['owner'],navigationId:'topupCategories'},component:()=>import('../modules/digital/TopupCategoriesPage.vue') },
      { path:'/digital',name:'digital',meta:{requiresAuth:true,title:'خدمات API',permission:'digital.view',navigationId:'digital'},component:()=>import('../modules/digital/DigitalPage.vue') },
      { path:'/integrations',name:'integrations',meta:{requiresAuth:true,title:'الربط مع الشركات',permission:'integrations.view',accountTypes:['system'],membershipKinds:['owner']},props:{mode:'integrations'},component:()=>import('../modules/digital/DigitalPage.vue') },
      { path:'/map',name:'map',meta:{requiresAuth:true,title:'خريطة المستخدمين',permission:'map.view',navigationId:'map'},component:()=>import('../modules/maps/MapPage.vue') },
      { path:'/security',name:'security',meta:{requiresAuth:true,title:'الحماية والأمان',permission:'security.view',navigationId:'security',accountTypes:['system']},component:()=>import('../modules/security/SecurityPage.vue') },
      { path:'/account-times',name:'accountTime',meta:{requiresAuth:true,title:'أوقات صلاحية الحساب',permission:'security.policies',navigationId:'accountTime',accountTypes:['system'],membershipKinds:['owner']},component:()=>import('../modules/account-times/AccountTimePage.vue') },
      { path:'/deleted-accounts',name:'deletedAccounts',meta:{requiresAuth:true,title:'أرشيف المحذوفات',permission:'agents.archiveView',navigationId:'deletedAccounts',accountTypes:['system']},component:()=>import('../modules/archive/ArchivePage.vue') },
      { path:'/backup',name:'backup',meta:{requiresAuth:true,title:'النسخ الاحتياطي',permission:'backup.view',navigationId:'backup',accountTypes:['system'],membershipKinds:['owner']},component:()=>import('../modules/backups/BackupPage.vue') },
      { path:'/company',name:'company',meta:{requiresAuth:true,title:'موقع الشركة',permission:'company.view',navigationId:'company'},component:()=>import('../modules/company/CompanyPage.vue') },
      { path:'/company-settings',name:'company-settings',meta:{requiresAuth:true,title:'تعديل بروفايل الشركة',permission:'company.edit',navigationId:'company-settings',accountTypes:['system']},props:{management:true},component:()=>import('../modules/company/CompanyPage.vue') },
      {
        path:'/sell', name:'sell',
        meta:{requiresAuth:true,title:'بيع البطاقات',permission:'sell.create',navigationId:'sell',accountTypes:['sub_agent','sub_branch','pos']},
        component:()=>import('../modules/sales/SellPage.vue'),
      },
      ...[['sales','المبيعات','sales.view'],['exceptions','طلبات إعادة الطباعة','exceptions.view']].map(([name,title,permission])=>({
        path:`/${name}`,name,meta:{requiresAuth:true,title,permission,navigationId:name},
        component:()=>import('../modules/sales/SalesPage.vue'),
      })),
      {
        path:'/print-policies',name:'printPolicies',
        meta:{requiresAuth:true,title:'سياسات الطباعة',permission:'security.policies',navigationId:'printPolicies',accountTypes:['system']},
        component:()=>import('../modules/sales/PrintPoliciesPage.vue'),
      },
      {
        path:'/branding',name:'branding',
        meta:{requiresAuth:true,title:'تصميم الوصل',permission:'branding.view',navigationId:'branding',accountTypes:['system','main_agent']},
        component:()=>import('../modules/sales/ReceiptDesignerPage.vue'),
      },
      {
        path:'/support',name:'support',
        meta:{requiresAuth:true,title:'الدعم الفني',permission:'support.view',navigationId:'support'},
        component:()=>import('../modules/support/SupportPage.vue'),
      },
      {
        path:'/notifications',name:'notifications',
        meta:{requiresAuth:true,title:'الإشعارات',permission:'notifications.view',navigationId:'notifications'},
        component:()=>import('../modules/notifications/NotificationsPage.vue'),
      },
      {
        path:'/reports',name:'reports',
        meta:{requiresAuth:true,title:'التقارير',permission:'reports.view',navigationId:'reports'},
        component:()=>import('../modules/reports/ReportsPage.vue'),
      },
      {
        path: '/wallets', name: 'wallets',
        meta: {requiresAuth: true, title: 'المحافظ', permission: 'wallets.view', navigationId: 'wallets'},
        component: () => import('../modules/finance/WalletsPage.vue'),
      },
      {
        path: '/prices', name: 'prices',
        meta: {requiresAuth: true, title: 'الأسعار', permission: 'prices.view', navigationId: 'prices'},
        component: () => import('../modules/finance/PricesPage.vue'),
      },
      {
        path: '/import', name: 'import',
        meta: {requiresAuth:true,title:'الطلبيات',permission:'import.view',navigationId:'import',accountTypes:['system','main_agent']},
        component: () => import('../modules/stock/ImportPage.vue'),
      },
      ...[['inventory','المخزون','inventory.view'],['claims','البطاقات التالفة','claims.view'],['exports','تصدير البطاقات','exports.view']].map(([name,title,permission]) => ({
        path:`/${name}`,name,meta:{requiresAuth:true,title,permission,navigationId:name,accountTypes:['system','main_agent']},
        component: () => import('../modules/stock/StockWorkspace.vue'),
      })),
      ...[
        ["governorates", "المحافظات", "governorates.view", "governorates"],
        ["sources", "المصادر", "sources.view", "sources"],
        ["pos-types", "أنواع نقاط البيع", "posTypes.view", "posTypes"],
        ["representatives", "المندوبون", "representatives.view", "representatives"],
      ].map(([name, title, permission, navigationId]) => ({
        path: `/${name}`, name,
        meta: { requiresAuth: true, title, permission, navigationId, ...(name === 'governorates' ? {accountTypes:['system'],membershipKinds:['owner']} : {}) },
        component: () => import("../modules/reference/ReferencePage.vue"),
      })),
      {
        path: "/profile", name: "profile",
        meta: { requiresAuth: true, title: "حسابك" },
        component: () => import("../modules/accounts/ProfilePage.vue"),
      },
      {
        path: "/products",
        name: "products",
        meta: {
          requiresAuth: true,
          permission: "products.view",
          catalog: "products",
          title: "المنتجات والفئات",
        },
        component: () => import("../modules/catalog/CatalogPage.vue"),
      },
      {
        path: "/providers",
        name: "providers",
        meta: {
          requiresAuth: true,
          permission: "providers.view",
          catalog: "providers",
          title: "الشركات والمزودون",
        },
        component: () => import("../modules/catalog/CatalogPage.vue"),
      },
      {
        path: "/agents",
        name: "agents",
        meta: {
          requiresAuth: true,
          permission: "account.view",
          title: "الوكلاء",
        },
        component: () => import("../modules/accounts/NetworkWorkspace.vue"),
      },
      {
        path: "/pos",
        name: "pos",
        meta: {
          requiresAuth: true,
          permission: "account.view",
          title: "نقاط البيع",
        },
        component: () => import("../modules/accounts/NetworkWorkspace.vue"),
      },
      {
        path: "/staff",
        name: "staff",
        meta: {
          requiresAuth: true,
          permission: "staff.view",
          title: "الموظفون",
        },
        component: () => import("../modules/accounts/StaffPage.vue"),
      },
      {
        path: "/permissions",
        name: "permissions",
        meta: {
          requiresAuth: true,
          permission: "permission_profile.view",
          title: "الصلاحيات",
        },
        component: () => import("../modules/accounts/ProfilesPage.vue"),
      },
      { path: "/:pathMatch(.*)*", redirect: "/dashboard" },
    ],
  });

  router.beforeEach(async (to) => {
    if (!session.state.checked) await session.refresh();
    if (to.meta.requiresAuth && !session.state.identity)
      return { name: "login" };
    if (to.name === "login" && session.state.identity)
      return landingRoute(router, session);
    if (to.name === "agents" && session.state.identity?.account.type === "pos")
      return { name: "pos" };
    if (!routeAllowed(to, session)) return landingRoute(router, session);
  });
  return router;
}

import React, { useCallback, useEffect, useState } from 'react';
import { Header } from './components/Header';
import { AuthModal } from './components/AuthModal';
import { MarketplaceView } from './components/MarketplaceView';
import { DealsPipelineView } from './components/DealsPipelineView';
import { SellerInventoryView } from './components/SellerInventoryView';
import { DemandsView } from './components/DemandsView';
import { LedgerView } from './components/LedgerView';
import { DisputesView } from './components/DisputesView';
import { AdminConsoleView } from './components/AdminConsoleView';
import { MembersView } from './components/MembersView';
import { CatalogView } from './components/CatalogView';
import { PlatformConfigView } from './components/PlatformConfigView';
import { WarehouseMapView } from './components/WarehouseMapView';
import { OrganizationProfileView } from './components/OrganizationProfileView';
import { ProfileView } from './components/ProfileView';
import { api } from './lib/api';
import { getLocale, type Locale } from './lib/i18n';
import { applyBootstrapPayload, isFeatureEnabled, usePlatformConfig } from './lib/config';
import { AccountSetupCard } from './components/AccountSetupCard';
import type {
  ActiveOrganization,
  OrganizationSummary,
  ProfileCompletion,
  PurchaseRequest,
  User,
} from './types';
import { ShieldCheck, CheckCircle2, Building, Scale, Clock } from 'lucide-react';

/** Why each door stayed shut, in the operator's language. */
const REFUSAL_MESSAGES: Record<string, string> = {
  seller: 'ليس لديك صلاحية الوصول إلى إدارة العروض والمستودعات',
  deals: 'ليس لديك صلاحية عرض سجل الصفقات',
  demands: 'ليس لديك صلاحية عرض طلبات الاحتياج',
  ledger: 'ليس لديك صلاحية عرض دفتر الأستاذ والقيود',
  disputes: 'ليس لديك صلاحية الدخول إلى قسم النزاعات',
  admin: 'هذه اللوحة لقرارات المنصة: مراجعة العروض، اعتماد المنشآت، وحسابات المنصة',
  members: 'عضوية المنشأة تُعرض لحساب مسجّل يعمل ضمن منشأة نشطة',
  catalog: 'كتالوج الأصناف يحتاج وصولاً إلى قراءة العروض داخل منشأة نشطة',
  config: 'هذه الشاشة لإعدادات المنصة وقدراتها وسجل أحداثها وتقاريرها',
  org: 'سجّل الدخول أولًا: تسجيل المنشأة وملفها يُلحقان بحساب',
  profile: 'سجّل الدخول أولًا: هذه الشاشة تعدّل بيانات حسابك',
};

export default function App() {
  const [currentUser, setCurrentUser] = useState<User | null>(null);
  const [currentOrg, setCurrentOrg] = useState<ActiveOrganization | null>(null);
  /**
   * Every business this account belongs to, read from `/auth/me`. The header
   * switcher offers these and nothing else, because the server refuses a
   * named organization the caller is not a member of.
   */
  const [organizations, setOrganizations] = useState<OrganizationSummary[]>([]);
  /**
   * What the account still owes, as the server last answered. The client does
   * not decide which fields a completion needs — the operator's `account.require_*`
   * settings do — so this is stored and re-read rather than derived locally.
   */
  const [profileCompletion, setProfileCompletion] = useState<ProfileCompletion | null>(null);
  const [activeView, setActiveView] = useState<string>('marketplace');
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [disputeInitialDealId, setDisputeInitialDealId] = useState<number | null>(null);
  const [authMode, setAuthMode] = useState<'login' | 'register' | 'reset' | null>(null);
  const [locale, setLocale] = useState<Locale>(getLocale);
  const platformConfig = usePlatformConfig();

  // The screens below refetch when this changes; it is the organization the
  // session is acting as, so switching businesses must reload them.
  const currentPersonaId = String(currentOrg?.id ?? '');

  const orgName = locale === 'ar'
    ? currentOrg?.name_ar ?? currentOrg?.name_en ?? null
    : currentOrg?.name_en ?? currentOrg?.name_ar ?? null;

  /**
   * Read the session from the server rather than trusting a local copy. Every
   * path that changes who is signed in or which business they act as comes
   * through here, so the header, the switcher and the screens cannot drift into
   * describing three different organizations at once.
   *
   * `profile_completion` travels with it: what the account still owes is the
   * server's reading of its own settings, and a client copy would be a second
   * policy to keep in step.
   */
  const loadSession = useCallback(async () => {
    const response = await api.getMe();
    if (response.success && response.data?.user) {
      setCurrentUser(response.data.user);
      setCurrentOrg(response.data.current_organization || null);
      setOrganizations(response.data.organizations ?? []);
      setProfileCompletion(response.data.profile_completion ?? null);
      return true;
    }
    api.setToken(null);
    api.setTenantId(null);
    setCurrentUser(null);
    setCurrentOrg(null);
    setOrganizations([]);
    setProfileCompletion(null);
    return false;
  }, []);

  useEffect(() => {
    // The platform's currency, launch city, tax block and feature flags are
    // operator settings. Read them before anything renders money or a city.
    api.getBootstrap().then((response) => {
      if (response.success && response.data) applyBootstrapPayload(response.data);
    });
  }, []);

  useEffect(() => {
    const resetParams = new URLSearchParams(window.location.search);
    if (resetParams.get('reset_token')) {
      setAuthMode('reset');
    }
    if (!api.getToken()) return;
    void loadSession();
  }, [loadSession]);

  /**
   * Switching businesses is a server call: the endpoint re-issues the tenant
   * context, and the client only adopts it after the answer. Re-reading `/me`
   * afterwards is what makes the permissions on screen the new organization's
   * permissions rather than the old ones.
   */
  const selectOrganization = async (organizationId: number) => {
    const response = await api.switchOrganization(organizationId);
    if (!response.success) {
      setToastMessage('رفض الخادم تبديل المنشأة: لا يمكن العمل بمنشأة غير عضويًا بها');
      setTimeout(() => setToastMessage(null), 5000);
      return;
    }
    await loadSession();
  };

  const handleOrderSuccess = (purchaseRequest: PurchaseRequest) => {
    // A purchase request is an offer to buy. No stock is held and no money
    // moves until the seller answers it, so the message says exactly that.
    setToastMessage(`تم تسجيل طلب الشراء #${purchaseRequest?.id ?? ''} لدى البائع للمراجعة`);
    setTimeout(() => setToastMessage(null), 5000);
    setActiveView('deals');
  };

  const handleOpenDisputeFromDeal = (dealId: number) => {
    setDisputeInitialDealId(dealId);
    setActiveView('disputes');
  };

  const hasPermission = (permission: string) => Boolean(
    currentUser?.roles?.includes('SuperAdmin') || currentUser?.permissions?.includes(permission)
  );

  const hasRole = (role: string) => Boolean(currentUser?.roles?.includes(role));

  const canManagePlatform = () =>
    hasRole('SuperAdmin') ||
    hasPermission('offers.moderate') ||
    hasPermission('organizations.verify') ||
    hasPermission('users.manage');

  /**
   * The configuration screen is one door with four different gates behind it:
   * settings and the currency registry are `role:SuperAdmin` server-side, while
   * capability flags, the audit trail and reports are their own permissions. A
   * business admin therefore lands on the tabs they can actually use, and each
   * tab re-checks before it fetches.
   */
  const canConfigurePlatform = () =>
    hasRole('SuperAdmin') ||
    hasPermission('settings.manage') ||
    hasPermission('audit.view') ||
    hasPermission('reports.view');

  /**
   * The membership roster is answered only for a member of that organization,
   * so the door opens when the session actually carries an organization.
   * Editing it is a separate question the server decides per write.
   */
  const canViewMembers = () => Boolean(currentUser && currentOrg?.id);

  /** `/catalog/products` itself carries `permission:offers.view`. */
  const canViewCatalog = () => canViewMembers() && hasPermission('offers.view');

  const changeLocale = (nextLocale: Locale) => {
    api.setLocale(nextLocale);
    setLocale(nextLocale);
    document.documentElement.lang = nextLocale;
    document.documentElement.dir = nextLocale === 'ar' ? 'rtl' : 'ltr';
  };

  /**
   * One answer to "may this session open that screen?", used by the nav tabs,
   * the click guard and the render blocks. When the three disagree a user can be
   * offered a tab that then refuses to render, which is what happened to the
   * demands screen: the guard accepted `demands.view` while the other two checked
   * only `requests.view`.
   *
   * This is convenience, not security — the server gates every request on its
   * own middleware regardless of what is drawn here.
   */
  const canOpenView = (view: string): boolean => {
    switch (view) {
      case 'marketplace':
      case 'maps':
        return true;
      case 'seller':
        return hasPermission('offers.create') || hasPermission('warehouses.manage');
      case 'deals':
        return hasPermission('deals.view');
      case 'demands':
        // The entire `/demands` prefix — including the plain listing — carries
        // `permission:demands.view`. Checking only `requests.view` here offered
        // the tab to sessions the server refuses on every one of its calls.
        return hasPermission('demands.view');
      case 'ledger':
        return hasPermission('ledger.view');
      case 'disputes':
        return hasPermission('disputes.open') || hasPermission('disputes.resolve');
      case 'admin':
        return canManagePlatform();
      case 'config':
        return canConfigurePlatform();
      case 'members':
        return canViewMembers();
      case 'catalog':
        return canViewCatalog();
      case 'org':
        // Registering a business is the first thing an account has to be able to
        // do, and `POST /organizations` asks only for a token. Gating this door
        // on an existing organization made the screen unreachable for exactly
        // the account that needed it: one that had just registered and had no
        // business yet.
        return Boolean(currentUser);
      case 'profile':
        // No permission involved: the endpoint behind this screen edits the
        // caller's own account row and refuses everything else.
        return Boolean(currentUser);
      default:
        return false;
    }
  };

  const navigateToView = (view: string) => {
    if (!canOpenView(view)) {
      setToastMessage(REFUSAL_MESSAGES[view] ?? 'هذه الشاشة غير متاحة للحساب الحالي');
      setTimeout(() => setToastMessage(null), 5000);
      return;
    }

    setActiveView(view);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col justify-between selection:bg-emerald-600 selection:text-white">
      {/* Toast alert. `inset-x-3` on a phone because the fixed bottom bar would
          otherwise sit on top of the one message that tells a person whether
          their write reached the server. */}
      {toastMessage && (
        <div className="fixed inset-x-3 bottom-24 z-50 flex items-center gap-3 rounded-xl border border-emerald-700 bg-emerald-900 px-4 py-3 text-white shadow-2xl sm:inset-x-auto sm:right-5 sm:bottom-5 sm:px-5">
          <CheckCircle2 className="w-5 h-5 shrink-0 text-emerald-400" />
          <span className="min-w-0 flex-1 text-xs font-bold leading-relaxed">{toastMessage}</span>
          <button
            onClick={() => setToastMessage(null)}
            aria-label="إخفاء"
            className="shrink-0 rounded p-1 font-bold text-emerald-400 hover:text-white"
          >
            ✕
          </button>
        </div>
      )}

      <div>
        {/* Main Sticky Header */}
        <Header
          currentUser={currentUser}
          currentOrg={currentOrg}
          organizations={organizations}
          onSelectOrganization={(organizationId) => { void selectOrganization(organizationId); }}
          onLogin={() => setAuthMode('login')}
          onRegister={() => setAuthMode('register')}
          onLogout={async () => {
            await api.logout();
            setCurrentUser(null);
            setCurrentOrg(null);
            setOrganizations([]);
            setProfileCompletion(null);
            // Sign out takes the screen with it: every business view is gated on
            // a session, so leaving the tab on one would render a blank page
            // under a header that says "sign in".
            setActiveView('marketplace');
          }}
          canNavigate={canOpenView}
          locale={locale}
          onChangeLocale={changeLocale}
          activeView={activeView}
          onNavigate={navigateToView}
        />

        {/* View Switcher */}
        <main className="pb-24 md:pb-16">
          {/* What the account still owes, asked once and dismissible. The
              server's `profile_completion` decides the fields; nothing here
              invents a requirement. */}
          {currentUser && profileCompletion?.required && (
            <AccountSetupCard
              completion={profileCompletion}
              user={currentUser}
              locale={locale}
              onOpenOrganization={() => navigateToView('org')}
              onSaved={() => { void loadSession(); }}
            />
          )}

          {currentUser && currentOrg && !hasRole('SuperAdmin') && currentOrg.status !== 'verified' && (
            <div className="mx-auto mt-4 w-full max-w-7xl px-3 sm:px-6 lg:px-8">
              <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-[11px] leading-relaxed text-amber-900">
                <span className="font-bold">
                  {locale === 'ar'
                    ? 'لم تقرأ المنصة وثائق هذه المنشأة بعد: التصفح ورفع الأوراق والعمل الداخلي يعمل، والنشر وطلبات الشراء والصفقات تُرفض حتى القبول.'
                    : 'No reviewer has read this organization’s documents yet: browsing, filing and internal work run; publishing, purchase requests and deals are refused until then.'}
                </span>
                <span
                  className="text-amber-700"
                  title={locale === 'ar'
                    ? '«المراجعة» قرار موظف المنصة على الأوراق المرفوعة؛ المنصة لا تستعلم عن أي سجل رسمي.'
                    : '“Reviewed” means platform staff read the filed papers; the platform queries no official register.'}
                >
                  {locale === 'ar' ? 'المراجعة قرار داخلي، لا استعلام رسمي.' : 'Internal review, not an official check.'}
                </span>
                <button
                  type="button"
                  onClick={() => navigateToView('org')}
                  className="ms-auto rounded-lg border border-amber-400 bg-white px-3 py-1.5 font-bold text-amber-900 hover:bg-amber-100"
                >
                  {locale === 'ar' ? 'رفع الوثائق' : 'File documents'}
                </button>
              </div>
            </div>
          )}

          {activeView === 'marketplace' && (
            <MarketplaceView
              onOrderSuccess={handleOrderSuccess}
              locale={locale}
              currentPersonaId={currentPersonaId}
              onRequireSignIn={() => setAuthMode('login')}
            />
          )}

          {activeView === 'deals' && canOpenView('deals') && (
            <DealsPipelineView
              currentPersonaId={currentPersonaId}
              onOpenDispute={handleOpenDisputeFromDeal}
              can={hasPermission}
            />
          )}

          {activeView === 'seller' && canOpenView('seller') && (
            <SellerInventoryView currentPersonaId={currentPersonaId} />
          )}

          {activeView === 'demands' && canOpenView('demands') && (
            <DemandsView
              currentPersonaId={currentPersonaId}
              organizationId={currentOrg?.id ?? null}
              can={hasPermission}
            />
          )}

          {activeView === 'ledger' && canOpenView('ledger') && (
            <LedgerView currentPersonaId={currentPersonaId} />
          )}

          {activeView === 'disputes' && canOpenView('disputes') && (
            <DisputesView
              currentPersonaId={currentPersonaId}
              initialDealId={disputeInitialDealId}
              isPlatformAdmin={hasRole('SuperAdmin')}
              onNotify={setToastMessage}
            />
          )}

          {activeView === 'maps' && (
            <WarehouseMapView locale={locale} />
          )}

          {activeView === 'members' && canOpenView('members') && (
            <MembersView
              organizationId={currentOrg?.id ?? null}
              organizationName={orgName}
              myRole={currentOrg?.membership_role ?? null}
              isSuperAdmin={hasRole('SuperAdmin')}
              can={hasPermission}
            />
          )}

          {activeView === 'catalog' && canOpenView('catalog') && (
            <CatalogView can={hasPermission} />
          )}

          {activeView === 'org' && canOpenView('org') && (
            <OrganizationProfileView
              organizationId={currentOrg?.id ?? null}
              organizationName={orgName}
              myRole={currentOrg?.membership_role ?? null}
              isSuperAdmin={hasRole('SuperAdmin')}
              can={hasPermission}
              onSessionChanged={() => { void loadSession(); }}
            />
          )}

          {activeView === 'profile' && canOpenView('profile') && (
            <ProfileView
              currentUser={currentUser}
              locale={locale}
              onSessionChanged={() => { void loadSession(); }}
              onNotify={setToastMessage}
            />
          )}

          {activeView === 'admin' && canOpenView('admin') && (
            <AdminConsoleView can={hasPermission} onNotify={setToastMessage} />
          )}

          {activeView === 'config' && canOpenView('config') && (
            <PlatformConfigView
              can={hasPermission}
              isPlatformAdmin={hasRole('SuperAdmin')}
              onNotify={setToastMessage}
            />
          )}
        </main>
      </div>

      {authMode && (
        <AuthModal
          mode={authMode}
          locale={locale}
          onClose={() => setAuthMode(null)}
          onAuthenticated={() => {
            // The login response names an active organization but not the full
            // membership list, and the permissions belong to whichever one the
            // server settles on. Re-read `/auth/me` rather than guessing.
            void loadSession();
          }}
        />
      )}

      {/* Trust & Compliance Footer */}
      <footer className="bg-slate-900 text-slate-400 text-xs border-t border-slate-800">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-4 gap-6">
            <div className="space-y-2">
              <div className="flex items-center gap-2">
                <div className="w-8 h-8 rounded-lg bg-emerald-600 flex items-center justify-center text-white font-bold">
                  حـ
                </div>
                <span className="text-white font-bold text-base font-serif">حَصّـال — HASSAL</span>
              </div>
              <p className="text-[11px] leading-relaxed text-slate-400">
                سوق B2B ينظّم العرض والطلب في قطاع الأغذية والمخزون للشركات والمنشآت، ويبدأ من مدينة واحدة.
              </p>
            </div>

            <div className="space-y-1.5 text-[11px]">
              <div className="font-bold text-white text-xs">الامتثال والحوكمة التجارية:</div>
              <div className="flex items-center gap-1.5 text-slate-300">
                <ShieldCheck className="w-3.5 h-3.5 text-emerald-400" />
                <span>إدارة الامتثال قابلة للتهيئة بحسب متطلبات السوق</span>
              </div>
              <div className="flex items-center gap-1.5 text-slate-300">
                <Building className="w-3.5 h-3.5 text-emerald-400" />
                <span>الضريبة محكومة بالتهيئة، ولا تُحتسب قبل تفعيلها من الإدارة</span>
              </div>
              <div className="flex items-center gap-1.5 text-slate-300">
                <Scale className="w-3.5 h-3.5 text-emerald-400" />
                <span>تحكيم النزاعات يسجَّل بالقرار والسبب ومراجعة المشرف</span>
              </div>
            </div>

            <div className="space-y-1.5 text-[11px]">
              <div className="font-bold text-white text-xs">حساب الضمان المالي Escrow:</div>
              <p className="leading-relaxed text-slate-400">
                {isFeatureEnabled('escrow')
                  ? 'يعمل حساب الضمان في هذه التهيئة عبر وسيط مالي: لا تُفرج المنصة عن مبالغ الصفقات للبائعين إلا بعد الفحص والاستلام.'
                  : 'المنصة في هذه التهيئة لا تحتفظ بأموال العملاء. تتم التسوية بين الطرفين مباشرة أو عبر مزوّد دفع معتمد، ويُسجَّل ما تم استلامه كإثبات دفع من أحد الطرفين.'}
              </p>
            </div>

            <div className="space-y-1.5 text-[11px]">
              <div className="font-bold text-white text-xs">حالة النظام المقروءة من التهيئة:</div>
              <div className="bg-slate-950 p-2.5 rounded-lg border border-slate-800 space-y-1 font-mono text-[10px]">
                <div className="flex justify-between text-slate-400">
                  <span>Engine:</span>
                  <span className="text-emerald-400">Laravel + MySQL API</span>
                </div>
                <div className="flex justify-between text-slate-400">
                  <span>Reservation hold:</span>
                  <span className={platformConfig.reservationTimeoutMinutes ? 'text-emerald-400' : 'text-slate-500'}>
                    {platformConfig.reservationTimeoutMinutes
                      ? `Configured (${platformConfig.reservationTimeoutMinutes}m)`
                      : 'Not loaded'}
                  </span>
                </div>
                <div className="flex justify-between text-slate-400">
                  <span>Ledger:</span>
                  <span className={isFeatureEnabled('double_entry_ledger') ? 'text-emerald-400' : 'text-amber-400'}>
                    {isFeatureEnabled('double_entry_ledger') ? 'Enabled' : 'Disabled'}
                  </span>
                </div>
                <div className="flex justify-between text-slate-400">
                  <span>Escrow:</span>
                  <span className={isFeatureEnabled('escrow') ? 'text-emerald-400' : 'text-amber-400'}>
                    {isFeatureEnabled('escrow') ? 'Enabled' : 'Disabled'}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div className="border-t border-slate-800 pt-4 flex flex-col sm:flex-row items-center justify-between text-[11px] text-slate-500 gap-2">
            <div>جميع الحقوق محفوظة لشركة حصّال للمنصات الرقمية التجارية © 2026</div>
            <div className="flex items-center gap-4">
              <span>{platformConfig.country ? `دولة التشغيل: ${platformConfig.country}` : 'دولة التشغيل: غير مهيّأة'}</span>
              <span>•</span>
              <span>
                {platformConfig.defaultCity
                  ? `تبدأ المنصة من ${platformConfig.defaultCity}`
                  : 'لم تُحدَّد مدينة الانطلاق بعد'}
              </span>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}

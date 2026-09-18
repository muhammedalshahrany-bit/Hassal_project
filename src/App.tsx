import React, { useEffect, useState } from 'react';
import { Header } from './components/Header';
import { AuthModal } from './components/AuthModal';
import { MarketplaceView } from './components/MarketplaceView';
import { DealsPipelineView } from './components/DealsPipelineView';
import { SellerInventoryView } from './components/SellerInventoryView';
import { DemandsView } from './components/DemandsView';
import { LedgerEscrowView } from './components/LedgerEscrowView';
import { DisputesView } from './components/DisputesView';
import { AdminConsoleView } from './components/AdminConsoleView';
import { WarehouseMapView } from './components/WarehouseMapView';
import { api } from './lib/api';
import { getLocale, type Locale } from './lib/i18n';
import type { User, OrganizationMembership } from './types';
import { ShieldCheck, CheckCircle2, Building, Scale, Clock } from 'lucide-react';

export default function App() {
  const [currentUser, setCurrentUser] = useState<User | null>(null);
  const [currentOrg, setCurrentOrg] = useState<OrganizationMembership | null>(null);
  const [currentPersonaId, setCurrentPersonaId] = useState<string>('buyer');
  const [activeView, setActiveView] = useState<string>('marketplace');
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [disputeInitialDealId, setDisputeInitialDealId] = useState<number | null>(null);
  const [authMode, setAuthMode] = useState<'login' | 'register' | 'reset' | null>(null);
  const [locale, setLocale] = useState<Locale>(getLocale);

  useEffect(() => {
    const resetParams = new URLSearchParams(window.location.search);
    if (resetParams.get('reset_token')) {
      setAuthMode('reset');
    }
    if (!api.getToken()) return;

    api.getMe().then((response) => {
      if (response.success && response.data?.user) {
        setCurrentUser(response.data.user);
        setCurrentOrg(response.data.current_organization || null);
      } else {
        api.setToken(null);
        api.setTenantId(null);
      }
    });
  }, []);

  // A production client never auto-authenticates with embedded credentials.
  const switchPersona = async (organizationId: string) => {
    await api.switchOrganization(Number(organizationId));
    setCurrentPersonaId(organizationId);
  };

  const handleOrderSuccess = (purchaseRequest: any) => {
    setToastMessage(`تم حجز الكمية بنجاح عبر القفل الذري! طلب شراء #${purchaseRequest.id}`);
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
    hasPermission('settings.manage') ||
    hasPermission('audit.view') ||
    hasPermission('organizations.manage');

  const changeLocale = (nextLocale: Locale) => {
    api.setLocale(nextLocale);
    setLocale(nextLocale);
    document.documentElement.lang = nextLocale;
    document.documentElement.dir = nextLocale === 'ar' ? 'rtl' : 'ltr';
  };

  const navigateToView = (view: string) => {
    if (view === 'seller' && !(hasPermission('offers.create') || hasPermission('warehouses.manage'))) {
      setToastMessage('ليس لديك صلاحية الوصول إلى إدارة العروض والمستودعات');
      return;
    }
    if (view === 'deals' && !hasPermission('deals.view')) {
      setToastMessage('ليس لديك صلاحية عرض سجل الصفقات');
      return;
    }
    if (view === 'demands' && !hasPermission('requests.view') && !hasPermission('demands.view')) {
      setToastMessage('ليس لديك صلاحية عرض طلبات الاحتياج');
      return;
    }
    if (view === 'ledger' && !hasPermission('ledger.view')) {
      setToastMessage('ليس لديك صلاحية الوصول إلى المحفظة والضمان');
      return;
    }
    if (view === 'disputes' && !(hasPermission('disputes.open') || hasPermission('disputes.resolve'))) {
      setToastMessage('ليس لديك صلاحية الدخول إلى قسم النزاعات');
      return;
    }
    if (view === 'admin' && !canManagePlatform()) {
      setToastMessage('للاطلاع على لوحة الإدارة تحتاج إلى صلاحية الإدارة أو التدقيق');
      return;
    }

    setActiveView(view);
    window.scrollTo({ top: 0, behavior: 'smooth' });
  };

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900 flex flex-col justify-between selection:bg-emerald-600 selection:text-white">
      {/* Toast alert */}
      {toastMessage && (
        <div className="fixed bottom-5 right-5 z-50 bg-emerald-900 text-white px-5 py-3 rounded-xl shadow-2xl border border-emerald-700 flex items-center gap-3 animate-in fade-in slide-in-from-bottom-5">
          <CheckCircle2 className="w-5 h-5 text-emerald-400 shrink-0" />
          <span className="text-xs font-bold">{toastMessage}</span>
          <button onClick={() => setToastMessage(null)} className="text-emerald-400 hover:text-white font-bold pr-2">
            ✕
          </button>
        </div>
      )}

      <div>
        {/* Main Sticky Header */}
        <Header
          currentUser={currentUser}
          currentOrg={currentOrg}
          currentPersonaId={currentPersonaId}
          onSelectPersona={switchPersona}
          onLogin={() => setAuthMode('login')}
          onRegister={() => setAuthMode('register')}
          onLogout={async () => {
            await api.logout();
            setCurrentUser(null);
            setCurrentOrg(null);
          }}
          canNavigate={(view) => {
            if (view === 'seller') return hasPermission('offers.create') || hasPermission('warehouses.manage');
            if (view === 'deals') return hasPermission('deals.view');
            if (view === 'demands') return hasPermission('requests.view');
            if (view === 'ledger') return hasPermission('ledger.view');
            if (view === 'disputes') return hasPermission('disputes.open') || hasPermission('disputes.resolve');
            if (view === 'admin') return canManagePlatform();
            return true;
          }}
          locale={locale}
          onChangeLocale={changeLocale}
          activeView={activeView}
          onNavigate={navigateToView}
        />

        {/* View Switcher */}
        <main className="pb-16">
          {activeView === 'marketplace' && (
            <MarketplaceView onOrderSuccess={handleOrderSuccess} locale={locale} />
          )}

          {activeView === 'deals' && hasPermission('deals.view') && (
            <DealsPipelineView
              currentPersonaId={currentPersonaId}
              onOpenDispute={handleOpenDisputeFromDeal}
            />
          )}

          {activeView === 'seller' && (hasPermission('offers.create') || hasPermission('warehouses.manage')) && (
            <SellerInventoryView currentPersonaId={currentPersonaId} />
          )}

          {activeView === 'demands' && hasPermission('requests.view') && (
            <DemandsView currentPersonaId={currentPersonaId} />
          )}

          {activeView === 'ledger' && hasPermission('ledger.view') && (
            <LedgerEscrowView currentPersonaId={currentPersonaId} />
          )}

          {activeView === 'disputes' && (hasPermission('disputes.open') || hasPermission('disputes.resolve')) && (
            <DisputesView
              currentPersonaId={currentPersonaId}
              initialDealId={disputeInitialDealId}
            />
          )}

          {activeView === 'maps' && (
            <WarehouseMapView locale={locale} />
          )}

          {activeView === 'gmail' && (
            <section className="max-w-3xl mx-auto my-12 rounded-xl border border-slate-200 bg-white p-8 text-center">
              لا توجد رسائل أو بيانات بريد تجريبية في نسخة الإنتاج.
            </section>
          )}

          {activeView === 'admin' && (hasPermission('settings.manage') || hasPermission('audit.view') || currentUser?.roles?.includes('SuperAdmin')) && (
            <AdminConsoleView />
          )}
        </main>
      </div>

      {authMode && (
        <AuthModal
          mode={authMode}
          locale={locale}
          onClose={() => setAuthMode(null)}
          onAuthenticated={(user, organization) => {
            setCurrentUser(user);
            setCurrentOrg(organization);
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
                سوق B2B الرقمي الموثوق لتجارة وتصريف فرص الأغذية والمخزون للشركات والمنشآت في اليمن.
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
                <span>الفوترة والضريبة عبر طبقة قابلة لتبديل المزود</span>
              </div>
              <div className="flex items-center gap-1.5 text-slate-300">
                <Scale className="w-3.5 h-3.5 text-emerald-400" />
                <span>لائحة تحكيم تجاري وفحص بضائع معتمدة</span>
              </div>
            </div>

            <div className="space-y-1.5 text-[11px]">
              <div className="font-bold text-white text-xs">حساب الضمان المالي Escrow:</div>
              <p className="leading-relaxed text-slate-400">
                تدار العمليات المالية عبر حساب بنكي وسيط مستقل لا يفرج عن مبالغ الصفقات للبائعين إلا بعد الفحص والاستلام ومطابقة الفاتورة.
              </p>
            </div>

            <div className="space-y-1.5 text-[11px]">
              <div className="font-bold text-white text-xs">المقاييس التقنية الحية:</div>
              <div className="bg-slate-950 p-2.5 rounded-lg border border-slate-800 space-y-1 font-mono text-[10px]">
                <div className="flex justify-between text-slate-400">
                  <span>Engine:</span>
                  <span className="text-emerald-400">Laravel + MySQL API</span>
                </div>
                <div className="flex justify-between text-slate-400">
                  <span>Pessimistic Lock:</span>
                  <span className="text-emerald-400">Active (30m window)</span>
                </div>
                <div className="flex justify-between text-slate-400">
                  <span>Ledger:</span>
                  <span className="text-emerald-400">Double-Entry Verified</span>
                </div>
              </div>
            </div>
          </div>

          <div className="border-t border-slate-800 pt-4 flex flex-col sm:flex-row items-center justify-between text-[11px] text-slate-500 gap-2">
            <div>جميع الحقوق محفوظة لشركة حصّال للمنصات الرقمية التجارية © 2026</div>
            <div className="flex items-center gap-4">
              <span>الجمهورية اليمنية</span>
              <span>•</span>
              <span>منصة رقمية مهيأة للسوق اليمنية</span>
            </div>
          </div>
        </div>
      </footer>
    </div>
  );
}

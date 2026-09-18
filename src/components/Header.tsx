import React from 'react';
import { 
  Building2, 
  ShieldCheck, 
  UserCircle2, 
  ArrowRightLeft, 
  Activity, 
  Layers
} from 'lucide-react';
import { translations, type Locale } from '../lib/i18n';
import type { User, OrganizationMembership } from '../types';
import { NotificationBell } from './NotificationBell';

interface HeaderProps {
  currentUser: User | null;
  currentOrg: OrganizationMembership | null;
  currentPersonaId: string;
  onSelectPersona: (personaId: string) => void;
  onLogin: () => void;
  onRegister: () => void;
  onLogout: () => void;
  canNavigate: (view: string) => boolean;
  activeView: string;
  onNavigate: (view: string) => void;
  locale: Locale;
  onChangeLocale: (locale: Locale) => void;
}

export const Header: React.FC<HeaderProps> = ({
  currentUser,
  currentOrg,
  currentPersonaId: _currentPersonaId,
  onSelectPersona: _onSelectPersona,
  onLogin,
  onRegister,
  onLogout,
  canNavigate,
  activeView,
  onNavigate,
  locale,
  onChangeLocale,
}) => {
  const t = translations[locale];

  return (
    <header className="bg-white border-b border-slate-200 sticky top-0 z-40 shadow-xs">
      {/* Top Notification / Trust Bar */}
      <div className="bg-slate-900 text-slate-300 text-xs px-4 py-1.5 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-3">
          <span className="flex items-center gap-1.5 text-emerald-400 font-medium">
            <span className="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
            نظام حجز المخزون الذري وحساب الضمان Escrow قيد التشغيل المباشر
          </span>
          <span className="text-slate-600">|</span>
          <span className="text-slate-400">الضريبة قابلة للتهيئة وفق المتطلبات القانونية</span>
        </div>

        <div className="flex items-center gap-4 text-xs">
          <span className="text-amber-400 font-medium flex items-center gap-1">
            <ShieldCheck className="w-3.5 h-3.5" />
            توثيق السجل التجاري والـ KYB نشط
          </span>
          <span className="text-slate-400">{t.currency}</span>
        </div>
      </div>

      {/* Main Header Bar */}
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3.5 flex flex-wrap items-center justify-between gap-4">
        {/* Brand & Logo */}
        <div className="flex items-center gap-4">
          <div 
            onClick={() => onNavigate('marketplace')}
            className="flex items-center gap-3 cursor-pointer group"
          >
            <div className="w-11 h-11 rounded-xl bg-gradient-to-tr from-emerald-800 via-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-emerald-500/20 group-hover:scale-105 transition-transform">
              <span className="font-bold text-2xl tracking-tighter">حـ</span>
            </div>
            <div>
              <div className="flex items-center gap-2">
                <span className="text-2xl font-black text-slate-900 tracking-tight font-serif">حَصّـال</span>
                <span className="text-xs px-2 py-0.5 rounded-full font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                  HASSAL B2B
                </span>
              </div>
              <p className="text-xs text-slate-500">منصة الفرص والمخزون الغذائي التجاري</p>
            </div>
          </div>
        </div>

        {/* Live Persona & Tenant Switcher */}
        <div className="flex items-center gap-3">
          <div className="bg-slate-50 border border-slate-200 rounded-xl p-1.5 flex items-center gap-2">
            <span className="text-xs text-slate-500 font-medium px-2 flex items-center gap-1">
              <UserCircle2 className="w-4 h-4 text-slate-400" />
              {t.commercialContext}
            </span>

            <span className="bg-white text-slate-800 text-xs font-semibold rounded-lg border border-slate-200 px-3 py-1.5">{t.sessionOrganization}</span>
          </div>

          {/* Active Organization Info */}
          <div className="hidden md:flex items-center gap-2.5 bg-emerald-50/70 border border-emerald-200/80 rounded-xl px-3.5 py-1.5">
            <Building2 className="w-4 h-4 text-emerald-700" />
            <div className="text-right">
              <div className="text-xs font-bold text-emerald-950 flex items-center gap-1.5">
                <span>{currentOrg ? (locale === 'ar' ? currentOrg.name_ar : currentOrg.name_en) : t.chooseOrganization}</span>
                <span className="text-[10px] bg-emerald-200 text-emerald-900 px-1.5 py-0.2 rounded font-medium">
                  {currentUser ? 'مستخدم موثّق' : 'غير مسجل'}
                </span>
              </div>
              <div className="text-[11px] text-emerald-700 flex items-center gap-2">
                <span>س.ت: {currentOrg?.id ?? '—'}</span>
                <span>•</span>
                <span>المستخدم: {currentUser?.name?.split(' ')[0] || '—'}</span>
              </div>
            </div>
          </div>

          {currentUser ? (
            <div className="flex items-center gap-2"><NotificationBell locale={locale} /><button type="button" onClick={onLogout} className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50">{t.logout}</button></div>
          ) : (
            <div className="flex items-center gap-2">
              <button type="button" onClick={onLogin} className="rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-50">
                {t.login}
              </button>
              <button type="button" onClick={onRegister} className="rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-800">
                {t.register}
              </button>
            </div>
          )}
          <button
            type="button"
            onClick={() => onChangeLocale(locale === 'ar' ? 'en' : 'ar')}
            className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 hover:bg-slate-50"
            aria-label={t.switchLanguage}
          >
            {t.switchLanguage}
          </button>
        </div>
      </div>

      {/* Navigation Tabs */}
      <div className="border-t border-slate-100 bg-slate-50/80 px-4 sm:px-6 lg:px-8">
        <div className="max-w-7xl mx-auto flex items-center gap-1 overflow-x-auto py-1 scrollbar-none text-xs font-medium">
          <button
            id="nav-marketplace"
            onClick={() => onNavigate('marketplace')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'marketplace'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>🛒</span>
            <span>{t.marketplace}</span>
          </button>

          {canNavigate('deals') && <button
            id="nav-deals"
            onClick={() => onNavigate('deals')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'deals'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>📋</span>
            <span>{t.deals}</span>
          </button>}

          {canNavigate('seller') && <button
            id="nav-seller"
            onClick={() => onNavigate('seller')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'seller'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>🏬</span>
            <span>{t.seller}</span>
          </button>}

          {canNavigate('demands') && <button
            id="nav-demands"
            onClick={() => onNavigate('demands')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'demands'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>📢</span>
            <span>{t.demands}</span>
          </button>}

          <button
            id="nav-maps"
            onClick={() => onNavigate('maps')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'maps'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>🗺️</span>
            <span>{t.maps}</span>
          </button>

          <button
            id="nav-gmail"
            onClick={() => onNavigate('gmail')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'gmail'
                ? 'bg-white text-red-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>✉️</span>
            <span>{t.gmail}</span>
          </button>

          {canNavigate('ledger') && <button
            id="nav-ledger"
            onClick={() => onNavigate('ledger')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'ledger'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>💰</span>
            <span>{t.ledger}</span>
          </button>}

          {canNavigate('disputes') && <button
            id="nav-disputes"
            onClick={() => onNavigate('disputes')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'disputes'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>⚖️</span>
            <span>{t.disputes}</span>
          </button>}

          {canNavigate('admin') && <button
            id="nav-admin"
            onClick={() => onNavigate('admin')}
            className={`px-3.5 py-2 rounded-lg flex items-center gap-1.5 transition-colors whitespace-nowrap ${
              activeView === 'admin'
                ? 'bg-white text-indigo-800 shadow-2xs font-bold border border-slate-200/80'
                : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100'
            }`}
          >
            <span>🛠️</span>
            <span>{t.admin}</span>
          </button>}
        </div>
      </div>
    </header>
  );
};

import React, { useEffect, useRef, useState } from 'react';
import {
  Building2,
  ChevronDown,
  Languages,
  LogOut,
  Moon,
  MoreHorizontal,
  Settings2,
  ShieldCheck,
  Sun,
  UserCircle2,
  X,
} from 'lucide-react';
import { translations, type Locale } from '../lib/i18n';
import { useTheme } from '../lib/theme';
import { baseCurrency, isFeatureEnabled, taxSummary, usePlatformConfig } from '../lib/config';
import {
  MORE_LABEL,
  ACCOUNT_DESTINATIONS,
  GROUP_LABELS,
  groupNav,
  mobileOverflowNav,
  mobilePrimaryNav,
  TAB_ITEMS,
} from '../lib/navigation';
import type { User, ActiveOrganization, OrganizationSummary, OrganizationType } from '../types';
import { recorded } from './panel';
import { NotificationBell } from './NotificationBell';

interface HeaderProps {
  currentUser: User | null;
  currentOrg: ActiveOrganization | null;
  /** Every organization this account belongs to; the switcher offers these only. */
  organizations: OrganizationSummary[];
  onSelectOrganization: (organizationId: number) => void;
  onLogin: () => void;
  onRegister: () => void;
  onLogout: () => void;
  canNavigate: (view: string) => boolean;
  activeView: string;
  onNavigate: (view: string) => void;
  locale: Locale;
  onChangeLocale: (locale: Locale) => void;
}

/**
 * What the platform actually charges, read from the tax settings. An
 * installation that has not enabled tax must not display a rate it was never
 * given, and one that has must show the operator's number rather than a
 * number chosen by a developer.
 */
function taxLine(locale: Locale, tax: ReturnType<typeof taxSummary>): string {
  if (!tax) {
    return locale === 'en'
      ? 'Tax calculation is disabled for this installation'
      : 'احتساب الضريبة معطّل في هذه التهيئة';
  }
  const rate = `${tax.ratePercent}%`;
  if (locale === 'en') {
    return tax.inclusive
      ? `${tax.label || 'Tax'} ${rate} — included in displayed prices`
      : `${tax.label || 'Tax'} ${rate} — added at checkout`;
  }
  const label = tax.label || 'الضريبة';
  return tax.inclusive
    ? `${label} ${rate} — مشمولة في الأسعار المعروضة`
    : `${label} ${rate} — تُضاف عند الطلب`;
}

/** The four organization types the `organizations.type` column holds. */
function typeLabel(type: OrganizationType, locale: Locale): string {
  const labels: Record<OrganizationType, { ar: string; en: string }> = {
    buyer: { ar: 'منشأة مشتري', en: 'Buyer' },
    seller: { ar: 'منشأة بائع', en: 'Seller' },
    intermediary: { ar: 'وسيط', en: 'Intermediary' },
    admin: { ar: 'إدارة المنصة', en: 'Platform' },
  };
  const label = labels[type];
  if (!label) return type;
  return locale === 'ar' ? label.ar : label.en;
}

const controlClass =
  'rounded-xl border border-slate-200 bg-white px-3 py-2 text-xs font-bold text-slate-700 transition hover:bg-slate-50 dark:border-hassal-line dark:bg-hassal-surface dark:text-slate-200 dark:hover:bg-hassal-raised';

export const Header: React.FC<HeaderProps> = ({
  currentUser,
  currentOrg,
  organizations,
  onSelectOrganization,
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
  const config = usePlatformConfig(); // re-renders when the platform settings arrive
  const { theme, toggle } = useTheme();
  const tax = taxSummary();
  const escrowActive = isFeatureEnabled('escrow');
  // No flags at all means the bootstrap response has not arrived; it does not
  // mean the platform switched anything off.
  const flagsRead = Object.keys(config.featureFlags ?? {}).length > 0;
  const marketplaceActive = isFeatureEnabled('marketplace');

  const [sheet, setSheet] = useState<'none' | 'more' | 'account'>('none');
  /** The desktop dropdown under the account chip. Phones get the sheet instead. */
  const [menu, setMenu] = useState(false);
  const chipRef = useRef<HTMLDivElement | null>(null);

  /**
   * What the capabilities actually say, in the operator's language. The bar
   * used to assert "حجز المخزون مفعّل" without reading a setting, which is a
   * claim about the platform's promises rather than a fact about its state.
   */
  const capabilityLine = !flagsRead
    ? (locale === 'en' ? 'Platform state not read yet' : 'لم تُقرأ حالة المنصة بعد')
    : !marketplaceActive
      ? (locale === 'en'
        ? 'Marketplace is off: listings and purchase requests are disabled'
        : 'قدرة السوق معطّلة: نشر العروض وطلبات الشراء معطّلان')
      : escrowActive
        ? (locale === 'en'
          ? 'Marketplace live — settlements run through an escrow arrangement'
          : 'السوق يعمل، والتسوية تمرّ بترتيب حساب ضمان')
        : (locale === 'en'
          ? 'Marketplace live — the platform holds no customer funds'
          : 'السوق يعمل، والمنصة لا تحتفظ بأموال العملاء');

  const orgName = currentOrg
    ? (locale === 'ar' ? currentOrg.name_ar : currentOrg.name_en || currentOrg.name_ar)
    : (locale === 'en' ? 'No organization' : 'لا منشأة');

  const primaryNav = mobilePrimaryNav(canNavigate);
  const overflowNav = mobileOverflowNav(canNavigate, primaryNav.map((item) => item.id));
  // The account's own screens: they are reachable from the chip beside the
  // brand, and from nowhere else. Carrying them into the tab strip, the bottom
  // bar and the overflow sheet as well turned one room into three doors.
  const accountNav = ACCOUNT_DESTINATIONS.filter((item) => canNavigate(item.id));
  const accountActive = ACCOUNT_DESTINATIONS.some((item) => item.id === activeView);
  // Only the sheet's own destinations light the "More" cell; an open account
  // screen belongs to the chip above it.
  const moreActive = overflowNav.some((item) => item.id === activeView);

  // Escape and a route change both take the sheets away; a drawer that survives
  // the tap it was opened for reads as a stuck screen.
  useEffect(() => {
    if (sheet === 'none') return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setSheet('none');
    };
    document.addEventListener('keydown', onKey);
    return () => document.removeEventListener('keydown', onKey);
  }, [sheet]);

  useEffect(() => {
    setSheet('none');
    setMenu(false);
  }, [activeView]);

  // The desktop menu closes on the next click anywhere outside the chip, the
  // same way a native menu does. A control that only closes on its own button
  // makes people hunt for the ✕ that is not there.
  useEffect(() => {
    if (!menu) return;
    const onDown = (event: MouseEvent) => {
      if (!chipRef.current?.contains(event.target as Node)) setMenu(false);
    };
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setMenu(false);
    };
    document.addEventListener('mousedown', onDown);
    document.addEventListener('keydown', onKey);
    return () => {
      document.removeEventListener('mousedown', onDown);
      document.removeEventListener('keydown', onKey);
    };
  }, [menu]);

  const go = (view: string) => {
    setSheet('none');
    setMenu(false);
    onNavigate(view);
  };

  /**
   * Which business this session answers for — or the honest reason that it
   * answers for none.
   *
   * «سجّل الدخول لاختيار المنظمة» used to be printed at an account that is
   * already signed in and simply has no business yet: it told a person standing
   * in the doorway that they first had to come in. Three different situations
   * now say three different things, and each of the two a person can act on
   * carries the action — registering needs only a token, and choosing is what
   * the account's business file is for.
   *
   * Defined once and drawn twice (the desktop strip and the phone sheet), in
   * whichever width that surface has.
   */
  const contextControl = (fullWidth: boolean) => {
    const field = `rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 dark:border-hassal-line dark:bg-hassal-surface dark:text-slate-100${
      fullWidth ? ' w-full rounded-xl px-3 py-2.5 text-sm' : ''
    }`;

    if (organizations.length > 1) {
      // One account may belong to several businesses; the server answers for
      // whichever this request names, so the choice has to be on screen and not
      // only in storage.
      return (
        <select
          id="organization-switcher"
          value={currentOrg?.id ?? ''}
          onChange={(event) => onSelectOrganization(Number(event.target.value))}
          className={`${field} cursor-pointer`}
        >
          {organizations.map((organization) => (
            <option key={organization.id} value={organization.id}>
              {(locale === 'ar' ? organization.name_ar : organization.name_en || organization.name_ar)
                + ` — ${typeLabel(organization.type, locale)}`}
            </option>
          ))}
        </select>
      );
    }

    if (currentOrg) {
      return <span className={field}>{orgName}</span>;
    }

    const empty = !currentUser
      ? (locale === 'en' ? 'Sign in to choose a business' : 'سجّل الدخول لاختيار منشأة')
      : organizations.length === 0
        ? (locale === 'en' ? 'No business registered yet — register one' : 'لا توجد منشأة بعد — سجّل منشأتك')
        : (locale === 'en' ? 'No business chosen — open the file' : 'لم تُختر منشأة — افتح ملفها');

    return (
      <button
        type="button"
        onClick={() => {
          if (currentUser) go('org');
          else onLogin();
        }}
        title={locale === 'en'
          ? 'The business a session trades as'
          : 'المنشأة التي يتحدث باسمها هذا المسار'}
        className={`${field} text-start transition-colors hover:border-emerald-300 hover:bg-emerald-50 dark:hover:bg-hassal-raised`}
      >
        {empty}
      </button>
    );
  };

  /**
   * Language and appearance, defined once. A signed-in session finds them in
   * the account sheet beside its own screens; a guest has no account to tap, so
   * the same two controls are the whole reason their sheet exists. Written
   * twice, they would drift the first time one of them was restyled.
   */
  const displayControls = (
    <div className="grid grid-cols-2 gap-2">
      <button type="button" onClick={toggle} className={`${controlClass} flex items-center justify-center gap-2`}>
        {theme === 'dark'
          ? <Sun className="h-4 w-4 shrink-0" />
          : <Moon className="h-4 w-4 shrink-0" />}
        {theme === 'dark' ? (locale === 'en' ? 'Light' : 'نهاري') : (locale === 'en' ? 'Dark' : 'ليلي')}
      </button>
      <button
        type="button"
        onClick={() => onChangeLocale(locale === 'ar' ? 'en' : 'ar')}
        className={`${controlClass} flex items-center justify-center gap-2`}
      >
        <Languages className="h-4 w-4 shrink-0" />
        {t.switchLanguage}
      </button>
    </div>
  );

  return (
    <header className="sticky top-0 z-40 border-b border-slate-200 bg-white shadow-xs dark:border-hassal-line dark:bg-hassal-surface">
      {/* Trust / state strip. On a phone this information is one tap away in the
          account sheet rather than three lines above the page: the first screen
          a buyer sees should be the goods, not the platform's notices. */}
      <div className="hidden items-center justify-between gap-3 bg-slate-900 px-4 py-1.5 text-xs text-slate-300 md:flex md:px-6 lg:px-8">
        <div className="flex min-w-0 items-center gap-3">
          <span className={`flex items-center gap-1.5 font-medium ${
            !flagsRead || !marketplaceActive ? 'text-amber-400' : 'text-emerald-400'
          }`}>
            <span className={`h-2 w-2 animate-pulse rounded-full ${
              !flagsRead || !marketplaceActive ? 'bg-amber-500' : 'bg-emerald-500'
            }`} />
            <span className="truncate">{capabilityLine}</span>
          </span>
          <span className="text-slate-600">|</span>
          <span className="text-slate-400">{taxLine(locale, tax)}</span>
        </div>

        <div className="flex shrink-0 items-center gap-4 text-xs">
          <span className="flex items-center gap-1 font-medium text-amber-400">
            <ShieldCheck className="h-3.5 w-3.5" />
            {locale === 'en'
              ? 'Registration documents are reviewed by the platform team'
              : 'وثائق السجل التجاري تُراجع من فريق المنصة'}
          </span>
          <span className="text-slate-400">
            {t.currencyPrefix} {baseCurrency() ?? t.notConfigured}
          </span>
        </div>
      </div>

      {/* Main bar */}
      <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-x-3 gap-y-2 px-3 py-2.5 sm:px-6 md:px-4 lg:px-8">
        <div className="flex min-w-0 items-center gap-2 sm:gap-3">
          <button
            type="button"
            onClick={() => go('marketplace')}
            className="group flex items-center gap-2.5 rounded-xl p-0.5 text-start focus-visible:ring-2 focus-visible:ring-emerald-600"
          >
            <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-tr from-emerald-800 via-emerald-600 to-teal-500 text-white shadow-md shadow-emerald-500/20 transition-transform group-hover:scale-105">
              <span className="text-xl font-bold tracking-tighter">حـ</span>
            </span>
            <span className="min-w-0">
              <span className="flex items-center gap-2">
                <span className="font-serif text-lg font-black tracking-tight text-slate-900 sm:text-xl dark:text-slate-50">
                  حَصّـال
                </span>
                <span className="hidden rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-800 sm:inline">
                  HASSAL B2B
                </span>
              </span>
              <span className="hidden truncate text-xs text-slate-500 lg:block dark:text-slate-400">
                منصة الفرص والمخزون الغذائي التجاري
              </span>
            </span>
          </button>

          {/* The account, beside the brand — its only door.
              The profile used to sit in the tab strip, the phone's bottom bar and
              the account sheet as well as here: three doors to one room. The
              account's three screens now live in exactly one place, this chip.
              On a desktop it opens a short menu under the name; on a phone it
              opens the sheet that holds the same three screens beside the
              business switcher and sign out. The legal-record entry is named in
              full there, because «ملف المنشأة والسجل» is the screen a business
              looks for by name and a truncated word would hide it. */}
          {currentUser ? (
            <>
              <div ref={chipRef} className="relative">
                <button
                  id="brand-profile-chip"
                  type="button"
                  onClick={() => {
                    if (window.matchMedia('(min-width: 768px)').matches) setMenu(!menu);
                    else {
                      setMenu(false);
                      setSheet(sheet === 'account' ? 'none' : 'account');
                    }
                  }}
                  aria-haspopup="menu"
                  aria-expanded={menu || sheet === 'account'}
                  aria-current={accountActive ? 'page' : undefined}
                  aria-label={locale === 'en'
                    ? 'Your account: profile, business record and team'
                    : 'حسابك: الملف الشخصي وملف المنشأة والفريق'}
                  title={locale === 'en'
                    ? 'Your account — profile, business record and team'
                    : 'حسابك — الملف الشخصي وملف المنشأة والفريق'}
                  className={`flex items-center gap-2 rounded-xl border px-2 py-1.5 text-start transition-colors ${
                    accountActive || menu
                      ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-hassal-raised'
                      : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-hassal-line dark:bg-hassal-surface dark:hover:bg-hassal-raised'
                  }`}
                >
                  <span className="relative shrink-0">
                    <UserCircle2 className="h-7 w-7 text-emerald-700 dark:text-emerald-400" />
                    <span
                      className={`absolute -bottom-0.5 -left-0.5 h-2.5 w-2.5 rounded-full border-2 border-white dark:border-hassal-surface ${
                        currentOrg?.status === 'verified' ? 'bg-emerald-500' : 'bg-amber-400'
                      }`}
                      title={
                        !currentOrg
                          ? (locale === 'en' ? 'No organization in context' : 'لا منشأة في السياق')
                          : currentOrg.status === 'verified'
                            ? (locale === 'en' ? 'Organization approved for trading' : 'المنشأة معتمدة للتداول')
                            : (locale === 'en' ? 'Organization not approved yet' : 'المنشأة لم تُعتمد للتداول بعد')
                      }
                    />
                  </span>
                  <span className="hidden leading-tight sm:block">
                    <span className="block max-w-[9rem] truncate text-[11px] font-black text-slate-900 dark:text-slate-100">
                      {currentUser.name || (locale === 'en' ? 'Signed-in account' : 'حساب مسجَّل')}
                    </span>
                    <span className="block truncate text-[10px] text-slate-500 dark:text-slate-400">
                      {orgName}
                    </span>
                  </span>
                  {/* Below the name's breakpoint the chip is an icon with a word,
                      because a bare circle does not say that it holds the
                      account. */}
                  <span className="text-[11px] font-black text-slate-900 sm:hidden dark:text-slate-100">
                    {locale === 'en' ? 'Account' : 'حسابي'}
                  </span>
                  <ChevronDown
                    className={`h-3.5 w-3.5 shrink-0 text-slate-400 transition-transform ${
                      menu || sheet === 'account' ? 'rotate-180' : ''
                    }`}
                  />
                </button>

                {menu && (
                  <div
                    role="menu"
                    aria-label={locale === 'en' ? 'Account' : 'الحساب'}
                    className="absolute start-0 top-full z-50 mt-1.5 w-72 rounded-2xl border border-slate-200 bg-white p-1.5 shadow-2xl dark:border-hassal-line dark:bg-hassal-surface"
                  >
                    <div className="px-2.5 py-2">
                      <div className="truncate text-xs font-black text-slate-900 dark:text-slate-100">
                        {currentUser.name || (locale === 'en' ? 'Signed-in account' : 'حساب مسجَّل')}
                      </div>
                      <div className="truncate text-[11px] text-slate-500 dark:text-slate-400">
                        {currentUser.email ?? orgName}
                      </div>
                    </div>
                    {accountNav.map((item) => (
                      <button
                        key={item.id}
                        role="menuitem"
                        id={`account-menu-${item.id}`}
                        type="button"
                        onClick={() => go(item.id)}
                        aria-current={activeView === item.id ? 'page' : undefined}
                        className={`flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-start text-xs font-bold transition-colors ${
                          activeView === item.id
                            ? 'bg-emerald-50 text-emerald-900 dark:bg-hassal-raised dark:text-emerald-200'
                            : 'text-slate-700 hover:bg-slate-50 dark:text-slate-200 dark:hover:bg-hassal-raised'
                        }`}
                      >
                        <item.Icon className="h-4 w-4 shrink-0 text-emerald-700 dark:text-emerald-400" />
                        <span className="min-w-0 truncate">{item.label[locale]}</span>
                      </button>
                    ))}
                    <div className="my-1 h-px bg-slate-100 dark:bg-hassal-line" />
                    <button
                      role="menuitem"
                      type="button"
                      onClick={() => {
                        setMenu(false);
                        onLogout();
                      }}
                      className="flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-start text-xs font-bold text-rose-700 hover:bg-rose-50 dark:text-rose-300 dark:hover:bg-hassal-raised"
                    >
                      <LogOut className="h-4 w-4 shrink-0" />
                      {t.logout}
                    </button>
                  </div>
                )}
              </div>
              <span className="md:hidden">
                <NotificationBell locale={locale} />
              </span>
            </>
          ) : (
            <button
              type="button"
              onClick={onLogin}
              className="ms-1 flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-[11px] font-bold text-slate-700 hover:bg-slate-50 dark:border-hassal-line dark:bg-hassal-surface dark:text-slate-200 dark:hover:bg-hassal-raised"
            >
              <UserCircle2 className="h-5 w-5 text-slate-400" />
              <span className="hidden sm:inline">
                {locale === 'en' ? 'Sign in to your profile' : 'سجّل الدخول لبروفايلك'}
              </span>
              <span className="sm:hidden">{t.login}</span>
            </button>
          )}
        </div>

        {/* Desktop context and controls */}
        <div className="hidden items-center gap-3 md:flex">
          <div className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 p-1.5 dark:border-hassal-line dark:bg-hassal-night">
            <span className="flex items-center gap-1 px-2 text-xs font-medium text-slate-500">
              <Building2 className="h-4 w-4 text-slate-400" />
              {t.commercialContext}
            </span>

            {contextControl(false)}
          </div>

          {/* Active organization facts: the number the business submitted, not
              the platform's row id — labelling `currentOrg.id` as a registration
              number was a claim no ministry recognises. The box is the way into
              the legal record, because a business that sees its registration
              number on screen expects that number's page to be under it. */}
          {currentOrg && (
            <button
              type="button"
              onClick={() => go('org')}
              disabled={!canNavigate('org')}
              title={locale === 'en' ? 'Organization & legal record' : 'ملف المنشأة والسجل'}
              className="hidden items-center gap-2.5 rounded-xl border border-emerald-200/80 bg-emerald-50/70 px-3.5 py-1.5 text-start transition hover:border-emerald-300 hover:bg-emerald-50 disabled:cursor-default lg:flex dark:border-hassal-line dark:bg-hassal-raised dark:hover:bg-hassal-raised"
            >
              <div className="text-end">
                <div className="flex items-center gap-1.5 text-xs font-bold text-emerald-950 dark:text-emerald-100">
                  <span className="max-w-[12rem] truncate">{orgName}</span>
                  <span className="rounded bg-emerald-200 px-1.5 py-0.5 text-[10px] font-medium text-emerald-900">
                    {locale === 'en' ? 'Signed in' : 'جلسة مسجَّلة'}
                  </span>
                </div>
                <div className="flex items-center gap-2 text-[11px] text-emerald-700 dark:text-emerald-300">
                  <span>{locale === 'en' ? 'CR' : 'س.ت'}: {recorded(currentOrg.cr_number)}</span>
                  <span>•</span>
                  <span>{locale === 'en' ? `Ref ${currentOrg.id}` : `مرجع داخلي ${currentOrg.id}`}</span>
                </div>
              </div>
            </button>
          )}

          {/* Sign out is not a bar control: it is the last row of the account
              menu beside the account's own screens. Left here as well it was a
              second door to the same act, and the more dangerous one to find by
              accident. */}
          {currentUser ? (
            <NotificationBell locale={locale} />
          ) : (
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={onLogin}
                className="rounded-xl border border-emerald-200 bg-white px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-50"
              >
                {t.login}
              </button>
              <button
                type="button"
                onClick={onRegister}
                className="rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white hover:bg-emerald-800"
              >
                {t.register}
              </button>
            </div>
          )}

          <button
            type="button"
            onClick={() => onChangeLocale(locale === 'ar' ? 'en' : 'ar')}
            className={controlClass}
            aria-label={t.switchLanguage}
          >
            <Languages className="me-1 inline h-3.5 w-3.5 align-[-2px]" />
            {t.switchLanguage}
          </button>

          {/* Light or dark. The label names the mode you are about to get,
              because a button that shows the mode you are already in reads as a
              broken control. The choice lives in this browser only. */}
          <button
            id="theme-toggle"
            type="button"
            onClick={toggle}
            aria-pressed={theme === 'dark'}
            title={theme === 'dark'
              ? (locale === 'en' ? 'Switch to the light theme' : 'التبديل إلى الوضع النهاري')
              : (locale === 'en' ? 'Switch to the dark theme' : 'التبديل إلى الوضع الليلي')}
            className="cursor-pointer rounded-xl border border-slate-200 bg-white p-2 text-slate-700 hover:bg-slate-50 dark:border-hassal-line dark:bg-hassal-surface dark:text-amber-300 dark:hover:bg-hassal-raised"
          >
            {theme === 'dark' ? <Sun className="h-4 w-4" /> : <Moon className="h-4 w-4" />}
          </button>
        </div>

        {/* Phone. Once there were two controls here: a register button and a
            sheet toggle that opened the same account sheet the chip beside the
            brand already opened. Now there is one sheet door per state — the
            chip when a session exists, and this button when it does not, which
            is where a guest reaches the language and the theme because a guest
            has no account to tap. */}
        <div className="flex items-center gap-2 md:hidden">
          {!currentUser && (
            <>
              <button
                type="button"
                onClick={onRegister}
                className="rounded-xl bg-emerald-700 px-3 py-2 text-xs font-bold text-white"
              >
                {t.register}
              </button>
              <button
                id="guest-sheet-toggle"
                type="button"
                onClick={() => setSheet(sheet === 'account' ? 'none' : 'account')}
                aria-expanded={sheet === 'account'}
                aria-label={locale === 'en' ? 'Language, theme and sign in' : 'اللغة والمظهر وتسجيل الدخول'}
                className="flex items-center gap-1 rounded-xl border border-slate-200 bg-white px-2.5 py-2 text-xs font-bold text-slate-700 dark:border-hassal-line dark:bg-hassal-surface dark:text-slate-200"
              >
                <Settings2 className="h-4 w-4" />
                <ChevronDown className={`h-3.5 w-3.5 transition-transform ${sheet === 'account' ? 'rotate-180' : ''}`} />
              </button>
            </>
          )}
        </div>
      </div>

      {/* Destination strip — tablets and up. A phone gets the bottom bar below
          instead of a row that has to be dragged sideways to be read.
          Between the two breakpoints the full names are a sentence each and the
          row would scroll without showing a scrollbar, so the short form is
          what a tablet reads and the long form what a desktop reads. */}
      <nav
        aria-label={locale === 'en' ? 'Sections' : 'أقسام المنصة'}
        className="hidden border-t border-slate-100 bg-slate-50/80 px-4 md:block md:px-6 lg:px-8 dark:border-hassal-line dark:bg-hassal-night"
      >
        <div className="mx-auto flex max-w-7xl items-center gap-1 overflow-x-auto py-1 text-xs font-medium scrollbar-none">
          {TAB_ITEMS.filter((item) => item.id === 'marketplace' || canNavigate(item.id)).map((item) => {
            const active = activeView === item.id;
            return (
              <button
                key={item.id}
                id={`nav-${item.id}`}
                type="button"
                onClick={() => go(item.id)}
                title={item.label[locale]}
                aria-current={active ? 'page' : undefined}
                className={`flex items-center gap-1.5 whitespace-nowrap rounded-lg px-2.5 py-2 transition-colors lg:px-3 ${
                  active
                    ? 'border border-slate-200/80 bg-white font-bold text-emerald-800 shadow-2xs dark:border-hassal-line dark:bg-hassal-raised dark:text-emerald-300'
                    : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900 dark:text-slate-400 dark:hover:bg-hassal-raised dark:hover:text-slate-100'
                }`}
              >
                <item.Icon className="h-4 w-4 shrink-0" />
                <span className="lg:hidden">{item.short[locale]}</span>
                <span className="hidden lg:inline">{item.label[locale]}</span>
              </button>
            );
          })}
        </div>
      </nav>

      {/* Phone: the sheet the header has no room for. A signed-in account finds
          its own screens, its businesses and its exit here; a guest finds the
          two ways in and the two controls — language and appearance — that
          nobody should need an account to change. */}
      {sheet === 'account' && (
        <Sheet
          title={currentUser
            ? (locale === 'en' ? 'Account & organization' : 'الحساب والمنشأة')
            : (locale === 'en' ? 'Sign in & preferences' : 'الدخول والإعدادات')}
          locale={locale}
          onClose={() => setSheet('none')}
        >
          {!currentUser ? (
            <div className="space-y-4">
              <div className="space-y-2">
                <button
                  type="button"
                  onClick={() => {
                    setSheet('none');
                    onLogin();
                  }}
                  className="w-full rounded-xl border border-emerald-200 bg-white py-3 text-sm font-bold text-emerald-800 dark:border-hassal-line dark:bg-hassal-raised dark:text-emerald-300"
                >
                  {t.login}
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setSheet('none');
                    onRegister();
                  }}
                  className="w-full rounded-xl bg-emerald-700 py-3 text-sm font-bold text-white"
                >
                  {t.register}
                </button>
              </div>
              <div>
                <div className="mb-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400">
                  {locale === 'en' ? 'Language & appearance' : 'اللغة والمظهر'}
                </div>
                {displayControls}
              </div>
            </div>
          ) : (
            <div className="space-y-4">
              <div>
                <div className="mb-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400">
                  {t.commercialContext}
                </div>
                {contextControl(true)}
                {currentOrg && (
                  <p className="mt-1 text-[11px] text-slate-500 dark:text-slate-400">
                    {locale === 'en' ? 'CR' : 'س.ت'}: {recorded(currentOrg.cr_number)}
                  </p>
                )}
              </div>

              {/* The account's own destinations, and only here. They left the tab
                  strip, the bottom bar and the overflow sheet because a screen
                  the account reaches by tapping its name does not need three
                  more doors elsewhere. */}
              <div>
                <div className="mb-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400">
                  {locale === 'en' ? 'Your screens' : 'أقسام حسابك'}
                </div>
                <div className="grid grid-cols-2 gap-2">
                  {accountNav.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => go(item.id)}
                      aria-current={activeView === item.id ? 'page' : undefined}
                      className={`${controlClass} flex items-center gap-2 text-start ${
                        activeView === item.id
                          ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-hassal-raised dark:text-emerald-200'
                          : ''
                      }`}
                    >
                      <item.Icon className="h-4 w-4 shrink-0" />
                      <span className="min-w-0 truncate">{item.label[locale]}</span>
                    </button>
                  ))}
                </div>
              </div>

              <div>
                <div className="mb-1.5 text-[11px] font-bold text-slate-500 dark:text-slate-400">
                  {locale === 'en' ? 'Language & appearance' : 'اللغة والمظهر'}
                </div>
                {displayControls}
              </div>

              <button
                type="button"
                onClick={() => {
                  setSheet('none');
                  onLogout();
                }}
                className="flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white py-3 text-sm font-bold text-rose-700 hover:bg-rose-50 dark:border-hassal-line dark:bg-hassal-surface dark:text-rose-300"
              >
                <LogOut className="h-4 w-4" />
                {t.logout}
              </button>
            </div>
          )}

          <p className="text-[11px] leading-relaxed text-slate-500 dark:text-slate-400">
            {capabilityLine} · {taxLine(locale, tax)}
          </p>
        </Sheet>
      )}

      {/* Phone: the overflow sheet — every destination the account may open that
          did not earn a cell in the bottom bar, printed under the heading it
          belongs to. A flat grid of nine equal buttons is what makes people
          read all nine; three named groups is what lets them read one. */}
      {sheet === 'more' && (
        <Sheet
          title={locale === 'en' ? 'All sections' : 'كل الأقسام'}
          locale={locale}
          onClose={() => setSheet('none')}
        >
          <div className="space-y-4">
            {groupNav(overflowNav).map((section) => (
              <div key={section.group}>
                <div className="mb-1.5 text-[11px] font-black uppercase tracking-wide text-slate-500 dark:text-slate-400">
                  {GROUP_LABELS[section.group][locale]}
                </div>
                <div className="grid grid-cols-2 gap-2">
                  {section.items.map((item) => (
                    <button
                      key={item.id}
                      type="button"
                      onClick={() => go(item.id)}
                      aria-current={activeView === item.id ? 'page' : undefined}
                      className={`flex items-center gap-2 rounded-xl border px-3 py-3 text-start text-xs font-bold ${
                        activeView === item.id
                          ? 'border-emerald-300 bg-emerald-50 text-emerald-900 dark:border-emerald-700 dark:bg-hassal-raised dark:text-emerald-200'
                          : 'border-slate-200 bg-white text-slate-700 dark:border-hassal-line dark:bg-hassal-surface dark:text-slate-200'
                      }`}
                    >
                      <item.Icon className="h-4 w-4 shrink-0 text-emerald-700 dark:text-emerald-400" />
                      <span className="min-w-0 leading-snug">{item.label[locale]}</span>
                    </button>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </Sheet>
      )}

      {/* Phone: bottom bar. Four cells reachable by thumb; the fourth never
          hides a destination, it lists the rest. */}
      <nav
        aria-label={locale === 'en' ? 'Main sections' : 'الأقسام الرئيسية'}
        className="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur md:hidden dark:border-hassal-line dark:bg-hassal-surface/95"
      >
        <div className="grid max-w-7xl grid-flow-col auto-cols-fr items-stretch gap-1 p-1.5">
          {primaryNav.map((item) => {
            const active = activeView === item.id;
            return (
              <button
                key={item.id}
                id={`mobile-nav-${item.id}`}
                type="button"
                onClick={() => go(item.id)}
                aria-current={active ? 'page' : undefined}
                className={`flex flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-1.5 text-[10px] font-bold transition-colors ${
                  active ? 'bg-emerald-50 text-emerald-800 dark:bg-hassal-raised dark:text-emerald-300' : 'text-slate-500 dark:text-slate-400'
                }`}
              >
                <item.Icon className="h-5 w-5" />
                <span className="max-w-full truncate">{item.short[locale]}</span>
              </button>
            );
          })}
          {/* The fourth cell is a list, not a hiding place: it holds every
              destination the account may open that did not earn a cell. It
              lights when the screen you are on came from that sheet, and not
              when you are on an account screen — the chip beside the brand is
              that door. */}
          <button
            id="mobile-nav-more"
            type="button"
            onClick={() => setSheet(sheet === 'more' ? 'none' : 'more')}
            aria-expanded={sheet === 'more'}
            aria-current={moreActive ? 'page' : undefined}
            className={`flex flex-col items-center justify-center gap-0.5 rounded-lg px-1 py-1.5 text-[10px] font-bold transition-colors ${
              moreActive
                ? 'bg-emerald-50 text-emerald-800 dark:bg-hassal-raised dark:text-emerald-300'
                : 'text-slate-500 dark:text-slate-400'
            }`}
          >
            <MoreHorizontal className="h-5 w-5" />
            <span>{MORE_LABEL[locale]}</span>
          </button>
        </div>
      </nav>
    </header>
  );
};

/**
 * A bottom sheet, because a phone menu that appears as a centered dialog covers
 * the thumb and inherits whatever stale padding the page had. It is one
 * component so the two sheets cannot drift apart.
 */
const Sheet: React.FC<{
  title: string;
  locale: Locale;
  onClose: () => void;
  children: React.ReactNode;
}> = ({ title, locale, onClose, children }) => (
  <div
    className="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/50 md:hidden"
    role="dialog"
    aria-modal="true"
    aria-label={title}
    onClick={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}
  >
    <div className="max-h-[80dvh] w-full space-y-4 overflow-y-auto rounded-t-2xl border-t border-slate-200 bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl dark:border-hassal-line dark:bg-hassal-surface">
      <div className="flex items-center justify-between gap-3">
        <h2 className="text-sm font-black text-slate-900 dark:text-slate-50">{title}</h2>
        <button
          type="button"
          onClick={onClose}
          aria-label={locale === 'en' ? 'Close' : 'إغلاق'}
          className="-m-1 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 dark:hover:bg-hassal-raised"
        >
          <X className="h-5 w-5" />
        </button>
      </div>
      {children}
    </div>
  </div>
);

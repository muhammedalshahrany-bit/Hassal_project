import React, { useState } from 'react';
import { LogIn, UserPlus, X } from 'lucide-react';
import { api } from '../lib/api';
import type { OrganizationMembership, User } from '../types';
import { translations, type Locale } from '../lib/i18n';

const DEMO_ACCOUNTS = [
  { role: 'Super Admin', email: 'superadmin@hassal.local', password: 'Hassal@2026!', description: 'إدارة كاملة للنظام' },
  { role: 'Seller Admin', email: 'seller.admin@hassal.local', password: 'Hassal@2026!', description: 'إدارة البائع ومخازنه' },
  { role: 'Seller Staff', email: 'seller.staff@hassal.local', password: 'Hassal@2026!', description: 'تنفيذ العمليات اليومية للبائع' },
  { role: 'Buyer Admin', email: 'buyer.admin@hassal.local', password: 'Hassal@2026!', description: 'إدارة المشتري وطلباته' },
  { role: 'Buyer Staff', email: 'buyer.staff@hassal.local', password: 'Hassal@2026!', description: 'إدارة الطلبات اليومية' },
  { role: 'Intermediary', email: 'intermediary@hassal.local', password: 'Hassal@2026!', description: 'تسوية الصفقات والربط' },
  { role: 'Auditor', email: 'auditor@hassal.local', password: 'Hassal@2026!', description: 'التدقيق والمراجعة' },
];

interface AuthModalProps {
  mode: 'login' | 'register' | 'reset';
  onClose: () => void;
  onAuthenticated: (user: User, organization: OrganizationMembership | null) => void;
  locale?: Locale;
}

export const AuthModal: React.FC<AuthModalProps> = ({ mode: initialMode, onClose, onAuthenticated, locale = 'ar' }) => {
  const t = translations[locale];
  const [mode, setMode] = useState<'login' | 'register' | 'reset'>(initialMode);
  const [forgotMode, setForgotMode] = useState(false);
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [phone, setPhone] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [organizationName, setOrganizationName] = useState('');
  const [organizationType, setOrganizationType] = useState<'buyer' | 'seller'>('buyer');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  React.useEffect(() => {
    const queryEmail = new URLSearchParams(window.location.search).get('email');
    if (queryEmail) setEmail(queryEmail);
  }, []);

  const submitForgotPassword = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    const response = await api.forgotPassword(email);
    setSubmitting(false);
    if (!response.success) {
      setError(response.message || 'تعذر إرسال رسالة الاستعادة');
      return;
    }
    setError('تم إرسال رابط الاستعادة إلى بريدك. في التشغيل المحلي ستجده في سجل Laravel.');
  };

  const submitResetPassword = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSubmitting(true);
    const token = new URLSearchParams(window.location.search).get('reset_token') || '';
    const response = await api.resetPassword({ email, token, password, password_confirmation: passwordConfirmation });
    setSubmitting(false);
    if (!response.success) {
      setError(response.message || 'تعذر تغيير كلمة المرور');
      return;
    }
    setError('تم تغيير كلمة المرور. يمكنك الآن تسجيل الدخول.');
    setMode('login');
    window.history.replaceState({}, '', window.location.pathname);
  };

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    setError(null);
    setSubmitting(true);

    const response = mode === 'login'
      ? await api.login(email, password)
      : await api.register({
          name,
          email,
          phone: phone || undefined,
          password,
          password_confirmation: passwordConfirmation,
          organization_name: organizationName || undefined,
          organization_type: organizationName ? organizationType : undefined,
        });

    setSubmitting(false);
    if (!response.success || !response.data?.token) {
      const validationErrors = response.error?.details;
      const firstValidationError = validationErrors && typeof validationErrors === 'object'
        ? Object.values(validationErrors).flat()[0]
        : null;
      setError(String(firstValidationError || response.message || 'تعذر إتمام العملية'));
      return;
    }

    const user = response.data.user as User;
    const organization = (response.data.active_organization || response.data.organization || null) as OrganizationMembership | null;
    onAuthenticated(user, organization);
    onClose();
  };

  const handleDemoLogin = async (account: (typeof DEMO_ACCOUNTS)[number]) => {
    setError(null);
    setSubmitting(true);
    const response = await api.login(account.email, account.password);
    setSubmitting(false);

    if (!response.success || !response.data?.token) {
      setError(response.message || 'تعذر تسجيل الدخول بالحساب التجريبي');
      return;
    }

    const user = response.data.user as User;
    const organization = (response.data.active_organization || response.data.organization || null) as OrganizationMembership | null;
    onAuthenticated(user, organization);
    onClose();
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="dialog" aria-modal="true">
      <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl" dir="rtl">
        <div className="mb-5 flex items-start justify-between gap-4">
          <div>
            <h2 className="text-xl font-black text-slate-900">{mode === 'login' ? 'تسجيل الدخول' : mode === 'reset' ? 'تعيين كلمة مرور جديدة' : 'إنشاء حساب جديد'}</h2>
              <p className="mt-1 text-xs text-slate-500">{mode === 'login' ? 'ادخل للوصول إلى إدارة المستودعات والصفقات.' : mode === 'reset' ? 'أدخل كلمة المرور الجديدة لحسابك.' : 'أنشئ حسابك وابدأ إدارة نشاطك التجاري.'}</p>
          </div>
          <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="إغلاق">
            <X className="h-5 w-5" />
          </button>
        </div>

        {mode === 'reset' ? (
          <form onSubmit={submitResetPassword} className="space-y-4">
            <label className="block text-sm font-bold text-slate-700">البريد الإلكتروني<input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            <label className="block text-sm font-bold text-slate-700">كلمة المرور الجديدة<input required minLength={8} type="password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            <label className="block text-sm font-bold text-slate-700">تأكيد كلمة المرور<input required minLength={8} type="password" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            {error && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-800">{error}</div>}
            <button disabled={submitting} type="submit" className="w-full rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:opacity-50">{submitting ? 'جارٍ الحفظ...' : 'حفظ كلمة المرور'}</button>
          </form>
        ) : forgotMode ? (
          <form onSubmit={submitForgotPassword} className="space-y-4">
            <label className="block text-sm font-bold text-slate-700">البريد الإلكتروني<input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            {error && <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-800">{error}</div>}
            <button disabled={submitting} type="submit" className="w-full rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white disabled:opacity-50">{submitting ? 'جارٍ الإرسال...' : 'إرسال رابط الاستعادة'}</button>
            <button type="button" onClick={() => { setForgotMode(false); setError(null); }} className="w-full text-sm font-bold text-slate-500 hover:text-slate-800">العودة لتسجيل الدخول</button>
          </form>
        ) : <>
        <div className="mb-5 grid grid-cols-2 rounded-xl bg-slate-100 p-1 text-sm font-bold">
          <button type="button" onClick={() => { setMode('login'); setError(null); }} className={`rounded-lg px-3 py-2 ${mode === 'login' ? 'bg-white text-emerald-800 shadow-sm' : 'text-slate-500'}`}>{t.login}</button>
          <button type="button" onClick={() => { setMode('register'); setError(null); }} className={`rounded-lg px-3 py-2 ${mode === 'register' ? 'bg-white text-emerald-800 shadow-sm' : 'text-slate-500'}`}>{t.register}</button>
        </div>

        <div className="mb-4 rounded-xl border border-emerald-200 bg-emerald-50/50 p-3">
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="text-xs font-black text-emerald-900">حسابات تجريبية لكل الأدوار</span>
            <span className="text-[10px] font-bold text-emerald-700">الرمز: Hassal@2026!</span>
          </div>
          <div className="grid grid-cols-1 gap-2">
            {DEMO_ACCOUNTS.map((account) => (
              <button
                key={account.email}
                type="button"
                onClick={() => handleDemoLogin(account)}
                className="flex items-center justify-between rounded-lg border border-emerald-200 bg-white px-2.5 py-2 text-right transition hover:border-emerald-400 hover:bg-emerald-50"
              >
                <div>
                  <div className="text-[11px] font-black text-slate-800">{account.role}</div>
                  <div className="text-[10px] text-slate-500">{account.description}</div>
                </div>
                <div className="text-[10px] font-bold text-emerald-700">{account.email}</div>
              </button>
            ))}
          </div>
        </div>

        <form onSubmit={submit} className="space-y-3">
          {mode === 'register' && <label className="block text-sm font-bold text-slate-700">الاسم الكامل<input required value={name} onChange={(event) => setName(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>}
          <label className="block text-sm font-bold text-slate-700">البريد الإلكتروني<input required type="email" value={email} onChange={(event) => setEmail(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
          {mode === 'register' && <label className="block text-sm font-bold text-slate-700">رقم الهاتف <span className="font-normal text-slate-400">(اختياري)</span><input value={phone} onChange={(event) => setPhone(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>}
          <label className="block text-sm font-bold text-slate-700">كلمة المرور<input required minLength={8} type="password" value={password} onChange={(event) => setPassword(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
          {mode === 'register' && <>
            <label className="block text-sm font-bold text-slate-700">تأكيد كلمة المرور<input required minLength={8} type="password" value={passwordConfirmation} onChange={(event) => setPasswordConfirmation(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            <label className="block text-sm font-bold text-slate-700">اسم المنظمة <span className="font-normal text-slate-400">(اختياري)</span><input value={organizationName} onChange={(event) => setOrganizationName(event.target.value)} className="mt-1 w-full rounded-xl border border-slate-200 p-3 font-normal outline-none focus:border-emerald-500" /></label>
            {organizationName && <label className="block text-sm font-bold text-slate-700">نوع المنظمة<select value={organizationType} onChange={(event) => setOrganizationType(event.target.value as 'buyer' | 'seller')} className="mt-1 w-full rounded-xl border border-slate-200 bg-white p-3 font-normal outline-none focus:border-emerald-500"><option value="buyer">مشتري</option><option value="seller">بائع</option></select></label>}
          </>}

          {error && <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700">{error}</div>}
          <button disabled={submitting} type="submit" className="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-700 px-4 py-3 font-bold text-white transition hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-50">
            {mode === 'login' ? <LogIn className="h-4 w-4" /> : <UserPlus className="h-4 w-4" />}
            {submitting ? (locale === 'ar' ? 'جارٍ التنفيذ...' : 'Processing...') : mode === 'login' ? t.login : t.register}
          </button>
          {mode === 'login' && <button type="button" onClick={() => { setForgotMode(true); setError(null); }} className="w-full text-sm font-bold text-emerald-700 hover:text-emerald-900">نسيت كلمة المرور؟</button>}
        </form>
        </>}
      </div>
    </div>
  );
};
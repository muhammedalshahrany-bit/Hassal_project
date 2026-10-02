import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  SlidersHorizontal,
  ToggleLeft,
  ToggleRight,
  Coins,
  ScrollText,
  PieChart,
  RefreshCw,
  Download,
  Plus,
  Wand2,
} from 'lucide-react';
import { SetupWizardPanel } from './SetupWizardPanel';
import { api, fieldError } from '../lib/api';
import { formatMoney, usePlatformConfig } from '../lib/config';
import { formatDateTime, getLocale } from '../lib/i18n';
import {
  BannerView,
  DecisionButton,
  ErrorNote,
  Fact,
  LabeledSelect,
  PanelShell,
  TextInput,
  recorded,
  type Banner,
} from './panel';
import type { AuditLog, Currency, FeatureFlag, SystemSetting } from '../types';

/**
 * The platform's own configuration, as the server holds it.
 *
 * Everything on this screen is read from an endpoint and written back through
 * the same contract; nothing here is a setting the client could invent. That
 * distinction matters more on this screen than anywhere else: a field that looks
 * editable but is not read by any code path is a switch that trains an operator
 * to believe it does something.
 *
 * The gates below mirror the route middleware one for one (§36) — the settings
 * and currency registry are `role:SuperAdmin` server-side, capability flags need
 * `settings.manage`, the audit trail `audit.view`, and reports `reports.view`.
 * Integration endpoints (API keys, webhooks) are deliberately absent: they are
 * scoped to one business, so they belong to that business's screen and not to
 * the platform's.
 */

type ConfigTab = 'settings' | 'wizard' | 'capabilities' | 'currencies' | 'audit' | 'reports';

interface PlatformConfigViewProps {
  can: (permission: string) => boolean;
  isPlatformAdmin: boolean;
  onNotify?: (message: string) => void;
}

/** Group names the server stores, in the order an operator reads them. */
const GROUP_LABELS: Record<string, string> = {
  platform: 'هوية المنصة',
  tax: 'الضريبة',
  commission: 'عمولة المنصة',
  operations: 'تشغيل الصفقات',
  legal: 'المتطلبات القانونية',
  trust: 'الثقة والمراجعة',
  security: 'أسقف الطلبات',
  uploads: 'مساحة المنشآت',
  matching: 'المطابقة والقرب الجغرافي',
};

const GROUP_ORDER = ['platform', 'tax', 'commission', 'operations', 'legal', 'trust', 'security', 'uploads', 'matching'];

const TYPE_LABELS: Record<string, string> = {
  string: 'نص',
  boolean: 'نعم/لا',
  integer: 'عدد صحيح',
  decimal: 'عدد عشري',
  json: 'تركيبة',
};

export const PlatformConfigView: React.FC<PlatformConfigViewProps> = ({ can, isPlatformAdmin, onNotify }) => {
  const tabs = useMemo(() => {
    const available: { id: ConfigTab; label: string; icon: React.ElementType }[] = [];
    if (isPlatformAdmin) available.push({ id: 'settings', label: 'إعدادات المنصة', icon: SlidersHorizontal });
    if (isPlatformAdmin) available.push({ id: 'wizard', label: 'الإعداد الأولي', icon: Wand2 });
    if (can('settings.manage')) available.push({ id: 'capabilities', label: 'القدرات المُفعّلة', icon: ToggleRight });
    if (isPlatformAdmin) available.push({ id: 'currencies', label: 'سجل العملات', icon: Coins });
    if (can('audit.view')) available.push({ id: 'audit', label: 'سجل الأحداث', icon: ScrollText });
    if (can('reports.view')) available.push({ id: 'reports', label: 'تقارير الصفقات', icon: PieChart });
    return available;
  }, [can, isPlatformAdmin]);

  const [activeTab, setActiveTab] = useState<ConfigTab>('settings');
  useEffect(() => {
    if (tabs.length && !tabs.some((tab) => tab.id === activeTab)) setActiveTab(tabs[0].id);
  }, [tabs, activeTab]);

  if (!tabs.length) {
    return (
      <div className="mx-auto my-12 max-w-3xl space-y-2 rounded-xl border border-slate-200 bg-white p-6 text-center sm:p-8">
        <SlidersHorizontal className="w-6 h-6 mx-auto text-slate-400" />
        <h2 className="text-sm font-black text-slate-900">هذه الشاشة لإعدادات المنصة</h2>
        <p className="text-xs text-slate-500 leading-relaxed">
          تظهر الأقسام هنا فقط للصلاحيات التي يمنحها الخادم للحساب الحالي: ضبط إعدادات المنصة وسجل العملات
          (دور إشرافي على المنصة)، أو تفعيل القدرات، أو قراءة سجل الأحداث، أو تقارير الصفقات.
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
      <div className="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h2 className="text-lg font-black text-slate-900">إعدادات المنصة</h2>
          <p className="text-xs text-slate-500 pt-0.5 leading-relaxed">
            كل قيمة هنا مقروءة من الخادم وتُكتب إليه. ما لا يقرأه أي مسار في النظام معلَّم صراحةً.
          </p>
        </div>
        {/* Six sections do not fit a phone row: the strip swipes instead of
            breaking into two lines of chips nobody can compare. */}
        <div
          role="tablist"
          className="scrollbar-none -mx-1 flex min-w-0 items-center gap-1.5 overflow-x-auto rounded-xl bg-slate-100 p-1 text-xs font-semibold sm:mx-0"
        >
          {tabs.map((tab) => {
            const Icon = tab.icon;
            return (
              <button
                key={tab.id}
                role="tab"
                aria-selected={activeTab === tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`flex shrink-0 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 transition-all ${
                  activeTab === tab.id ? 'bg-white text-emerald-900 shadow-2xs font-bold' : 'text-slate-600'
                }`}
              >
                <Icon className="w-3.5 h-3.5 shrink-0" />
                <span>{tab.label}</span>
              </button>
            );
          })}
        </div>
      </div>

      {activeTab === 'settings' && isPlatformAdmin && <SettingsPanel onNotify={onNotify} />}
      {activeTab === 'wizard' && isPlatformAdmin && <SetupWizardPanel />}
      {activeTab === 'capabilities' && can('settings.manage') && <CapabilitiesPanel onNotify={onNotify} />}
      {activeTab === 'currencies' && isPlatformAdmin && <CurrenciesPanel onNotify={onNotify} />}
      {activeTab === 'audit' && can('audit.view') && <AuditPanel isPlatform={isPlatformAdmin} />}
      {activeTab === 'reports' && can('reports.view') && <ReportsPanel onNotify={onNotify} />}
    </div>
  );
};

/* ────────────────────────────── settings ─────────────────────────────────── */

const SettingsPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [rows, setRows] = useState<SystemSetting[]>([]);
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  // Keys the server said no code reads. It is a fact about the write that just
  // happened, not something this file could know from a compiled-in list.
  const [unread, setUnread] = useState<Record<string, boolean>>({});

  const load = useCallback(async () => {
    setLoading(true);
    const result = await api.getSettings();
    if (result.success) {
      const list = (result.data ?? []) as SystemSetting[];
      setRows(list);
      setDrafts((current) => {
        const next: Record<string, string> = { ...current };
        list.forEach((row) => {
          if (next[row.key] === undefined) next[row.key] = row.value ?? '';
        });
        return next;
      });
    } else {
      setBanner({ kind: 'error', text: result.message || 'تعذّر قراءة الإعدادات' });
    }
    setLoading(false);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const platformRows = useMemo(() => rows.filter((row) => !row.organization_id), [rows]);
  const overrides = rows.length - platformRows.length;

  /** The box under the search filters this list in the browser; the server sent it whole. */
  const matchesSearch = useMemo(() => {
    const term = search.trim().toLowerCase();
    return (row: SystemSetting) => (term ? row.key.toLowerCase().includes(term) : true);
  }, [search]);
  const visibleCount = useMemo(
    () => platformRows.filter((row) => matchesSearch(row)).length,
    [platformRows, matchesSearch]
  );

  const grouped = useMemo(() => {
    const map = new Map<string, SystemSetting[]>();
    platformRows.forEach((row) => {
      const list = map.get(row.group) ?? [];
      list.push(row);
      map.set(row.group, list);
    });
    return [...map.entries()].sort(([a], [b]) => {
      const ia = GROUP_ORDER.indexOf(a);
      const ib = GROUP_ORDER.indexOf(b);
      if (ia === -1 && ib === -1) return a.localeCompare(b);
      if (ia === -1) return 1;
      if (ib === -1) return -1;
      return ia - ib;
    });
  }, [platformRows]);

  const save = async (row: SystemSetting) => {
    const value = drafts[row.key] ?? '';
    setBusyKey(row.key);
    setBanner(null);
    const result = await api.updateSetting(row.key, value);
    setBusyKey(null);

    if (!result.success) {
      setBanner({
        kind: 'error',
        text:
          result.error_code === 'SETTING_TYPE_MISMATCH'
            ? `${result.message || 'القيمة لا تطابق نوع الإعداد'} — النوع: ${TYPE_LABELS[row.type] ?? row.type}`
            : result.error_code === 'SETTING_NOT_EDITABLE'
              ? 'هذا الإعداد مقفل في قاعدة البيانات ولا يقبل التعديل.'
              : result.message || 'تعذّر حفظ الإعداد',
      });
      return;
    }

    setUnread((current) => ({ ...current, [row.key]: result.meta?.in_catalog === false }));
    setBanner({
      kind: 'ok',
      text:
        result.meta?.in_catalog === false
          ? `حُفظت القيمة، لكن لا يقرأها أي مسار في النظام: المفتاح ${row.key} ليس في كتالوج الإعدادات.`
          : `حُفظت القيمة في ${row.key}.`,
    });
    onNotify?.('تم حفظ الإعداد');
    await load();
  };

  return (
    <div className="space-y-4">
      <PanelShell
        title="قيم المنصة العامة"
        hint="كل سطر هو صف في قاعدة البيانات: قيمته، ونوعه الذي فُحص عند الكتابة، ومجموعته. التعديل يُرسل إلى /settings/update ويُسجَّل باسم الحساب."
      >
        <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2">
          <TextInput label="بحث بالمفتاح" value={search} onChange={setSearch} placeholder="tax.rate" />
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-end">
            {/* Counted here from what the server returned for this filter — a read
                count of the list, never a metric the platform publishes. */}
            <span className="order-2 text-center text-[11px] text-slate-500 sm:order-1 sm:text-right">
              {search.trim()
                ? `يطابق بحثك ${visibleCount} من ${platformRows.length} إعدادًا قرأها الخادم`
                : `${platformRows.length} إعدادًا قرأها الخادم`}
            </span>
            <span className="order-1 sm:order-2">
              <DecisionButton onClick={() => void load()} busy={loading}>
                <RefreshCw className="w-3.5 h-3.5" />
                إعادة القراءة
              </DecisionButton>
            </span>
          </div>
        </div>

        <BannerView banner={banner} />

        {overrides > 0 && (
          <ErrorNote
            text={`${overrides} إعداد مرخَّص لمنشأة بعينها؛ تُدار من شاشة تلك المنشأة ولا تُعدَّل من هنا.`}
          />
        )}

        {loading && !platformRows.length ? (
          <p className="text-xs text-slate-500">…</p>
        ) : null}

        {!loading && !platformRows.length ? (
          <p className="text-xs text-slate-500">
            لا توجد إعدادات مرخَّصة بعد. تُنشأ بقيام المشغّل بتعريفها، لا بتخمين قيم داخل الواجهة.
          </p>
        ) : null}

        {grouped.map(([group, list]) => {
          const visible = list.filter((row) => matchesSearch(row));
          if (!visible.length) return null;
          return (
            <div key={group} className="space-y-2">
              <h4 className="text-[11px] font-black text-slate-700">
                {GROUP_LABELS[group] ?? group}
                <span className="ps-1.5 font-medium text-slate-400">({visible.length})</span>
              </h4>
              {visible.map((row) => (
                <SettingRow
                  key={row.key}
                  row={row}
                  draft={drafts[row.key] ?? row.value ?? ''}
                  onDraft={(value) => setDrafts((d) => ({ ...d, [row.key]: value }))}
                  onSave={() => void save(row)}
                  busy={busyKey === row.key}
                  unread={unread[row.key] === true}
                />
              ))}
            </div>
          );
        })}
      </PanelShell>
    </div>
  );
};

const SettingRow: React.FC<{
  row: SystemSetting;
  draft: string;
  onDraft: (value: string) => void;
  onSave: () => void;
  busy: boolean;
  unread: boolean;
}> = ({ row, draft, onDraft, onSave, busy, unread }) => {
  const locked = !row.is_editable;
  // Is the box below out of step with what the server holds? A flag, not a claim:
  // nothing is written until this row's own save button is pressed.
  const stored =
    row.type === 'boolean' ? (row.value === '1' || row.value === 'true' ? '1' : '0') : (row.value ?? '');
  const dirty = draft !== stored;

  return (
    <div className="min-w-0 space-y-3 rounded-lg border border-slate-200 p-3 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(9rem,15rem)_auto] sm:items-end sm:gap-3 sm:space-y-0">
      <div className="flex-1 space-y-1 min-w-0">
        <div className="flex flex-wrap items-center gap-2">
          <code className="text-[11px] font-bold text-slate-800">{row.key}</code>
          <span className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600">
            {TYPE_LABELS[row.type] ?? row.type}
          </span>
          {row.is_public ? (
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700">يُرسل لعملاء المنصة</span>
          ) : null}
          {locked ? (
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-amber-50 text-amber-700">مقفل</span>
          ) : null}
          {unread ? (
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-rose-50 text-rose-700">لا يقرأه النظام</span>
          ) : null}
          {dirty && !locked ? (
            <span className="text-[10px] px-1.5 py-0.5 rounded bg-sky-50 text-sky-700">
              مكتوب في الصندوق، لم يُحفظ بعد
            </span>
          ) : null}
        </div>
        <p className="text-[11px] text-slate-500 leading-relaxed">
          {recorded(row.description_ar)}
          {row.value === null ? ' — لم تُضبط قيمة بعد' : ''}
        </p>
        {/* What the database holds right now, so an unsaved edit in the box below
            is never mistaken for the live value. */}
        <p className="text-[10px] text-slate-400">
          القيمة المطبَّقة الآن:{' '}
          <code className="break-all font-bold text-slate-600">
            {row.value === null
              ? 'لم تُضبط'
              : row.type === 'boolean'
                ? row.value === '1' || row.value === 'true'
                  ? 'مُفعّلة'
                  : 'موقوفة'
                : row.value}
          </code>
        </p>
      </div>

      {row.type === 'boolean' ? (
        <LabeledSelect
          label="القيمة"
          value={draft === '1' || draft === 'true' ? '1' : '0'}
          onChange={onDraft}
          options={[
            { value: '1', label: 'مُفعّل' },
            { value: '0', label: 'موقوف' },
          ]}
        />
      ) : (
        <TextInput
          label="القيمة"
          value={draft}
          onChange={onDraft}
          wide
          placeholder={row.type === 'decimal' ? '0.0000' : row.type === 'integer' ? '0' : ''}
        />
      )}

      <DecisionButton onClick={onSave} busy={busy} tone={locked ? 'default' : 'primary'}>
        {locked ? 'مقفل في قاعدة البيانات' : dirty ? 'حفظ التعديل' : 'حفظ القيمة'}
      </DecisionButton>
    </div>
  );
};

/* ──────────────────────────── capability flags ───────────────────────────── */

const CapabilitiesPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [flags, setFlags] = useState<FeatureFlag[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyKey, setBusyKey] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);

  const load = useCallback(async () => {
    setLoading(true);
    const result = await api.getFeatureFlags();
    if (result.success) setFlags((result.data ?? []) as FeatureFlag[]);
    else setBanner({ kind: 'error', text: result.message || 'تعذّر قراءة القدرات' });
    setLoading(false);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const toggle = async (flag: FeatureFlag) => {
    setBusyKey(flag.key);
    setBanner(null);
    const result = await api.toggleFeatureFlag(flag.key, !flag.is_enabled);
    setBusyKey(null);

    if (!result.success) {
      setBanner({
        kind: 'error',
        text:
          result.error_code === 'FLAG_NOT_IN_CATALOG'
            ? 'هذا الاسم ليس قدرة معرَّفة في الخادم.'
            : result.message || 'تعذّر تبديل القدرة',
      });
      return;
    }

    // The change is live the moment the server answers; the row re-reads rather
    // than trusting the click's own optimistic value.
    await load();
    onNotify?.(`${flag.name_ar}: ${result.data?.is_enabled ? 'مُفعّلة' : 'موقوفة'}`);
  };

  return (
    <PanelShell
      title="القدرات التي يفتحها الخادم"
      hint="إيقاف قدرة يجعل مساراتها ترد 503 FEATURE_DISABLED لكل حساب، بما في ذلك واجهات البائعين؛ إخفاء الزر وحده ليس منعًا. القائمة كلها من /feature-flags، ولا تُضاف قدرة من هذه الشاشة."
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="min-w-0 text-[11px] text-slate-500">
          {/* Counted here from the list /feature-flags returned whole: how many of
              the flags the server listed are on — not a published metric. */}
          {loading ? '…' : `${flags.filter((f) => f.is_enabled).length} من ${flags.length} قدرة مُفعّلة حاليًا`}
        </p>
        <DecisionButton onClick={() => void load()} busy={loading}>
          <RefreshCw className="w-3.5 h-3.5" />
          إعادة القراءة
        </DecisionButton>
      </div>

      <BannerView banner={banner} />

      <div className="space-y-2">
        {flags.map((flag) => (
          <div key={flag.key} className="min-w-0 flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 p-3">
            {flag.is_enabled ? (
              <ToggleRight className="w-5 h-5 shrink-0 text-emerald-600" />
            ) : (
              <ToggleLeft className="w-5 h-5 shrink-0 text-slate-400" />
            )}
            <div className="min-w-0 flex-1 basis-[10rem]">
              <div className="flex flex-wrap items-center gap-2">
                <span className="text-xs font-bold text-slate-900">{flag.name_ar}</span>
                <code className="break-all text-[10px] text-slate-500">{flag.key}</code>
                <span
                  className={`shrink-0 rounded-full border px-2 py-0.5 text-[10px] font-bold ${
                    flag.is_enabled
                      ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                      : 'border-slate-200 bg-slate-100 text-slate-600'
                  }`}
                >
                  {flag.is_enabled ? 'مُفعّلة' : 'موقوفة'}
                </span>
              </div>
              <p className="text-[11px] leading-relaxed text-slate-500 pt-0.5">{recorded(flag.description_ar)}</p>
            </div>
            <button
              onClick={() => void toggle(flag)}
              disabled={busyKey === flag.key}
              className={`w-full shrink-0 rounded-lg border px-3 py-2 text-xs font-bold disabled:opacity-40 cursor-pointer sm:w-auto ${
                flag.is_enabled
                  ? 'bg-white text-rose-700 border-rose-200 hover:bg-rose-50'
                  : 'bg-emerald-600 text-white border-emerald-600 hover:bg-emerald-700'
              }`}
            >
              {flag.is_enabled ? 'إيقاف القدرة' : 'تفعيل القدرة'}
            </button>
          </div>
        ))}
      </div>
    </PanelShell>
  );
};

/* ─────────────────────────────── currencies ──────────────────────────────── */

const CurrenciesPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const config = usePlatformConfig();
  const [rows, setRows] = useState<Currency[]>([]);
  const [loading, setLoading] = useState(true);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [editing, setEditing] = useState<number | null>(null);
  const [form, setForm] = useState<Record<string, string>>({});
  const [creating, setCreating] = useState(false);
  const [newCurrency, setNewCurrency] = useState({ iso_code: '', name_ar: '', name_en: '', symbol: '', decimal_places: '2', exchange_rate_to_base: '' });

  const load = useCallback(async () => {
    setLoading(true);
    const result = await api.getCurrencies();
    if (result.success) setRows((result.data ?? []) as Currency[]);
    else setBanner({ kind: 'error', text: result.message || 'تعذّر قراءة سجل العملات' });
    setLoading(false);
  }, []);

  useEffect(() => {
    void load();
  }, [load]);

  const startEdit = (currency: Currency) => {
    setEditing(currency.id);
    setForm({
      symbol: currency.symbol,
      decimal_places: String(currency.decimal_places),
      exchange_rate_to_base: String(currency.exchange_rate_to_base),
      is_active: currency.is_active ? '1' : '0',
    });
  };

  const saveEdit = async (currency: Currency) => {
    setBusyId(currency.id);
    setBanner(null);
    const result = await api.updateCurrency(currency.id, {
      symbol: form.symbol,
      decimal_places: Number(form.decimal_places),
      exchange_rate_to_base: Number(form.exchange_rate_to_base),
      is_active: form.is_active === '1',
    });
    setBusyId(null);
    if (!result.success) {
      setBanner({
        kind: 'error',
        text:
          fieldError(result, 'exchange_rate_to_base') ||
          fieldError(result, 'decimal_places') ||
          result.message ||
          'تعذّر تحديث العملة',
      });
      return;
    }
    setEditing(null);
    onNotify?.(`تم تحديث ${currency.iso_code}`);
    await load();
  };

  const createCurrency = async () => {
    setBusyId(-1);
    setBanner(null);
    const result = await api.createCurrency({
      ...newCurrency,
      decimal_places: Number(newCurrency.decimal_places),
      exchange_rate_to_base: Number(newCurrency.exchange_rate_to_base),
    });
    setBusyId(null);
    if (!result.success) {
      const first =
        fieldError(result, 'iso_code') ||
        fieldError(result, 'name_ar') ||
        fieldError(result, 'name_en') ||
        fieldError(result, 'symbol') ||
        fieldError(result, 'exchange_rate_to_base') ||
        result.message ||
        'تعذّر إضافة العملة';
      setBanner({ kind: 'error', text: first });
      return;
    }
    setCreating(false);
    onNotify?.(`أُضيفت ${newCurrency.iso_code.toUpperCase()}`);
    await load();
  };

  const makeDefault = async (currency: Currency) => {
    setBusyId(currency.id);
    setBanner(null);
    const result = await api.setDefaultCurrency(currency.id);
    setBusyId(null);
    if (!result.success) {
      setBanner({ kind: 'error', text: result.message || 'تعذّر ضبط العملة الأساسية' });
      return;
    }
    await load();
    setBanner({
      kind: 'ok',
      text: `${currency.iso_code} الآن العملة الأساسية: سعرها صُوب إلى 1، وضُبطت قيمة platform.default_currency تلقائيًا.`,
    });
    onNotify?.(`العملة الأساسية الآن ${currency.iso_code}`);
  };

  return (
    <PanelShell
      title="سجل العملات وأسعارها"
      hint="الأسعار يدوية من المشغّل (MANUAL_ADMIN_RATE) ومقيسة على العملة الأساسية. المنصة لا تجلب سعر صرف من جهة خارجية ولا تدّعي أنه سعر السوق."
    >
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="min-w-0 text-[11px] text-slate-500">
          {/* Counted from the registry the server returned in full. */}
          {loading ? '…' : `${rows.length} عملة مسجّلة`} · الأساسية: {config.baseCurrency ?? 'لم تُضبط'}
        </p>
        <div className="flex flex-wrap items-center gap-2">
          <DecisionButton onClick={() => void load()} busy={loading}>
            <RefreshCw className="w-3.5 h-3.5" />
            إعادة القراءة
          </DecisionButton>
          <DecisionButton onClick={() => setCreating((v) => !v)}>{creating ? 'إلغاء' : 'عملة جديدة'}</DecisionButton>
        </div>
      </div>

      <BannerView banner={banner} />

      {creating ? (
        <div className="border border-slate-200 rounded-lg p-3 space-y-3 bg-slate-50">
          <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <TextInput label="ISO (3)" value={newCurrency.iso_code} onChange={(v) => setNewCurrency({ ...newCurrency, iso_code: v })} wide />
            <TextInput label="الاسم عربي" value={newCurrency.name_ar} onChange={(v) => setNewCurrency({ ...newCurrency, name_ar: v })} wide />
            <TextInput label="الاسم إنجليزي" value={newCurrency.name_en} onChange={(v) => setNewCurrency({ ...newCurrency, name_en: v })} wide />
            <TextInput label="الرمز" value={newCurrency.symbol} onChange={(v) => setNewCurrency({ ...newCurrency, symbol: v })} wide />
            <TextInput
              label="خانات عشرية"
              value={newCurrency.decimal_places}
              onChange={(v) => setNewCurrency({ ...newCurrency, decimal_places: v })}
              wide
            />
            <TextInput
              label="سعر الصرف (لوحدة أساسية)"
              value={newCurrency.exchange_rate_to_base}
              onChange={(v) => setNewCurrency({ ...newCurrency, exchange_rate_to_base: v })}
              placeholder="1"
              wide
            />
            <div className="sm:col-span-2 lg:col-span-3">
              <DecisionButton onClick={() => void createCurrency()} busy={busyId === -1} tone="primary">
                <Plus className="w-3.5 h-3.5" />
                إضافة العملة إلى السجل
              </DecisionButton>
            </div>
          </div>
          <p className="text-[11px] text-slate-500">
            السعر يقبله الخادم كرقم أكبر من صفر؛ تركه فارغًا يرد بخطأ تحقّق، لأنه لا يوجد سعر افتراضي مجهول المصدر.
            والرقم هو كم من هذه العملة يعادل وحدة واحدة من العملة الأساسية، ولا يُستخدم لتحويل إجماليات الصفقة:
            فالصفقة تُسجَّل وتُسعَّر بعملة معاملتها، ويظهر هذا الرقم عند العرض بالعملة الأساسية فقط.
          </p>
        </div>
      ) : null}

      <p className="text-[10px] text-slate-400 sm:hidden">اسحب الجدول أفقيًا؛ عمود الإجراء آخره. اضغط «تعديل» ليصير الحقل صندوقًا قابلًا للكتابة.</p>
      <div className="min-w-0 overflow-x-auto border border-slate-200 rounded-lg">
        <table className="w-full text-right text-xs">
          <thead className="bg-slate-50 text-[11px] font-black text-slate-700">
            <tr>
              <th className="px-3 py-2.5 min-w-0">العملة</th>
              <th className="px-3 py-2.5">الرمز</th>
              <th className="px-3 py-2.5">خانات</th>
              <th
                className="px-3 py-2.5"
                title="كم من هذه العملة يعادل وحدة واحدة من العملة الأساسية للمنصة. يكون هذا الرقم ١ للعملة الأساسية نفسها."
              >
                سعر الصرف (لوحدة أساسية)
              </th>
              <th className="px-3 py-2.5">أُضيف يدويًا</th>
              <th className="px-3 py-2.5">الحالة</th>
              <th className="px-3 py-2.5 w-full min-w-0 text-left">إجراء</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((currency) => (
              <tr key={currency.id} className={currency.is_default ? 'bg-emerald-50/40' : ''}>
                <td className="px-3 py-2.5 min-w-0">
                  <div className="font-bold text-slate-900">{currency.iso_code}</div>
                  <div className="text-[10px] text-slate-500">{currency.name_ar}</div>
                </td>
                <td className="px-3 py-2.5">
                  {editing === currency.id ? (
                    <input
                      value={form.symbol ?? ''}
                      onChange={(e) => setForm({ ...form, symbol: e.target.value })}
                      className="w-full min-w-[3.5rem] rounded border border-slate-200 bg-white px-2 py-1 text-xs"
                    />
                  ) : (
                    currency.symbol
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {editing === currency.id ? (
                    <input
                      value={form.decimal_places ?? ''}
                      onChange={(e) => setForm({ ...form, decimal_places: e.target.value })}
                      className="w-full min-w-[3rem] rounded border border-slate-200 bg-white px-2 py-1 text-xs"
                    />
                  ) : (
                    currency.decimal_places
                  )}
                </td>
                <td className="px-3 py-2.5">
                  {editing === currency.id ? (
                    <input
                      value={form.exchange_rate_to_base ?? ''}
                      onChange={(e) => setForm({ ...form, exchange_rate_to_base: e.target.value })}
                      className="w-full min-w-[6.5rem] rounded border border-slate-200 bg-white px-2 py-1 text-xs"
                    />
                  ) : (
                    <span className="tabular-nums">{currency.exchange_rate_to_base}</span>
                  )}
                </td>
                <td className="px-3 py-2.5 text-[10px] text-slate-500">
                  {currency.rate_updated_at ? formatDateTime(currency.rate_updated_at, getLocale()) || '—' : '—'}
                </td>
                <td className="px-3 py-2.5">
                  {editing === currency.id ? (
                    <select
                      value={form.is_active}
                      onChange={(e) => setForm({ ...form, is_active: e.target.value })}
                      className="w-full rounded border border-slate-200 bg-white px-2 py-1 text-xs"
                    >
                      <option value="1">نشطة</option>
                      <option value="0">موقوفة</option>
                    </select>
                  ) : currency.is_default ? (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-emerald-100 text-emerald-800 font-bold whitespace-nowrap">أساسية</span>
                  ) : currency.is_active ? (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-600 whitespace-nowrap">نشطة</span>
                  ) : (
                    <span className="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 whitespace-nowrap">موقوفة</span>
                  )}
                </td>
                <td className="px-3 py-2.5">
                  <div className="flex flex-wrap items-center gap-1.5">
                    {editing === currency.id ? (
                      <>
                        <DecisionButton onClick={() => void saveEdit(currency)} busy={busyId === currency.id} tone="primary">
                          حفظ التعديل
                        </DecisionButton>
                        <DecisionButton onClick={() => setEditing(null)}>إلغاء</DecisionButton>
                      </>
                    ) : (
                      <>
                        <DecisionButton onClick={() => startEdit(currency)}>تعديل</DecisionButton>
                        {!currency.is_default ? (
                          <DecisionButton onClick={() => void makeDefault(currency)} busy={busyId === currency.id}>
                           ضبط كأساسية
                          </DecisionButton>
                        ) : null}
                      </>
                    )}
                  </div>
                </td>
              </tr>
            ))}
            {!loading && !rows.length ? (
              <tr>
                <td colSpan={7} className="px-3 py-6 text-center text-slate-500">
                  لا عملات مسجّلة. لا تعرض الواجهة رمزًا ولا سعرًا حتى توجد قيمة مضبوطة في السجل.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      <ErrorNote text="تغيير العملة الأساسية يعيد تعريف ما تُقاس إليه كل الأسعار: راجِع أسعار العملات الأخرى بعد ضبطها وإلا ستُنسب صفقات قائمة إلى أسعار محسوبة على أساس قديم." />
    </PanelShell>
  );
};

/* ──────────────────────────────── audit trail ────────────────────────────── */

/**
 * The filter takes a value the server recorded. There is no vocabulary list in
 * this file on purpose: actions are written as "METHOD path" by the audit
 * middleware and as constants by the controllers that log a decision, so any
 * compiled-in set would be a guess that drifts away from what is stored. The
 * suggestions below come from the page currently loaded.
 */
const AuditPanel: React.FC<{ isPlatform: boolean }> = ({ isPlatform }) => {
  const [logs, setLogs] = useState<AuditLog[]>([]);
  const [page, setPage] = useState(1);
  const [meta, setMeta] = useState<{ total_items: number; total_pages: number } | null>(null);
  const [filters, setFilters] = useState({ action: '', auditable_type: '', organization_id: '' });
  const [loading, setLoading] = useState(true);
  const [banner, setBanner] = useState<Banner>(null);

  const load = useCallback(
    async (targetPage = page) => {
      setLoading(true);
      const result = await api.getAuditLogs({
        action: filters.action || undefined,
        auditable_type: filters.auditable_type || undefined,
        organization_id: isPlatform && filters.organization_id ? Number(filters.organization_id) : undefined,
        page: targetPage,
      });
      if (result.success) {
        setLogs((result.data ?? []) as AuditLog[]);
        setMeta(
          result.pagination
            ? { total_items: result.pagination.total_items, total_pages: result.pagination.total_pages }
            : null
        );
        setPage(targetPage);
      } else {
        setBanner({
          kind: 'error',
          text:
            result.error_code === 'NO_ORGANIZATION_CONTEXT'
              ? 'لا سياق منشأة لهذا الطلب: اختر منشأة أو ادخل بصفة إشرافية على المنصة.'
              : result.message || 'تعذّر قراءة سجل الأحداث',
        });
      }
      setLoading(false);
    },
    [filters, page, isPlatform]
  );

  useEffect(() => {
    void load(1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filters.action, filters.auditable_type, filters.organization_id]);

  const knownActions = useMemo(
    () => [...new Set(logs.map((log) => log.action))].sort(),
    [logs]
  );
  const knownTypes = useMemo(
    () => [...new Set(logs.map((log) => log.auditable_type).filter(Boolean) as string[])].sort(),
    [logs]
  );

  return (
    <PanelShell
      title={isPlatform ? 'سجل الأحداث — كل المنشآت' : 'سجل الأحداث — منشأتك'}
      hint="الفلاتر تُطبَّق في الاستعلام نفسه، والقيم المطابقة لا في الواجهة. السجل خارج نطاق الحساب لا يُقرأ من هذه الشاشة، ومن دون سياق منشأة يردّ الخادم 403."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <TextInput
          label="الحدث (كما سُجِّل)"
          value={filters.action}
          onChange={(value) => setFilters((f) => ({ ...f, action: value }))}
          placeholder="USER_ROLE_ASSIGNED أو POST api/v1/…"
          list="audit-actions"
        />
        <TextInput
          label="الكيان"
          value={filters.auditable_type}
          onChange={(value) => setFilters((f) => ({ ...f, auditable_type: value }))}
          placeholder="App\Models\User"
          list="audit-types"
        />
        {isPlatform ? (
          <TextInput
            label="معرّف المنشأة"
            value={filters.organization_id}
            onChange={(value) => setFilters((f) => ({ ...f, organization_id: value }))}
            placeholder="12"
          />
        ) : null}
        <datalist id="audit-actions">
          {knownActions.map((action) => (
            <option key={action} value={action} />
          ))}
        </datalist>
        <datalist id="audit-types">
          {knownTypes.map((type) => (
            <option key={type} value={type} />
          ))}
        </datalist>
        <div className="flex flex-col gap-1">
          <DecisionButton onClick={() => void load(page)} busy={loading}>
            <RefreshCw className="w-3.5 h-3.5" />
            تحديث السجل
          </DecisionButton>
          {/* Counted by the server for the filter applied, not by this page. */}
          <span className="text-center text-[11px] text-slate-500">
            {meta ? `${meta.total_items} حدث بالفلتر الحالي` : 'لم يردّ الخادم بعدد الأحداث'}
          </span>
        </div>
      </div>

      <BannerView banner={banner} />

      <p className="text-[10px] text-slate-400 sm:hidden">
        الاقتراحات تحت حقلَي الحدث والكيان تأتي من الصفحة المحمّلة حاليًا، لا من كل السجل.
      </p>
      <div className="min-w-0 overflow-x-auto border border-slate-200 rounded-lg">
        <table className="w-full text-right text-xs">
          <thead className="bg-slate-50 text-[11px] font-black text-slate-700">
            <tr>
              <th className="px-3 py-2.5">الوقت</th>
              <th className="px-3 py-2.5">الحساب</th>
              <th className="px-3 py-2.5">الحدث</th>
              <th className="px-3 py-2.5">الكيان</th>
              {isPlatform ? <th className="px-3 py-2.5">المنشأة</th> : null}
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {logs.map((log) => (
              <tr key={log.id}>
                <td className="px-3 py-2.5 text-[10px] text-slate-500 whitespace-nowrap">
                  {formatDateTime(log.created_at, getLocale()) || log.created_at}
                </td>
                <td className="px-3 py-2.5 min-w-0">{log.user?.name ?? 'غير مسجّل'}</td>
                <td className="px-3 py-2.5 min-w-0 font-bold text-slate-800 break-all">{log.action}</td>
                <td className="px-3 py-2.5 min-w-0 text-[10px] text-slate-600 break-all">
                  {log.auditable_type ? `${log.auditable_type.split('\\').pop()} #${log.auditable_id}` : '—'}
                </td>
                {isPlatform ? <td className="px-3 py-2.5 min-w-0 text-[10px]">{log.organization?.name_ar ?? 'المنصة'}</td> : null}
              </tr>
            ))}
            {!loading && !logs.length ? (
              <tr>
                <td colSpan={isPlatform ? 5 : 4} className="px-3 py-6 text-center text-slate-500">
                  لا أحداث بهذه المعايير.
                </td>
              </tr>
            ) : null}
          </tbody>
        </table>
      </div>

      {meta && meta.total_pages > 1 ? (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[11px] text-slate-600">
            صفحة {page} من {meta.total_pages}
          </span>
          <div className="flex flex-1 items-center justify-end gap-2">
            <DecisionButton onClick={() => void load(Math.max(1, page - 1))} busy={loading}>
              السابق
            </DecisionButton>
            <DecisionButton onClick={() => void load(Math.min(meta.total_pages, page + 1))} busy={loading}>
              التالي
            </DecisionButton>
          </div>
        </div>
      ) : null}
    </PanelShell>
  );
};

/* ───────────────────────────────── reports ───────────────────────────────── */

const ReportsPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [summary, setSummary] = useState<Record<string, any> | null>(null);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [loading, setLoading] = useState(true);
  const [exporting, setExporting] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);

  const load = useCallback(
    async (window: { from?: string; to?: string } = {}) => {
      setLoading(true);
      setBanner(null);
      const result = await api.getSalesSummary(window);
      if (result.success) setSummary((result.data ?? {}) as Record<string, any>);
      else {
        setSummary(null);
        setBanner({
          kind: 'error',
          text:
            result.error_code === 'NO_ORGANIZATION_CONTEXT'
              ? 'لا سياق منشأة لهذا التقرير: اختر منشأة أو ادخل بصفة إشرافية على المنصة.'
              : fieldError(result, 'from') || fieldError(result, 'to') || result.message || 'تعذّر توليد التقرير',
        });
      }
      setLoading(false);
    },
    []
  );

  useEffect(() => {
    void load();
  }, [load]);

  const download = async () => {
    setExporting(true);
    setBanner(null);
    const result = await api.downloadDealsCsv({ from: from || undefined, to: to || undefined });
    if (!result.ok || !result.blob || !result.filename) {
      setBanner({ kind: 'error', text: result.message ?? 'تعذّر تصدير الصفقات' });
      setExporting(false);
      return;
    }
    const url = URL.createObjectURL(result.blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = result.filename;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    URL.revokeObjectURL(url);
    setExporting(false);
    onNotify?.(`نُزِّل ملف ${result.filename}`);
  };

  const currency = summary?.currency ?? null;

  return (
    <PanelShell
      title="تقرير الصفقات"
      hint="الإيرادات تُعدّ من الصفقات المنتهية أو المصفّاة فقط؛ الصفقة المتفق عليها قبل التنفيذ ليست دورانًا. الفترة تُطبَّق على تاريخ إنشاء الصفقة، والتقرير يقول ذلك صراحةً."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label className="min-w-0 space-y-1 block">
          <span className="block text-[11px] font-bold text-slate-600">من تاريخ</span>
          <input
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs"
          />
        </label>
        <label className="min-w-0 space-y-1 block">
          <span className="block text-[11px] font-bold text-slate-600">إلى تاريخ</span>
          <input
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            className="w-full rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs"
          />
        </label>
        <div className="flex flex-col gap-2">
          <DecisionButton onClick={() => void load({ from: from || undefined, to: to || undefined })} busy={loading} tone="primary">
            توليد التقرير لهذه الفترة
          </DecisionButton>
          <DecisionButton
            onClick={() => {
              setFrom('');
              setTo('');
              void load();
            }}
          >
            كل الفترات
          </DecisionButton>
        </div>
        <div className="flex flex-col gap-1">
          <DecisionButton onClick={() => void download()} busy={exporting}>
            <Download className="w-3.5 h-3.5" />
            تصدير الصفقات CSV
          </DecisionButton>
          <span className="text-center text-[10px] leading-relaxed text-slate-400">
            التصدير يستخدم التاريخين أعلاه كما هما، وقد يكون الملف كل السجل إن تُركا فارغين.
          </span>
        </div>
      </div>

      <BannerView banner={banner} />

      {summary ? (
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <Fact label="كل الصفقات بالنطاق" value={String(summary.total_deals_count)} />
          <Fact label="المغلقة باستلام مُؤكد" value={String(summary.completed_deals_count)} />
          <Fact label="حجم البضائع المتداولة" value={formatMoney(summary.gross_merchandise_value, currency ?? '')} />
          <Fact label="عمولة المنصة المستحقّة" value={formatMoney(summary.platform_commission_accrued, currency ?? '')} />
          <div className="col-span-full text-[11px] leading-relaxed text-slate-500">
            الأرقام محسوبة من حالات: <code>{summary.counted_statuses.join('، ') || 'لا حالة'}</code> — والعمولة مستحقّة لا
            محصّلة، لأن سدادها يتم بين المنصة والبائع خارج هذا النظام.
          </div>
          <div className="col-span-full text-[11px] leading-relaxed text-slate-500">
            العملة: {currency ?? 'لم يضبط المشغّل عملة أساسية، لذلك تُعرض القيم بلا رمز'} · الفترة:{' '}
            {summary.period_from ?? 'بداية السجل'} ← {summary.period_to ?? 'اليوم'} على حقل{' '}
            <code>{summary.period_field}</code> · أُنشئ {formatDateTime(summary.generated_at, getLocale()) || summary.generated_at}
          </div>
        </div>
      ) : null}

      {!loading && !summary ? (
        <p className="text-xs text-slate-500">لا أرقام تُعرض حتى يردّ الخادم بنطاق يستطيع قراءته الحساب الحالي.</p>
      ) : null}

      <ErrorNote text="هذه الأرقام ما سجلته المنصة نفسها: لا تصل إلى أنظمة الدولة ولا تعني أن صفقة تحققت خارج المنصة." />
    </PanelShell>
  );
};

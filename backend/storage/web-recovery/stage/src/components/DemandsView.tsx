import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  PlusCircle,
  MapPin,
  Calendar,
  Building2,
  Package,
  DollarSign,
  ChevronDown,
  ChevronUp,
  Target,
  FileText,
} from 'lucide-react';
import { api, fieldError } from '../lib/api';
import { activeCurrencies, baseCurrency, configuredCity, formatMoney, isFeatureEnabled, usePlatformConfig } from '../lib/config';
import { formatDate, getLocale } from '../lib/i18n';
import {
  BannerView,
  DecisionButton,
  ErrorNote,
  Fact,
  PanelShell,
  TextInput,
  recorded,
  type Banner,
} from './panel';
import type { Category, Demand, DemandMatch, DemandStatus, Quotation } from '../types';

/**
 * The buyer's demand board and the seller's quotation desk — one screen because
 * they are one conversation, and the row that answers "whose is this?" decides
 * which half of it you may act on.
 *
 * What this screen deliberately does not do:
 * - It never books stock. A match becomes a *purchase request* the seller
 *   reviews; holding quantity is the seller's decision, taken on the requests
 *   screen, and is the moment `reserved_quantity` moves.
 * - It never offers to "accept" a quotation. The server stores quotations and
 *   reads them back on the buyer's own demand, but no route accepts, rejects or
 *   converts one, so a button doing that would be a switch wired to nothing.
 *   The path from a demand to a deal runs through a match, not a quotation.
 * - It never guesses a city or a unit. Both are the buyer's requirement, and the
 *   server refuses an ask without them.
 */

/** The five states `App\Models\Demand` can hold — nothing else is displayed. */
const DEMAND_STATUS_LABELS: Record<DemandStatus, string> = {
  OPEN: 'مفتوح لاستقبال العروض',
  QUOTED: 'وردت عليه عروض سعرية',
  ACCEPTED: 'قُبل عرض وتم تحويله',
  CLOSED: 'مغلق',
  EXPIRED: 'منتهي الصلاحية',
};

const DEMAND_STATUS_STYLES: Record<DemandStatus, string> = {
  OPEN: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  QUOTED: 'bg-sky-50 text-sky-800 border-sky-200',
  ACCEPTED: 'bg-indigo-50 text-indigo-800 border-indigo-200',
  CLOSED: 'bg-slate-100 text-slate-700 border-slate-200',
  EXPIRED: 'bg-amber-50 text-amber-800 border-amber-200',
};

const MATCH_STATUS_LABELS: Record<string, string> = {
  ACTIVE: 'مطابقة سارية',
  USED: 'استُخدمت في طلب شراء',
  EXPIRED: 'انتهت مدة المطابقة',
  INVALIDATED: 'سقطت لأن العرض لم يعد في السوق',
};

const QUOTATION_STATUS_LABELS: Record<string, string> = {
  SUBMITTED: 'مقدَّم ولم يُتخذ فيه إجراء',
  ACCEPTED: 'مقبول',
  REJECTED: 'مرفوض',
  EXPIRED: 'منتهي',
};

interface DemandsViewProps {
  currentPersonaId: string;
  organizationId: number | null;
  can: (permission: string) => boolean;
}

const num = (value: string | number | null | undefined): number =>
  value === null || value === undefined ? 0 : Number(value);

/** A refusal, in the server's own words, with the codes this screen can hit named out. */
function refusalOf(result: { error?: { code?: string; message?: string } | null; error_code?: string; message?: string }): string {
  const code = result.error?.code ?? result.error_code ?? '';
  const message = result.error?.message ?? result.message ?? 'رفض الخادم الطلب';
  if (code === 'TENANT_REQUIRED') return 'هذه الإجراء يحتاج منشأة عاملة؛ اخترها من أعلى الشاشة.';
  if (code === 'FEATURE_DISABLED') return 'المنصة أوقفت هذه القدرة، فلا يُنفَّذ الإجراء الآن.';
  if (code === 'DEMAND_ACCESS_DENIED' || code === 'MATCH_ACCESS_DENIED') return 'هذا الطلب لمطابقة منشأة أخرى.';
  if (code === 'SELF_QUOTATION_DENIED') return 'لا تُقدَّم عروض سعر على طلبات منشأتك.';
  if (code === 'CURRENCY_NOT_ACTIVE') return 'لم تُعتمد عملة لهذا الرقم بعد؛ يضبطها مسؤول المنصة.';
  if (code === 'MATCH_NOT_ACTIONABLE' || code === 'MATCH_EXPIRED' || code === 'OFFER_NOT_AVAILABLE') {
    return `لم تعد هذه المطابقة فرصة مفتوحة: ${message}`;
  }
  if (code === 'QUANTITY_EXCEEEDS_AVAILABLE') return 'الكمية المطلوبة أكبر من المتاح الآن؛ حدّث المطابقة.';
  if (code === 'QUANTITY_BELOW_MIN_ORDER') return `الكمية أقل من الحد الأدنى للطلب لدى هذا البائع: ${message}`;
  return code ? `${code}: ${message}` : message;
}

export const DemandsView: React.FC<DemandsViewProps> = ({ currentPersonaId, organizationId, can }) => {
  const config = usePlatformConfig();
  const locale = getLocale();

  const [demands, setDemands] = useState<Demand[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [readError, setReadError] = useState<string | null>(null);
  const [scope, setScope] = useState<'open' | 'mine'>('open');
  const [search, setSearch] = useState('');
  const [showModal, setShowModal] = useState(false);

  const rfqEnabled = isFeatureEnabled('demand_rfq');
  const matchingEnabled = isFeatureEnabled('matching_engine');
  const flagsRead = Object.keys(config.featureFlags ?? {}).length > 0;

  const loadData = useCallback(async () => {
    setLoading(true);
    setReadError(null);
    const result = await api.getDemands({
      ...(scope === 'mine' ? { my_demands: 1 as const } : {}),
      ...(search.trim() ? { search: search.trim() } : {}),
    });
    if (result.success) setDemands(result.data ?? []);
    else setReadError(refusalOf(result));
    setLoading(false);
  }, [scope, search]);

  useEffect(() => {
    void loadData();
  }, [loadData, currentPersonaId]);

  useEffect(() => {
    void api.getCategories().then((result) => {
      if (result.success) setCategories(result.data ?? []);
    });
  }, []);

  const categoryNames = useMemo(() => {
    const map = new Map<number, string>();
    for (const category of categories) map.set(category.id, locale === 'en' ? category.name_en || category.name_ar : category.name_ar);
    return map;
  }, [categories, locale]);

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-4">
        <div>
          <h2 className="text-lg font-black text-slate-900">
            {locale === 'en' ? 'Demand board and quotation desk' : 'لوحة طلبات الاحتياج وطاولة عروض الأسعار'}
          </h2>
          <p className="text-xs text-slate-500 pt-0.5 leading-relaxed">
            المطابقة تُرشح عروضاً، وطلب الشراء يُرفع عن مطابقة يقرّها المشتري، والبائع هو من يقرر الحجز بعد ذلك.
            عرض السعر يُحفظ على الطلب ولا يُنشئ حجزاً ولا صفقة.
          </p>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="flex items-center gap-1 bg-slate-100 p-1 rounded-xl text-xs font-semibold">
            <button
              onClick={() => setScope('open')}
              className={`px-3 py-1.5 rounded-lg cursor-pointer ${scope === 'open' ? 'bg-white text-emerald-900 shadow-2xs font-bold' : 'text-slate-600'}`}
            >
              الطلبات المفتوحة
            </button>
            <button
              onClick={() => setScope('mine')}
              disabled={!organizationId}
              className={`px-3 py-1.5 rounded-lg cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed ${scope === 'mine' ? 'bg-white text-emerald-900 shadow-2xs font-bold' : 'text-slate-600'}`}
            >
              طلبات منشأتي
            </button>
          </div>

          <form
            onSubmit={(event) => { event.preventDefault(); void loadData(); }}
            className="flex items-center gap-1.5"
          >
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="بحث بالعنوان"
              className="w-40 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:bg-white focus:outline-none"
            />
            <button type="submit" className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-bold text-slate-700 cursor-pointer">
              بحث
            </button>
          </form>

          {can('demands.create') ? (
            <button
              onClick={() => setShowModal(true)}
              className="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 cursor-pointer"
            >
              <PlusCircle className="w-4 h-4" />
              <span>نشر طلب احتياج</span>
            </button>
          ) : null}
        </div>
      </div>

      {!flagsRead ? (
        <ErrorNote text="لم تُقرأ قدرات المنصة بعد؛ قد تظهر القوائم أدناه فارغة حتى تصل إجابة مسار الإقلاع." />
      ) : !rfqEnabled ? (
        <ErrorNote text="قدرة «الطلبات وعروض الأسعار» (demand_rfq) معطّلة في هذه التهيئة: الخادم يرفض كل مسارات /demands، فلا نشر ولا عرض سعر ولا مطابقة." />
      ) : null}

      {readError ? <ErrorNote text={readError} /> : null}

      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs">جارٍ قراءة الطلبات…</div>
      ) : demands.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500 text-xs">
          {scope === 'mine'
            ? 'لم تنشر منشأتك طلب احتياج بعد، أو لا طلبات لها في هذه الحالة.'
            : 'لا توجد طلبات احتياج مفتوحة غير منتهية.'}
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {demands.map((demand) => (
            <DemandCard
              key={demand.id}
              demand={demand}
              isMine={organizationId !== null && Number(demand.buyer_organization_id) === Number(organizationId)}
              categoryName={categoryNames.get(Number(demand.category_id)) ?? null}
              canQuote={can('demands.quote')}
              canRequest={can('requests.create')}
              matchingEnabled={matchingEnabled && rfqEnabled}
              locale={locale}
              onChanged={() => void loadData()}
            />
          ))}
        </div>
      )}

      {showModal ? (
        <NewDemandModal
          categories={categories}
          onClose={() => setShowModal(false)}
          onCreated={() => { setShowModal(false); setScope('mine'); void loadData(); }}
        />
      ) : null}
    </div>
  );
};

/* ───────────────────────────── one demand ────────────────────────────────── */

interface DemandCardProps {
  demand: Demand;
  isMine: boolean;
  categoryName: string | null;
  canQuote: boolean;
  canRequest: boolean;
  matchingEnabled: boolean;
  locale: 'ar' | 'en';
  onChanged: () => void;
}

const DemandCard: React.FC<DemandCardProps> = ({
  demand,
  isMine,
  categoryName,
  canQuote,
  canRequest,
  matchingEnabled,
  locale,
  onChanged,
}) => {
  const [open, setOpen] = useState<'none' | 'quote' | 'matches'>('none');
  const [matches, setMatches] = useState<DemandMatch[] | null>(null);
  const [matchesBusy, setMatchesBusy] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);

  const expired = Boolean(demand.expires_at) && new Date(String(demand.expires_at)).getTime() < Date.now();

  const loadMatches = async () => {
    setMatchesBusy(true);
    setBanner(null);
    const result = await api.getDemandMatches(demand.id);
    setMatchesBusy(false);
    if (result.success) {
      setMatches(result.data?.matches ?? []);
    } else {
      setMatches(null);
      setBanner({ kind: 'error', text: refusalOf(result) });
    }
  };

  const toggleMatches = () => {
    if (open === 'matches') { setOpen('none'); return; }
    setOpen('matches');
    void loadMatches();
  };

  return (
    <div className="bg-white border border-slate-200 rounded-xl p-5 space-y-3.5 shadow-2xs flex flex-col justify-between">
      <div className="space-y-2">
        <div className="flex items-start justify-between gap-2">
          <div className="space-y-1">
            <span className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">
              {categoryName ?? 'تصنيف غير محدَّد'}
            </span>
            <h3 className="text-sm font-bold text-slate-900 leading-snug">
              {locale === 'en' ? demand.title_en || demand.title_ar : demand.title_ar}
            </h3>
            <p className="text-[10px] text-slate-400 font-mono">
              {demand.demand_number}{isMine ? ' — طلب منشأتك' : ''}
            </p>
          </div>

          <span className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full border ${DEMAND_STATUS_STYLES[demand.status] ?? 'bg-slate-50 text-slate-700 border-slate-200'}`}>
            {DEMAND_STATUS_LABELS[demand.status] ?? demand.status}
          </span>
        </div>

        {demand.description ? (
          <p className="text-xs text-slate-600 leading-relaxed bg-slate-50 p-3 rounded-lg border border-slate-100">
            {demand.description}
          </p>
        ) : (
          <p className="text-xs text-slate-400">لم يُرفق وصف بالطلب.</p>
        )}
      </div>

      <div className="grid grid-cols-2 gap-2 text-xs text-slate-600 pt-1 border-t border-slate-100">
        <div className="flex items-center gap-1.5">
          <Package className="w-3.5 h-3.5 text-emerald-600" />
          <span>الكمية: <strong>{num(demand.target_quantity)}</strong> {demand.unit}</span>
        </div>

        {demand.target_unit_price !== null && demand.target_unit_price !== undefined ? (
          <div className="flex items-center gap-1.5">
            <DollarSign className="w-3.5 h-3.5 text-emerald-600" />
            <span>السعر المستهدف: <strong>{formatMoney(demand.target_unit_price, demand.currency_code)}</strong></span>
          </div>
        ) : (
          <div className="flex items-center gap-1.5 text-slate-400">
            <DollarSign className="w-3.5 h-3.5" />
            <span>بلا سعر مستهدف</span>
          </div>
        )}

        <div className="flex items-center gap-1.5">
          <MapPin className="w-3.5 h-3.5 text-slate-400" />
          <span>مدينة التسليم: {demand.delivery_city}</span>
        </div>

        <div className="flex items-center gap-1.5">
          <Calendar className="w-3.5 h-3.5 text-amber-500" />
          <span>
            التوريد: {demand.target_delivery_date ? formatDate(demand.target_delivery_date, locale) : 'لم يُحدَّد'}
          </span>
        </div>

        <div className="flex items-center gap-1.5">
          <Target className="w-3.5 h-3.5 text-slate-400" />
          <span>
            آخر لقبول العروض: {demand.expires_at ? formatDate(demand.expires_at, locale) : 'بلا موعد'}
            {expired ? ' — انتهى' : ''}
          </span>
        </div>

        {demand.special_requirements ? (
          <div className="flex items-center gap-1.5">
            <FileText className="w-3.5 h-3.5 text-slate-400" />
            <span className="truncate" title={demand.special_requirements}>شروط خاصة</span>
          </div>
        ) : null}
      </div>

      <div className="pt-1.5 flex items-center justify-between gap-2 text-[11px] bg-slate-50 p-2 rounded-lg">
        <div className="flex items-center gap-1.5 text-slate-700 min-w-0">
          <Building2 className="w-3.5 h-3.5 text-slate-500 shrink-0" />
          <span className="font-semibold truncate">
            {isMine
              ? 'منشأتك'
              : (locale === 'en'
                ? demand.buyer_organization?.name_en || demand.buyer_organization?.name_ar || 'منشأة مسجَّلة'
                : demand.buyer_organization?.name_ar || demand.buyer_organization?.name_en || 'منشأة مسجَّلة')}
          </span>
        </div>
        {/* The rating column defaults to a number nobody earned and is null for
            every business since the correction; only a real score is printed. */}
        <span className="text-slate-500 shrink-0">
          {recorded(demand.buyer_organization?.rating === null || demand.buyer_organization?.rating === undefined
            ? null
            : String(demand.buyer_organization.rating))}
        </span>
      </div>

      <BannerView banner={banner} />

      <div className="flex flex-wrap items-center gap-2">
        {!isMine && demand.status === 'OPEN' && canQuote ? (
          <DecisionButton onClick={() => setOpen(open === 'quote' ? 'none' : 'quote')}>
            {open === 'quote' ? 'إغلاق عرض السعر' : 'تقديم عرض سعر'}
          </DecisionButton>
        ) : null}

        {isMine ? (
          <DecisionButton onClick={toggleMatches} busy={matchesBusy}>
            {open === 'matches' ? 'إغلاق المطابقات' : 'مطابقات هذا الطلب'}
          </DecisionButton>
        ) : null}

        {isMine && demand.quotations && demand.quotations.length > 0 ? (
          <span className="text-[11px] text-slate-500">
            وردت {demand.quotations.length} عروض سعر — تُعرض أدناه كما حفظها الخادم.
          </span>
        ) : null}
      </div>

      {open === 'quote' ? (
        <QuotationForm demand={demand} onDone={() => { setOpen('none'); onChanged(); }} />
      ) : null}

      {open === 'matches' && isMine ? (
        <div className="space-y-2 border-t border-slate-100 pt-3">
          {!matchingEnabled ? (
            <ErrorNote text="مسار المطابقات يحتاج قدرتي demand_rfq وmatching_engine معاً؛ إحداهما معطّلة في هذه التهيئة." />
          ) : matchesBusy ? (
            <p className="text-xs text-slate-500">يعيد المحرك الحساب على المخزون الحالي…</p>
          ) : !matches || matches.length === 0 ? (
            <p className="text-xs text-slate-500">
              لا مطابقة قائمة الآن: لا عرض نشط يطابق التصنيف والكمية والحد الأدنى، أو استُخدمت مطابقات هذا الطلب سابقاً.
            </p>
          ) : (
            matches.map((match) => (
              <MatchRow
                key={match.id}
                demand={demand}
                match={match}
                canRequest={canRequest}
                locale={locale}
                onRequested={onChanged}
              />
            ))
          )}
        </div>
      ) : null}

      {isMine && demand.quotations && demand.quotations.length > 0 ? (
        <div className="space-y-2 border-t border-slate-100 pt-3">
          {demand.quotations.map((quotation) => (
            <QuotationRow key={quotation.id} quotation={quotation} unit={demand.unit} />
          ))}
          <p className="text-[10px] text-slate-500 leading-relaxed">
            لا مسار في الخادم يقبل عرض السعر أو يحوّله: القبول الذي يُنشئ حجة على البائع يظل طلب الشراء الناتج عن مطابقة.
          </p>
        </div>
      ) : null}
    </div>
  );
};

/* ─────────────────────────── quotation submit ────────────────────────────── */

const QuotationForm: React.FC<{ demand: Demand; onDone: () => void }> = ({ demand, onDone }) => {
  const config = usePlatformConfig();
  const currencies = activeCurrencies();
  const [quantity, setQuantity] = useState(String(num(demand.target_quantity)));
  const [price, setPrice] = useState(demand.target_unit_price !== null && demand.target_unit_price !== undefined ? String(num(demand.target_unit_price)) : '');
  const [currency, setCurrency] = useState<string>(demand.currency_code || baseCurrency() || currencies[0]?.iso_code || '');
  const [validityDays, setValidityDays] = useState('7');
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  const submit = async () => {
    setBusy(true);
    setBanner(null);
    setFieldErrors({});

    const result = await api.submitQuotation(demand.id, {
      quoted_quantity: Number(quantity),
      unit_price: Number(price),
      currency_code: currency || undefined,
      validity_days: validityDays ? Number(validityDays) : undefined,
      notes: notes.trim() || undefined,
    });

    setBusy(false);

    if (!result.success) {
      setFieldErrors({
        quoted_quantity: fieldError(result, 'quoted_quantity'),
        unit_price: fieldError(result, 'unit_price'),
        validity_days: fieldError(result, 'validity_days'),
        notes: fieldError(result, 'notes'),
      });
      setBanner({ kind: 'error', text: refusalOf(result) });
      return;
    }

    setBanner({
      kind: 'ok',
      text: `${result.message ?? 'تم حفظ عرض السعر'} — رقمه ${result.data?.quotation_number ?? ''} وهو مُسجَّل لدى الطلب، ولا يُنشئ حجزاً ولا صفقة.`,
    });
  };

  return (
    <div className="border border-slate-200 rounded-lg p-3 space-y-2.5 bg-slate-50/60">
      <p className="text-[11px] text-slate-600 leading-relaxed">
        عرضك يُحفظ على هذا الطلب ويظهر لمنشأة المشتري ضمن طلبها. الكمية هنا ما تستطيع توريده أنت، بوحدة
        المشتري نفسها ({demand.unit}).
      </p>

      <BannerView banner={banner} />

      <div className="flex flex-wrap gap-3">
        <TextInput
          label={`الكمية التي تورّدها (${demand.unit})`}
          value={quantity}
          onChange={setQuantity}
          type="number"
          error={fieldErrors.quoted_quantity}
        />
        <TextInput label="سعر الوحدة" value={price} onChange={setPrice} type="number" error={fieldErrors.unit_price} />
        <TextInput label="أيام سريان العرض" value={validityDays} onChange={setValidityDays} type="number" error={fieldErrors.validity_days} />
      </div>

      <div className="flex flex-wrap gap-3">
        <label className="space-y-1 block">
          <span className="text-[11px] font-bold text-slate-700">العملة</span>
          <select
            value={currency}
            onChange={(event) => setCurrency(event.target.value)}
            className="bg-white border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:outline-none"
          >
            {currencies.length === 0 ? <option value="">{config.baseCurrency ?? 'لا عملة معتمدة'}</option> : null}
            {currencies.map((row) => (
              <option key={row.iso_code} value={row.iso_code}>{row.iso_code}</option>
            ))}
          </select>
        </label>
      </div>

      <TextInput label="ملاحظات للتوريد (اختياري)" value={notes} onChange={setNotes} wide error={fieldErrors.notes} />

      <div className="flex items-center gap-2">
        <DecisionButton tone="primary" busy={busy} onClick={() => void submit()}>حفظ عرض السعر</DecisionButton>
        <DecisionButton onClick={onDone}>إغلاق</DecisionButton>
        {Number(quantity) > 0 && Number(price) > 0 ? (
          <span className="text-[11px] text-slate-600">الإجمالي المحتسب من رقميك: {formatMoney(Number(quantity) * Number(price), currency)}</span>
        ) : null}
      </div>
      <p className="text-[10px] text-slate-400">
        الإجمالي يحسبه الخادم من الكميتين ويثبّته في السجل؛ ما يظهر هنا معاينة فقط.
      </p>
    </div>
  );
};

const QuotationRow: React.FC<{ quotation: Quotation; unit: string }> = ({ quotation, unit }) => (
  <div className="border border-slate-200 rounded-lg p-3 space-y-2 bg-white">
    <div className="flex items-center justify-between gap-2">
      <span className="text-[10px] font-mono text-slate-500">{quotation.quotation_number}</span>
      <span className="text-[10px] font-bold text-slate-700">
        {QUOTATION_STATUS_LABELS[quotation.status] ?? quotation.status}
      </span>
    </div>
    <div className="grid grid-cols-2 gap-2">
      <Fact label="المورِد" value={quotation.seller_organization?.name_ar || quotation.seller_organization?.name_en || recorded(null)} />
      <Fact label="الكمية المعروضة" value={`${num(quotation.quoted_quantity)} ${unit}`} />
      <Fact label="سعر الوحدة" value={formatMoney(quotation.unit_price, quotation.currency_code)} />
      <Fact label="الإجمالي المسجَّل" value={formatMoney(quotation.total_amount, quotation.currency_code)} />
      <Fact label="سريان العرض" value={quotation.validity_days ? `${quotation.validity_days} يوماً` : recorded(null)} />
    </div>
    {quotation.notes ? <p className="text-[11px] text-slate-600">{quotation.notes}</p> : null}
  </div>
);

/* ─────────────────────── match → purchase request ────────────────────────── */

const MatchRow: React.FC<{
  demand: Demand;
  match: DemandMatch;
  canRequest: boolean;
  locale: 'ar' | 'en';
  onRequested: () => void;
}> = ({ demand, match, canRequest, locale, onRequested }) => {
  const [expanded, setExpanded] = useState(false);
  const [quantity, setQuantity] = useState(String(num(match.requested_quantity)));
  const [notes, setNotes] = useState('');
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);

  const offer = match.offer;
  const actionable = match.status === 'ACTIVE'
    && (!match.expires_at || new Date(String(match.expires_at)).getTime() > Date.now());

  const submit = async () => {
    setBusy(true);
    setBanner(null);
    const result = await api.createPurchaseRequestFromMatch(match.id, {
      requested_quantity: Number(quantity),
      notes: notes.trim() || undefined,
    });
    setBusy(false);

    if (!result.success) {
      setBanner({ kind: 'error', text: refusalOf(result) });
      return;
    }

    setBanner({
      kind: 'ok',
      text: `${result.message ?? 'تم تسجيل طلب الشراء'} — رقمه ${result.data?.purchase_request_number ?? ''}. لم يُحجز شيء بعد: البائع يقرر الحجز من شاشة طلبات الشراء الواردة إليه.`,
    });
    onRequested();
  };

  return (
    <div className="border border-slate-200 rounded-lg p-3 space-y-2 bg-white">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <p className="text-xs font-bold text-slate-900 truncate">
            {locale === 'en' ? offer?.title_en || offer?.title_ar || `عرض رقم ${match.offer_id}` : offer?.title_ar || offer?.title_en || `عرض رقم ${match.offer_id}`}
          </p>
          <p className="text-[10px] text-slate-500">
            {[
              `متاح ${offer ? num(offer.available_quantity) : '—'} ${offer?.unit ?? demand.unit}`,
              offer ? `أدنى طلب ${num(offer.min_order_quantity)}` : null,
              // The match endpoint serializes the offer row itself, which names
              // no seller business; the id here is the platform's internal
              // reference, not a claim about who the seller is.
              `بائع برقم مرجعي ${match.seller_organization_id}`,
            ].filter(Boolean).join(' · ')}
          </p>
        </div>
        <span className="text-[10px] font-bold text-slate-700 shrink-0">
          {MATCH_STATUS_LABELS[match.status] ?? match.status}
        </span>
      </div>

      <div className="grid grid-cols-2 gap-2">
        <Fact label="قيمة المطابقة" value={match.match_score === null || match.match_score === undefined ? recorded(null) : `${num(match.match_score)}%`} />
        <Fact label="إجمالي تقديري" value={match.estimated_total === null || match.estimated_total === undefined ? recorded(null) : formatMoney(match.estimated_total, demand.currency_code)} />
        <Fact label="انتهت المطابقة" value={match.expires_at ? formatDate(match.expires_at, locale) : recorded(null)} />
      </div>

      {match.match_reasons && match.match_reasons.length > 0 ? (
        <p className="text-[10px] text-slate-500 leading-relaxed">
          أسباب الترشيح: {match.match_reasons.join('، ')}
        </p>
      ) : null}

      {banner ? <BannerView banner={banner} /> : null}

      {canRequest && actionable ? (
        expanded ? (
          <div className="space-y-2 border-t border-slate-100 pt-2">
            <div className="flex flex-wrap gap-3">
              <TextInput
                label={`الكمية المطلوبة (${offer?.unit ?? demand.unit})`}
                value={quantity}
                onChange={setQuantity}
                type="number"
              />
              <TextInput label="ملاحظة للبائع (اختياري)" value={notes} onChange={setNotes} wide />
            </div>
            <div className="flex items-center gap-2">
              <DecisionButton tone="primary" busy={busy} onClick={() => void submit()}>إرسال طلب الشراء</DecisionButton>
              <DecisionButton onClick={() => setExpanded(false)}>إلغاء</DecisionButton>
            </div>
            <p className="text-[10px] text-slate-400">
              الحد الأدنى والكمية المتاحة يتحقق منهما الخادم؛ إن خالفهما رفض الطلب وظهر رفضه هنا.
            </p>
          </div>
        ) : (
          <button
            onClick={() => setExpanded(true)}
            className="text-[11px] font-bold text-emerald-800 flex items-center gap-1 cursor-pointer"
          >
            <ChevronDown className="w-3.5 h-3.5" />
            تحويل هذه المطابقة إلى طلب شراء
          </button>
        )
      ) : (
        <p className="text-[10px] text-slate-400 flex items-center gap-1">
          <ChevronUp className="w-3.5 h-3.5" />
          {canRequest
            ? 'هذه المطابقة لم تعد فرصة مفتوحة.'
            : 'صلاحية إنشاء طلب شراء غير ممنوحة لهذا الحساب داخل منشأته.'}
        </p>
      )}
    </div>
  );
};

/* ─────────────────────────── publishing a demand ─────────────────────────── */

const NewDemandModal: React.FC<{
  categories: Category[];
  onClose: () => void;
  onCreated: () => void;
}> = ({ categories, onClose, onCreated }) => {
  const config = usePlatformConfig();
  const locale = getLocale();
  const [title, setTitle] = useState('');
  const [titleEn, setTitleEn] = useState('');
  const [description, setDescription] = useState('');
  const [quantity, setQuantity] = useState('100');
  const [unit, setUnit] = useState('');
  const [targetPrice, setTargetPrice] = useState('');
  const [city, setCity] = useState('');
  const [requiredDate, setRequiredDate] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  // The operator's configured city only pre-fills the ask; the buyer's
  // requirement is what gets stored.
  useEffect(() => {
    if (!city) {
      const fallback = configuredCity();
      if (fallback) setCity(fallback);
    }
  }, [config.defaultCity, city]);

  const today = new Date();
  const tomorrow = new Date(today.getTime() + 86400000).toISOString().slice(0, 10);

  const create = async () => {
    setBusy(true);
    setBanner(null);
    setFieldErrors({});

    const result = await api.createDemand({
      title_ar: title.trim(),
      title_en: titleEn.trim() || undefined,
      description: description.trim() || undefined,
      target_quantity: Number(quantity),
      unit: unit.trim(),
      target_unit_price: targetPrice ? Number(targetPrice) : undefined,
      delivery_city: city.trim(),
      target_delivery_date: requiredDate || undefined,
      category_id: categoryId ? Number(categoryId) : undefined,
    });

    setBusy(false);

    if (!result.success) {
      const keys = ['title_ar', 'title_en', 'description', 'target_quantity', 'unit',
        'target_unit_price', 'delivery_city', 'target_delivery_date', 'category_id'];
      const mapped: Record<string, string> = {};
      for (const key of keys) {
        const message = fieldError(result, key);
        if (message) mapped[key] = message;
      }
      setFieldErrors(mapped);
      setBanner({ kind: 'error', text: refusalOf(result) });
      return;
    }

    onCreated();
  };

  const field = (key: string) => fieldErrors[key];

  return (
    <div className="fixed inset-0 z-50 bg-slate-900/60 flex items-center justify-center p-4" role="dialog" aria-modal="true">
      <div className="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl border border-slate-200 max-h-[90vh] overflow-y-auto" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
        <div className="flex items-center justify-between border-b border-slate-100 pb-3">
          <h3 className="text-base font-bold text-slate-900">نشر طلب احتياج تجاري</h3>
          <button type="button" onClick={onClose} className="text-slate-400 hover:text-slate-700 font-bold cursor-pointer">✕</button>
        </div>

        <BannerView banner={banner} />

        <div className="space-y-3 text-xs">
          <TextInput label="عنوان الطلب" value={title} onChange={setTitle} wide placeholder="مثال: مطلوب توريد 500 كرتون أرز" error={field('title_ar')} />
          <TextInput label="العنوان بالإنجليزية (اختياري)" value={titleEn} onChange={setTitleEn} wide error={field('title_en')} />

          <div className="flex flex-wrap gap-3">
            <TextInput label="الكمية المطلوبة" value={quantity} onChange={setQuantity} type="number" error={field('target_quantity')} />
            <TextInput label="وحدة القياس" value={unit} onChange={setUnit} placeholder="كرتونة / باليت / كيس" error={field('unit')} />
            <TextInput
              label="السعر المستهدف للوحدة (اختياري)"
              value={targetPrice}
              onChange={setTargetPrice}
              type="number"
              error={field('target_unit_price')}
            />
          </div>
          <p className="text-[10px] text-slate-400">
            {config.baseCurrency
              ? `يُثبّت الخادم العملة الأساسية للمنصة (${config.baseCurrency}) على هذا الرقم إن لم تُرسل عملة أخرى.`
              : 'لم تُضبط عملة أساسية للمنصة؛ يضبطها مسؤول المنصة قبل نشر طلب بسعر مستهدف.'}
          </p>

          <div className="flex flex-wrap gap-3">
            <TextInput label="مدينة التسليم" value={city} onChange={setCity} error={field('delivery_city')} />
            <TextInput
              label="التوريد قبل تاريخ"
              value={requiredDate}
              onChange={setRequiredDate}
              type="date"
              min={tomorrow}
              error={field('target_delivery_date')}
            />
            <label className="space-y-1 block">
              <span className="text-[11px] font-bold text-slate-700">التصنيف</span>
              <select
                value={categoryId}
                onChange={(event) => setCategoryId(event.target.value)}
                className="bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs focus:bg-white focus:outline-none"
              >
                <option value="">بلا تصنيف</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>
                    {locale === 'en' ? category.name_en || category.name_ar : category.name_ar}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <TextInput
            label="المواصفات والشروط الفنية"
            value={description}
            onChange={setDescription}
            wide
            multiline
            rows={3}
            placeholder="درجات التعبئة، الحد الأدنى لمدة الصلاحية، شرط التسليم في المستودع…"
            error={field('description')}
          />
        </div>

        <div className="flex items-center gap-3 pt-1">
          <button type="button" onClick={onClose} className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold cursor-pointer">
            إلغاء
          </button>
          <button
            type="button"
            disabled={busy || !title.trim() || !unit.trim() || !city.trim()}
            onClick={() => void create()}
            className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold cursor-pointer"
          >
            {busy ? 'جارٍ النشر…' : 'نشر طلب الاحتياج'}
          </button>
        </div>
        <p className="text-[10px] text-slate-400 leading-relaxed">
          ينشر الطلب مفتوحاً لمدة يحددها الخادم من إعداداته، ثم يُعاد حساب المطابقات عليه فوراً.
        </p>
      </div>
    </div>
  );
};

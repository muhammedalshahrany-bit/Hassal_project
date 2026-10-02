import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Gavel,
  Building2,
  Users,
  ShieldAlert,
  RefreshCw,
  Check,
  Undo2,
  Info,
  MessagesSquare,
  FileSearch,
  Sparkles,
  LifeBuoy,
  ShieldCheck,
} from 'lucide-react';
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
import { api, fieldError, type ApiResult } from '../lib/api';
import { AccountPermissionsEditor } from './AccountPermissionsEditor';
import { CommunityModerationPanel } from './CommunityModerationPanel';
import { PlatformPermissionsPanel } from './PlatformPermissionsPanel';
import { SupportQueuePanel } from './SupportDeskPanel';
import { StaffDocumentReview, TYPE_LABELS } from './OrganizationDocumentsPanel';
import { formatMoney, opportunityReasonLabel } from '../lib/config';
import { formatDateTime, getLocale } from '../lib/i18n';
import type {
  ManagedUser,
  MembershipRoleOption,
  PlatformRbacSnapshot,
  PlatformRoleOption,
  DocumentQueueRow,
  DocumentReviewSummary,
  Offer,
  OfferStatus,
  Organization,
  OrganizationStatus,
  OrganizationType,
} from '../types';

/**
 * The platform's own console: the actions only HASSAL can take.
 *
 * Five things happen here and nothing else, because these are the decisions a
 * hosted business cannot make about itself:
 *
 *   1. publish or return another business's listing (offers.moderate),
 *   2. let a business trade, or stop it (organizations.verify),
 *   3. rule on the legal papers a business filed, across every business
 *      (organizations.verify again, because accepting a copy and flipping a
 *      status are the same capability looked at from two ends),
 *   4. give an account a platform role, or lock it (users.manage),
 *   5. decide what another business's people may say in public on a listing
 *      (community.moderate).
 *
 * There are no dashboards with invented totals on this screen. A number here is
 * either `pagination.total_items` for the filter currently applied — which the
 * server counted — or it is not shown. Business settings, currencies, feature
 * flags and the audit trail belong to the configuration screen, and a member
 * list belongs to the business that owns it.
 *
 * Every control below is also enforced at the route (§36). Hiding a tab is not
 * the protection; it only keeps the console honest about what the signed-in
 * account can actually do.
 */

type ConsoleTab = 'moderation' | 'featured' | 'organizations' | 'documents' | 'community' | 'support' | 'users' | 'permissions';

interface AdminConsoleViewProps {
  can: (permission: string) => boolean;
  onNotify?: (message: string) => void;
}

/** Statuses the offer vocabulary defines; the server rejects anything else. */
const REVIEWABLE_STATUSES: OfferStatus[] = [
  'PENDING_REVIEW',
  'ACTIVE',
  'PAUSED',
  'SOLD_OUT',
  'EXPIRED',
  'CANCELLED',
  'DRAFT',
];

const ORGANIZATION_STATUSES: { value: OrganizationStatus; label: string }[] = [
  { value: 'pending_kyc', label: 'بانتظار المراجعة' },
  { value: 'verified', label: 'معتمدة' },
  { value: 'suspended', label: 'موقوفة عن التداول' },
  { value: 'rejected', label: 'مرفوضة' },
];

const ORGANIZATION_TYPES: { value: OrganizationType; label: string }[] = [
  { value: 'buyer', label: 'مشترٍ' },
  { value: 'seller', label: 'مورّد' },
  { value: 'intermediary', label: 'وسيط' },
  { value: 'admin', label: 'إشرافية' },
];

/** The account statuses the filter offers, in the words the console uses. */
const USER_STATUS_LABELS: Record<string, string> = {
  active: 'نشط',
  suspended: 'موقوف',
  inactive: 'غير نشط',
};

/** The organization's status in the words the console uses. */
function statusLabel(status: string): string {
  return ORGANIZATION_STATUSES.find((s) => s.value === status)?.label ?? status;
}

export const AdminConsoleView: React.FC<AdminConsoleViewProps> = ({ can, onNotify }) => {
  const tabs = useMemo(() => {
    const available: { id: ConsoleTab; label: string; icon: React.ElementType }[] = [];
    if (can('offers.moderate')) available.push({ id: 'moderation', label: 'مراجعة العروض', icon: Gavel });
    if (can('offers.feature')) available.push({ id: 'featured', label: 'العروض المميّزة', icon: Sparkles });
    if (can('organizations.verify')) available.push({ id: 'organizations', label: 'المنشآت والتداول', icon: Building2 });
    if (can('organizations.verify')) available.push({ id: 'documents', label: 'وثائق المنشآت', icon: FileSearch });
    if (can('community.moderate')) available.push({ id: 'community', label: 'تعليقات السوق', icon: MessagesSquare });
    if (can('support.manage')) available.push({ id: 'support', label: 'بلاغات الدعم', icon: LifeBuoy });
    if (can('users.manage')) available.push({ id: 'users', label: 'حسابات المنصة', icon: Users });
    // A separate capability from `users.manage`, and a separate tab for it: editing
    // what a role is worth is not the same act as editing an account, and one
    // operator should be grantable the matrix without being grantable the people.
    if (can('roles.manage')) available.push({ id: 'permissions', label: 'صلاحيات المنصة', icon: ShieldCheck });
    return available;
  }, [can]);

  const [activeTab, setActiveTab] = useState<ConsoleTab>('moderation');
  useEffect(() => {
    if (tabs.length && !tabs.some((tab) => tab.id === activeTab)) setActiveTab(tabs[0].id);
  }, [tabs, activeTab]);

  if (!tabs.length) {
    return (
      <div className="mx-auto my-12 max-w-3xl space-y-2 rounded-xl border border-slate-200 bg-white p-6 text-center sm:p-8">
        <ShieldAlert className="w-6 h-6 mx-auto text-amber-600" />
        <h2 className="text-sm font-black text-slate-900">هذه اللوحة لقرارات المنصة</h2>
        <p className="text-xs text-slate-500 leading-relaxed">
          لا تظهر الإجراءات هنا إلا للحساب الذي يملك صلاحية مراجعتها في الخادم: مراجعة عروض المنشآت الأخرى،
          أو اعتماد منشأة، أو الفصل في كلام منشور على عرض يخص شركة أخرى، أو إدارة حسابات المنصة.
          الصلاحيات تُمنح في قاعدة البيانات ولا تُفتح من الواجهة.
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">
      <div className="flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="min-w-0">
          <h2 className="text-lg font-black text-slate-900">لوحة قرارات المنصة</h2>
          <p className="text-xs text-slate-500 pt-0.5 leading-relaxed">
            نشر العروض، وإذن التداول للمنشآت، وأدوار الحسابات — وهي قرارات تُسجَّل باسم الحساب المتخذ لها.
          </p>
        </div>
        {/* Five queues on a handset do not fit one row; they swipe instead of
            wrapping into two rows of chips nobody can read. */}
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

      {activeTab === 'moderation' && can('offers.moderate') && <ModerationPanel onNotify={onNotify} />}
      {activeTab === 'featured' && can('offers.feature') && <FeaturePlacementPanel onNotify={onNotify} />}
      {activeTab === 'organizations' && can('organizations.verify') && <OrganizationsPanel onNotify={onNotify} />}
      {activeTab === 'documents' && can('organizations.verify') && <DocumentReviewDeskPanel onNotify={onNotify} />}
      {activeTab === 'community' && can('community.moderate') && <CommunityModerationPanel onNotify={onNotify} />}
      {activeTab === 'support' && can('support.manage') && <SupportQueuePanel locale={getLocale()} onNotify={onNotify} />}
      {activeTab === 'users' && can('users.manage') && <UsersPanel onNotify={onNotify} can={can} />}
      {activeTab === 'permissions' && can('roles.manage') && <PlatformPermissionsPanel onNotify={onNotify} />}
    </div>
  );
};

/* ────────────────────────────── offer moderation ─────────────────────────── */

const ModerationPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [status, setStatus] = useState<OfferStatus>('PENDING_REVIEW');
  const [search, setSearch] = useState('');
  const [offers, setOffers] = useState<Offer[]>([]);
  /** One dossier open at a time: a reviewer reads one business at a time. */
  const [openId, setOpenId] = useState<number | null>(null);
  const [total, setTotal] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [reasons, setReasons] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    const result = await api.getModerationQueue({ status, search: search || undefined, per_page: 25 });
    if (result.success) {
      setOffers(result.data ?? []);
      setTotal(result.pagination?.total_items ?? null);
    } else {
      setOffers([]);
      setTotal(null);
      setLoadError(result.message || 'تعذّر تحميل القائمة');
    }
    setLoading(false);
  }, [status, search]);

  useEffect(() => {
    load();
  }, [load]);

  const decide = async (offer: Offer, decision: 'APPROVE' | 'RETURN') => {
    const reason = (reasons[offer.id] ?? '').trim();
    if (decision === 'RETURN' && !reason) {
      setBanner({ kind: 'error', text: 'الإرجاع يحتاج سببًا؛ بلا سبب لا يعرف البائع ماذا يصلح.' });
      return;
    }

    setBusyId(offer.id);
    setBanner(null);
    const result = await api.reviewOffer(offer.id, decision, decision === 'RETURN' ? reason : undefined);
    setBusyId(null);

    if (result.success) {
      setBanner({ kind: 'ok', text: result.message || 'تم تسجيل القرار' });
      setReasons((prev) => ({ ...prev, [offer.id]: '' }));
      load();
      onNotify?.('تم تحديث قائمة المراجعة');
      return;
    }
    setBanner({ kind: 'error', text: decisionMessage(result, offer.status) });
  };

  return (
    <PanelShell
      title="مراجعة العروض قبل النشر"
      hint="العروض هنا لم تنشر بعد؛ النشر يجعلها حقيقة تجارية تدخل محرك المطابقة، والإرجاع يعيدها إلى البائع كمسودة مع السبب."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <LabeledSelect
          label="حالة العرض"
          value={status}
          onChange={(value) => setStatus(value as OfferStatus)}
          options={REVIEWABLE_STATUSES.map((s) => ({ value: s, label: s }))}
        />
        <TextInput label="بحث في العنوان" value={search} onChange={setSearch} placeholder="اسم المنتج أو عنوان العرض" />
        <button
          onClick={load}
          className="flex w-full items-center justify-center gap-1.5 text-xs font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer sm:w-auto"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          <span>تحديث القائمة</span>
        </button>
        {/* Counted by the server for this filter, not by the browser. */}
        <p className="text-[11px] font-medium text-slate-500 sm:pb-2">
          {total !== null ? `${total} نتيجة بالفلتر الحالي` : 'لم يردّ الخادم بعدد النتائج'}
        </p>
      </div>

      <BannerView banner={banner} />
      {loadError && <ErrorNote text={loadError} />}

      <p className="text-[10px] text-slate-400 sm:hidden">اسحب الجدول أفقيًا لقراءة كل الأعمدة؛ عمود القرار آخرها.</p>
      <div className="min-w-0 border border-slate-200 rounded-xl overflow-hidden">
        <table className="w-full text-xs text-right">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
            <tr>
              <th className="p-3">العرض</th>
              <th className="p-3">المورّد</th>
              <th className="p-3">السعر / الكمية المتاحة</th>
              <th className="p-3">سبب التصريف</th>
              <th className="p-3">الموقع</th>
              <th className="p-3 min-w-0 text-left">القرار</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {loading && (
              <tr>
                <td colSpan={6} className="p-6 text-center text-slate-400">جارٍ التحميل…</td>
              </tr>
            )}
            {!loading && !offers.length && (
              <tr>
                <td colSpan={6} className="p-6 text-center text-slate-400">
                  لا توجد عروض بهذه الحالة الآن.
                </td>
              </tr>
            )}
            {!loading && offers.map((offer) => (
              <React.Fragment key={offer.id}>
              <tr className="align-top hover:bg-slate-50/60">
                <td className="p-3 min-w-0">
                  <div className="font-bold text-slate-900">{offer.title_ar}</div>
                  <div className="text-[11px] text-slate-500">
                    {recorded(offer.variant_name_ar || offer.product_name_ar)} · #{offer.id}
                  </div>
                  <div className="text-[10px] font-mono text-slate-400 mt-0.5">{offer.status}</div>
                  <button
                    onClick={() => setOpenId(openId === offer.id ? null : offer.id)}
                    className="mt-1.5 text-[10px] font-bold text-emerald-800 border border-emerald-200 rounded-lg px-2 py-1 hover:bg-emerald-50 cursor-pointer"
                  >
                    {openId === offer.id ? 'إخفاء ملف المراجعة' : 'ملف المراجعة'}
                  </button>
                </td>
                <td className="p-3 text-slate-700">{recorded(offer.seller_name_ar || offer.seller_name_en)}</td>
                <td className="p-3 text-slate-700 whitespace-nowrap">
                  {formatMoney(offer.unit_price, offer.currency_code)}
                  <div className="text-[11px] text-slate-500">
                    {offer.available_quantity} {offer.unit} · حد أدنى {offer.min_order_quantity}
                  </div>
                </td>
                <td className="p-3 min-w-0 text-slate-700">
                  {opportunityReasonLabel(offer.opportunity_reason, 'ar') || '—'}
                  {offer.opportunity_reason_notes && (
                    <div className="text-[11px] text-slate-500">{offer.opportunity_reason_notes}</div>
                  )}
                </td>
                <td className="p-3 text-slate-700">{recorded(offer.warehouse_city)}</td>
                <td className="p-3 w-full min-w-0">
                  <textarea
                    value={reasons[offer.id] ?? ''}
                    onChange={(event) => setReasons((prev) => ({ ...prev, [offer.id]: event.target.value }))}
                    placeholder="سبب الإرجاع (مطلوب عند الإرجاع فقط)"
                    rows={2}
                    className="w-full min-w-[10rem] mb-2 bg-slate-50 border border-slate-200 rounded-lg p-2 text-[11px] focus:bg-white focus:outline-none"
                  />
                  <div className="flex flex-wrap items-center gap-1.5">
                    <button
                      onClick={() => decide(offer, 'APPROVE')}
                      disabled={busyId === offer.id || offer.status !== 'PENDING_REVIEW'}
                      className="bg-emerald-600 disabled:opacity-40 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold flex items-center gap-1 cursor-pointer"
                    >
                      <Check className="w-3.5 h-3.5" />
                      <span>نشر في السوق</span>
                    </button>
                    <button
                      onClick={() => decide(offer, 'RETURN')}
                      disabled={busyId === offer.id || offer.status !== 'PENDING_REVIEW'}
                      className="bg-white disabled:opacity-40 border border-slate-300 hover:bg-slate-100 text-slate-800 px-3 py-1.5 rounded-lg font-bold flex items-center gap-1 cursor-pointer"
                    >
                      <Undo2 className="w-3.5 h-3.5" />
                      <span>إرجاع للبائع</span>
                    </button>
                  </div>
                  {offer.status !== 'PENDING_REVIEW' && (
                    <div className="text-[10px] text-slate-400 pt-1">
                      لم يعد هذا العرض بانتظار قرار.
                    </div>
                  )}
                </td>
              </tr>
              {openId === offer.id ? (
                <tr className="bg-slate-50/70">
                  <td colSpan={6} className="p-3">
                    <ModerationDossier offer={offer} />
                  </td>
                </tr>
              ) : null}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>
    </PanelShell>
  );
};

/**
 * Everything a reviewer needs that is not on the listing itself.
 *
 * The server already assembles this per row (`OfferController::moderationContext`);
 * before this panel existed the reviewer saw a title, a price and a city, and had
 * to know to open three other screens to see whether the business behind the
 * listing had ever filed a registration certificate at all. That is how a
 * moderation queue turns into a rubber stamp.
 *
 * Two wording rules hold throughout:
 *
 *   * A date in the legal record is *this platform's own* decision, so it reads
 *     «قرّر موظف المنصة» and never implies a ministry confirmed a number (§7).
 *   * A waiting time is measured from the row's last write, because the offers
 *     table records no submission timestamp; the panel says so rather than
 *     presenting it as an exact queue age.
 */
const ModerationDossier: React.FC<{ offer: Offer }> = ({ offer }) => {
  const locale = getLocale();
  const ar = locale === 'ar';
  const context = offer.moderation;

  if (!context) {
    return (
      <ErrorNote
        text={ar
          ? 'لم يُرسل الخادم ملف المراجعة لهذا العرض؛ اعرض القائمة من جديد قبل اتخاذ قرار.'
          : 'The server sent no review dossier for this listing; reload the queue before deciding.'}
      />
    );
  }

  const legal = context.seller_legal_record;
  const history = context.seller_history;
  const documents = context.documents ?? [];

  return (
    // On a handset the dossier sits inside a swipeable table row, so it is capped
    // to the screen width: a reviewer who opens it must read it without swiping
    // back and forth under every tile.
    <div className="space-y-3 max-w-[calc(100vw-2.5rem)] sm:max-w-none">
      <div className="flex flex-wrap items-center gap-2">
        <h4 className="text-[11px] font-black text-slate-900">
          {ar ? 'ملف المراجعة' : 'Review dossier'}
        </h4>
        {offer.show_seller_identity === false ? (
          <span className="text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200 rounded-full px-2 py-0.5">
            {ar ? 'هذا البائع لن يُظهر اسمه في السوق' : 'This seller hides their name on the market'}
          </span>
        ) : null}
        {context.waiting_hours !== null && context.waiting_hours !== undefined ? (
          <span className="text-[10px] font-bold bg-amber-50 text-amber-900 border border-amber-200 rounded-full px-2 py-0.5">
            {ar
              ? `منتظر منذ ${context.waiting_hours} ساعة (محسوبة من آخر كتابة على الصف، فلا تاريخ تقديم مسجّل)`
              : `Waiting ~${context.waiting_hours} h, measured from the row's last write`}
          </span>
        ) : null}
      </div>

      {legal ? (
        <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
          <Fact label={ar ? 'المنشأة' : 'Business'} value={recorded(legal.name_ar)} />
          <Fact label={ar ? 'البلد / المدينة' : 'Country / city'} value={`${recorded(legal.country_code)} · ${recorded(legal.city)}`} />
          <Fact label={ar ? 'حالة المنشأة لدى المنصة' : 'Status on platform'} value={statusLabel(legal.platform_status)} />
          <Fact label={ar ? 'آخر قرار لحالة المنشأة' : 'Last status decision'} value={legal.reviewed_at ? formatDateTime(legal.reviewed_at, locale) : recorded(null)} />
          <Fact label={ar ? 'رقم السجل التجاري' : 'Registration no.'} value={recorded(legal.registration_no)} />
          <Fact label={ar ? 'الرقم الضريبي' : 'Tax no.'} value={recorded(legal.tax_no)} />
          <Fact label={ar ? 'الهاتف' : 'Phone'} value={recorded(legal.phone)} />
          <Fact label={ar ? 'الموقع الإلكتروني' : 'Website'} value={recorded(legal.website)} />
          <Fact label={ar ? 'عدد أعضاء المنشأة' : 'Members'} value={String(legal.member_count)} />
        </div>
      ) : (
        <ErrorNote text={ar ? 'لا سجل منشأة مُرفق بهذا العرض.' : 'No business record is attached to this listing.'} />
      )}

      <div>
        <div className="text-[10px] font-bold text-slate-600 mb-1">
          {ar
            ? 'الوثائق التي رفعتها المنشأة — والقرارات المسجّلة عليها من موظف المنصة، لا تصديق أي جهة خارجية'
            : 'Papers the business filed — and this platform staff’s own decisions on them, not a third party’s confirmation'}
        </div>
        {!documents.length ? (
          <ErrorNote
            text={ar
              ? 'لم ترفع هذه المنشأة أي وثيقة: لا يمكن معاينة شهادة لم تُرفع، وقرار النشر هنا على ما في العرض وحده.'
              : 'This business has filed nothing: there is no certificate to read, so a decision here rests on the listing alone.'}
          />
        ) : (
          <ul className="space-y-1">
            {documents.map((document) => (
              <li key={document.id} className="flex flex-wrap items-center gap-2 text-[10px] bg-white border border-slate-200 rounded-lg px-2 py-1.5">
                <span className="font-bold text-slate-800">{TYPE_LABELS[document.type] ?? document.type}</span>
                <span
                  className={`rounded px-1.5 py-0.5 font-bold border ${
                    document.status === 'accepted'
                      ? 'bg-emerald-50 border-emerald-200 text-emerald-900'
                      : document.status === 'rejected'
                        ? 'bg-rose-50 border-rose-200 text-rose-900'
                        : 'bg-amber-50 border-amber-200 text-amber-900'
                  }`}
                >
                  {document.status === 'accepted'
                    ? (ar ? 'قبِلها المراجع' : 'Accepted')
                    : document.status === 'rejected'
                      ? (ar ? 'رفضها المراجع' : 'Rejected')
                      : (ar ? 'بانتظار المراجعة' : 'Awaiting review')}
                </span>
                {document.reference_number ? <span className="text-slate-600">{document.reference_number}</span> : null}
                {document.reviewed_by ? (
                  <span className="text-slate-500">
                    {ar ? `باسم ${document.reviewed_by}` : `by ${document.reviewed_by}`}
                  </span>
                ) : null}
                {document.url ? (
                  <a href={document.url} target="_blank" rel="noreferrer" className="font-bold text-emerald-800 underline">
                    {ar ? 'فتح النسخة' : 'Open copy'}
                  </a>
                ) : (
                  <span className="text-slate-400">{ar ? 'نسخة غير متاحة' : 'copy unavailable'}</span>
                )}
                {document.review_reason ? (
                  <span className="w-full text-rose-800">{ar ? 'سبب القرار: ' : 'Reason: '}{document.review_reason}</span>
                ) : null}
              </li>
            ))}
          </ul>
        )}
      </div>

      {history ? (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6">
          <Fact label={ar ? 'عروض' : 'Offers'} value={String(history.offers_total)} />
          <Fact label={ar ? 'معروضة الآن' : 'Active'} value={String(history.offers_active)} />
          <Fact label={ar ? 'منتهية' : 'Expired'} value={String(history.offers_expired)} />
          <Fact label={ar ? 'صفقات' : 'Deals'} value={String(history.deals_total)} />
          <Fact label={ar ? 'مُسدَّدة' : 'Settled'} value={String(history.deals_settled)} />
          <Fact label={ar ? 'متنازَع عليها' : 'Disputed'} value={String(history.deals_disputed)} />
        </div>
      ) : null}

      <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
        <Fact label={ar ? 'المستودع' : 'Warehouse'} value={recorded(context.warehouse?.name)} />
        <Fact label={ar ? 'رمز المستودع' : 'Warehouse code'} value={recorded(context.warehouse?.code)} />
        <Fact
          label={ar ? 'الإحداثيات' : 'Coordinates'}
          value={context.warehouse?.lat && context.warehouse?.lon
            ? `${context.warehouse.lat}, ${context.warehouse.lon}`
            : recorded(null)}
        />
        <Fact label={ar ? 'صور المنتج' : 'Product images'} value={String(context.image_count ?? 0)} />
      </div>
    </div>
  );
};

/**
 * A review refusal read back to the reviewer. An offer can leave the queue
 * between loading it and deciding on it, and the server says so rather than
 * silently re-deciding the new state.
 */
function decisionMessage(result: ApiResult<Offer>, listedStatus: OfferStatus): string {
  if (result.error_code === 'OFFER_NOT_AWAITING_REVIEW') {
    return `لم يعد هذا العرض بانتظار القرار (حالته الآن ${listedStatus} أو ما سجله الخادم). حدّث القائمة قبل اتخاذ إجراء.`;
  }
  if (result.error_code === 'VALIDATION_FAILED') {
    return fieldError(result, 'reason') || fieldError(result, 'decision') || 'الطلب لم يكتمل: ' + (result.message ?? '');
  }
  return result.message || 'تعذّر تسجيل القرار';
}

/* ─────────────────────────── feature placement ───────────────────────────── */

/**
 * What the platform is promoting, and the hand on it.
 *
 * `offers.feature` is a separate capability from `offers.moderate` on purpose:
 * publishing a listing decides that it exists in the market, promoting one
 * decides that a thousand buyers see it before they search. A reviewer may do
 * the first without being handed the second, and the pin is stamped with the
 * account that placed it so the console can say who decided.
 *
 * Two lists, because the decision has two halves. The strip itself comes from
 * `GET /marketplace/offers/featured` — the server's order (newest pin first) and
 * the server's ceiling (`marketplace.featured_limit`), with nothing here able to
 * widen either. Candidates come from the public market list, which is already
 * only what a buyer may see; pinning anything else is refused by the route with
 * `OFFER_NOT_FEATUREABLE`, and that refusal is shown rather than smoothed over,
 * because a draft cannot be promoted and the console has to say so out loud.
 */
const FeaturePlacementPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [strip, setStrip] = useState<Offer[]>([]);
  const [ceiling, setCeiling] = useState<number | null>(null);
  const [livePins, setLivePins] = useState<number | null>(null);
  const [candidates, setCandidates] = useState<Offer[]>([]);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    const [featured, market] = await Promise.all([
      api.getFeaturedOffers(),
      api.getOffers({ search: search || undefined, sort_by: 'newest', per_page: 20 }),
    ]);

    if (featured.success && featured.data) {
      setStrip(featured.data.offers);
      setCeiling(featured.data.limit);
      setLivePins(featured.data.total_featured);
    } else {
      setStrip([]);
      setLoadError(featured.message || 'تعذّر تحميل شريط العروض المميّزة.');
    }

    setCandidates(market.success ? (market.data ?? []) : []);
    setLoading(false);
  }, [search]);

  useEffect(() => {
    load();
  }, [load]);

  const place = async (offer: Offer, featured: boolean) => {
    setBusyId(offer.id);
    setBanner(null);
    const result = await api.setOfferFeatured(offer.id, featured);
    setBusyId(null);

    if (result.success) {
      setBanner({
        kind: 'ok',
        text: featured
          ? `ثبُت العرض في مقدمة الشريط باسم هذا الحساب: ${result.data?.title_ar ?? ''}`
          : `أُزيل التمييز عن ${result.data?.title_ar ?? 'هذا العرض'}؛ يعود إلى السوق كأي عرض آخر.`,
      });
      load();
      onNotify?.(featured ? 'أُضيف عرض إلى الشريط المميّز' : 'أُزيل عرض من الشريط المميّز');
      return;
    }

    // The two refusals that mean something here, said as themselves rather than
    // as "something went wrong": a listing that is not published cannot be
    // promoted, and a strip the size of the platform's ceiling is full.
    if (result.error_code === 'OFFER_NOT_FEATUREABLE') {
      setBanner({
        kind: 'error',
        text: 'لا يُروَّج لعرضٍ غير منشور وجاهز للبيع؛ الشريط واجهة سوق، وليس طريقًا لعرض مسودة أو معادٍ للنشر.',
      });
      return;
    }
    setBanner({ kind: 'error', text: result.message || 'تعذّر تسجيل قرار الترويج.' });
  };

  const pinnedIds = new Set(strip.map((offer) => offer.id));

  return (
    <PanelShell
      title="ترويج العروض في أعلى السوق"
      hint="ما يظهر في شريط «اختيار المنصة» عند كل زائر تقرّره هذه اللوحة وحدها. السقف عددٌ من إعدادات المنصة لا تطلبه الشاشة، والترتيب أحدث التثبيتين أولاً؛ وعرضٌ يبيع كميته أو يتجاوز تاريخه يغادر الشريط من نفسه لأن قائمة السوق هي التي تُقرأ."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-3">
        <Fact label="سقف الشريط (من إعدادات المنصة)" value={ceiling !== null ? `${ceiling} عرضًا` : 'لم تُقرأ بعد'} />
        <Fact label="معروضات مثبّتة تفي بشروط السوق" value={livePins !== null ? `${livePins}` : 'لم يُردّ بعدد'} />
        <button
          onClick={load}
          className="flex w-full items-center justify-center gap-1.5 text-xs font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer sm:w-auto"
        >
          <RefreshCw className="w-3.5 h-3.5" />
          <span>تحديث</span>
        </button>
      </div>

      <BannerView banner={banner} />
      {loadError && <ErrorNote text={loadError} />}
      {loading && <p className="text-xs text-slate-400">جارٍ التحميل…</p>}

      {/* 1. The strip as the market reads it. */}
      <div className="space-y-2">
        <h4 className="text-xs font-black text-slate-900">الشريط الآن، بترتيبه</h4>
        {!strip.length && !loading && (
          <p className="text-[11px] text-slate-500">
            لا عرض مثبّتًا اليوم؛ الشريط لا يظهر للزوّار ولا يُترك مكانه فراغ.
          </p>
        )}
        <ol className="space-y-2">
          {strip.map((offer, position) => (
            <li
              key={offer.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2"
            >
              <div className="flex min-w-0 items-center gap-2">
                <span className="shrink-0 w-6 h-6 grid place-items-center rounded-full bg-emerald-700 text-white text-[11px] font-black">
                  {position + 1}
                </span>
                <div className="min-w-0">
                  <div className="text-xs font-bold text-slate-900 truncate">{offer.title_ar}</div>
                  <div className="text-[10px] text-slate-500 truncate">
                    {recorded(offer.seller_name_ar || offer.seller_name_en)} · ثُبّت {recorded(formatDateTime(offer.featured_at, 'ar'))}
                    {offer.featured_by ? ` · بواسطة ${offer.featured_by.name}` : ''}
                  </div>
                </div>
              </div>
              <button
                onClick={() => place(offer, false)}
                disabled={busyId === offer.id}
                className="shrink-0 text-[11px] font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-1.5 hover:bg-slate-100 cursor-pointer disabled:opacity-40"
              >
                إزالة من الشريط
              </button>
            </li>
          ))}
        </ol>
      </div>

      {/* 2. What else the market is showing, so a pin can be placed by name. */}
      <div className="space-y-2">
        <h4 className="text-xs font-black text-slate-900">اعرض منشورًا في السوق لتثبيته</h4>
        <TextInput label="بحث بالاسم أو SKU" value={search} onChange={setSearch} placeholder="اسم الصنف أو عنوان العرض" wide />
        <ul className="space-y-2">
          {candidates.map((offer) => (
            <li
              key={offer.id}
              className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 px-3 py-2"
            >
              <div className="min-w-0">
                <div className="text-xs font-bold text-slate-900 truncate">{offer.title_ar}</div>
                <div className="text-[10px] text-slate-500 truncate">
                  {recorded(offer.product_name_ar)} · {recorded(offer.warehouse_city)} · {offer.status} · #{offer.id}
                </div>
              </div>
              {pinnedIds.has(offer.id) ? (
                <span className="shrink-0 text-[10px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-full px-2.5 py-1">
                  في الشريط
                </span>
              ) : (
                <button
                  onClick={() => place(offer, true)}
                  disabled={busyId === offer.id}
                  className="shrink-0 text-[11px] font-bold text-white bg-emerald-600 rounded-lg px-3 py-1.5 hover:bg-emerald-700 cursor-pointer disabled:opacity-40 flex items-center gap-1.5"
                >
                  <Sparkles className="w-3 h-3" />
                  <span>تثبيته في المقدمة</span>
                </button>
              )}
            </li>
          ))}
        </ul>
        {!candidates.length && !loading && (
          <p className="text-[11px] text-slate-500">لا نتائج منشورة مطابقة للبحث الحالي.</p>
        )}
      </div>
    </PanelShell>
  );
};

/* ─────────────────────────── trading permission ──────────────────────────── */

const OrganizationsPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [search, setSearch] = useState('');
  const [organizations, setOrganizations] = useState<Organization[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [reasons, setReasons] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    const result = await api.getOrganizations({
      status: status || undefined,
      type: type || undefined,
      search: search || undefined,
      per_page: 25,
    });
    if (result.success) {
      setOrganizations(result.data ?? []);
      setTotal(result.pagination?.total_items ?? null);
    } else {
      setOrganizations([]);
      setTotal(null);
      setLoadError(result.message || 'تعذّر تحميل المنشآت');
    }
    setLoading(false);
  }, [status, type, search]);

  /** Which organization's filed papers are open right now — one drawer at a time. */
  const [docsFor, setDocsFor] = useState<number | null>(null);

  useEffect(() => {
    load();
  }, [load]);

  const decide = async (organization: Organization, decision: 'VERIFY' | 'REJECT' | 'SUSPEND' | 'REINSTATE') => {
    const reason = (reasons[organization.id] ?? '').trim();
    if ((decision === 'REJECT' || decision === 'SUSPEND') && !reason) {
      setBanner({ kind: 'error', text: 'الرفض والإيقاف يحتاجان سببًا مسجلًا؛ قرار بلا سبب لا يمكن الرد عليه لاحقًا.' });
      return;
    }

    setBusyId(organization.id);
    setBanner(null);
    const result = await api.decideOrganizationVerification(organization.id, decision, reason || undefined);
    setBusyId(null);

    if (result.success) {
      setBanner({ kind: 'ok', text: result.message || 'تم تسجيل القرار' });
      setReasons((prev) => ({ ...prev, [organization.id]: '' }));
      load();
      onNotify?.('تم تحديث قائمة المنشآت');
      return;
    }

    if (result.error_code === 'LEGAL_FIELDS_INCOMPLETE') {
      const missing = (result.error?.details?.missing_fields as string[] | undefined) ?? [];
      setBanner({
        kind: 'error',
        text: `لا يمكن الاعتماد قبل استكمال: ${missing.length ? missing.join('، ') : 'بيانات نظامية ناقصة'}`,
      });
      return;
    }
    if (result.error_code === 'DECISION_ALREADY_APPLIED') {
      setBanner({ kind: 'error', text: 'المنشأة في هذه الحالة بالفعل؛ حدّث القائمة.' });
      return;
    }
    setBanner({ kind: 'error', text: result.message || 'تعذّر تسجيل القرار' });
  };

  return (
    <PanelShell
      title="إذن التداول للمنشآت"
      hint="«معتمدة» تعني أن حسابًا في منصة حصّال راجع ما رفعته المنشأة من بيانات. المنصة لا تتصل بأي سجل رسمي، ولا تعرض نفسها على أنها جهة تحقق."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <LabeledSelect
          label="حالة المراجعة"
          value={status}
          onChange={setStatus}
          options={[{ value: '', label: 'كل الحالات' }, ...ORGANIZATION_STATUSES]}
        />
        <LabeledSelect
          label="نوع النشاط"
          value={type}
          onChange={setType}
          options={[{ value: '', label: 'كل الأنواع' }, ...ORGANIZATION_TYPES]}
        />
        <TextInput label="بحث بالاسم" value={search} onChange={setSearch} placeholder="الاسم التجاري أو المسجل" />
        <div className="flex flex-col gap-1">
          <button
            onClick={load}
            className="flex w-full items-center justify-center gap-1.5 text-xs font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تحديث القائمة</span>
          </button>
          {/* The server's own count for the filter applied; nothing is added up here. */}
          <span className="text-center text-[11px] font-medium text-slate-500">
            {total !== null ? `${total} منشأة بالفلتر الحالي` : 'لم يردّ الخادم بعدد النتائج'}
          </span>
        </div>
      </div>

      <BannerView banner={banner} />
      {loadError && <ErrorNote text={loadError} />}

      <div className="space-y-3">
        {loading && <div className="p-6 text-center text-slate-400 text-xs">جارٍ التحميل…</div>}
        {!loading && !organizations.length && (
          <div className="p-6 text-center text-slate-400 text-xs">لا توجد منشآت مطابقة.</div>
        )}
        {!loading && organizations.map((organization) => (
          <div key={organization.id} className="bg-white border border-slate-200 rounded-xl p-4 space-y-3">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="text-sm font-black text-slate-900">{organization.name_ar}</div>
                <div className="text-[11px] text-slate-500">{organization.name_en} · #{organization.id}</div>
              </div>
              <div className="flex shrink-0 flex-wrap items-center gap-2">
                <span className="text-[10px] font-mono px-2 py-0.5 rounded-full bg-slate-100 text-slate-700 border border-slate-200">
                  {organization.type}
                </span>
                <span
                  className={`text-[11px] font-bold px-2 py-0.5 rounded-full border ${
                    organization.status === 'verified'
                      ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                      : organization.status === 'pending_kyc'
                        ? 'bg-amber-50 text-amber-800 border-amber-200'
                        : 'bg-rose-50 text-rose-800 border-rose-200'
                  }`}
                >
                  {statusLabel(organization.status)}
                </span>
              </div>
            </div>

            <div className="grid grid-cols-1 gap-3 text-[11px] sm:grid-cols-2 lg:grid-cols-4">
              <Fact label="السجل التجاري" value={recorded(organization.commercial_registration_number)} />
              <Fact label="الرقم الضريبي" value={recorded(organization.vat_number)} />
              <Fact label="الدولة" value={recorded(organization.country_code)} />
              <Fact
                label="تاريخ المراجعة داخل المنصة"
                value={organization.kyc_verified_at ? formatDateTime(String(organization.kyc_verified_at), getLocale()) || String(organization.kyc_verified_at) : 'لم تُراجع بعد'}
              />
            </div>

            <div className="pt-1">
              <button
                onClick={() => setDocsFor(docsFor === organization.id ? null : organization.id)}
                className="text-[11px] font-bold text-slate-700 bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 hover:bg-slate-100 flex items-center gap-1.5 cursor-pointer"
              >
                <FileSearch className="w-3.5 h-3.5" />
                <span>{docsFor === organization.id ? 'إخفاء الوثائق المرفوعة' : 'عرض الوثائق المرفوعة'}</span>
              </button>

              {docsFor === organization.id ? (
                <div className="mt-2 border border-slate-200 rounded-xl p-3 bg-slate-50/40">
                  <StaffDocumentReview organizationId={organization.id} />
                  <p className="text-[10px] text-slate-500 pt-2">
                    قبول النسخ أو رفضها إجراء على ورقة واحدة؛ قرار «اعتماد للتداول» أو «رفض» أسفل هذا المربع هو ما
                    يفتح السوق للمنشأة أو يبقيها خارجه، وهو ما يُسجَّل باسم حالة المنشأة.
                  </p>
                </div>
              ) : null}
            </div>

            <div className="space-y-2 rounded-xl border border-slate-200 bg-slate-50/60 p-3 pt-1">
              <div className="text-[11px] font-black text-slate-800">القرار على حالة التداول</div>
              <TextInput
                label="سبب القرار"
                value={reasons[organization.id] ?? ''}
                onChange={(value) => setReasons((prev) => ({ ...prev, [organization.id]: value }))}
                placeholder="مطلوب عند الرفض أو الإيقاف"
                wide
              />
              <div className="flex flex-wrap items-center gap-2">
                {(organization.status === 'pending_kyc' || organization.status === 'rejected') && (
                  <DecisionButton
                    tone="primary"
                    busy={busyId === organization.id}
                    onClick={() => decide(organization, 'VERIFY')}
                  >
                    اعتماد للتداول
                  </DecisionButton>
                )}
                {organization.status === 'pending_kyc' && (
                  <DecisionButton
                    busy={busyId === organization.id}
                    onClick={() => decide(organization, 'REJECT')}
                  >
                    رفض
                  </DecisionButton>
                )}
                {organization.status === 'verified' && (
                  <DecisionButton
                    busy={busyId === organization.id}
                    onClick={() => decide(organization, 'SUSPEND')}
                  >
                    إيقاف عن التداول
                  </DecisionButton>
                )}
                {(organization.status === 'suspended' || organization.status === 'rejected') && (
                  <DecisionButton
                    busy={busyId === organization.id}
                    onClick={() => decide(organization, 'REINSTATE')}
                  >
                    إعادة تفعيل
                  </DecisionButton>
                )}
              </div>
              {/* Said next to the buttons, because a decision whose effect is
                  guessed at is a decision that gets reversed the next day. */}
              <p className="text-[10px] leading-relaxed text-slate-500">
                {organization.status === 'pending_kyc'
                  ? '«اعتماد للتداول» يُدخل منشأتك السوق؛ «رفض» يبقيها خارجه مع سبب يُرسل إليها.'
                  : organization.status === 'rejected'
                    ? '«اعتماد للتداول» يعيد المنشأة إلى السوق بعد استكمال ما رفض؛ القرار يُسجَّل باسمك وبالسبب.'
                    : organization.status === 'verified'
                      ? '«إيقاف عن التداول» ينزل عروض المنشأة من السوق ومن نتائج المطابقة.'
                      : '«إعادة تفعيل» تعيد المنشأة إلى السوق كما كانت قبل الإيقاف.'}
              </p>
            </div>

            {organization.status === 'suspended' && (
              <p className="text-[11px] text-rose-700 flex items-start gap-1.5">
                <Info className="w-3.5 h-3.5 mt-0.5 shrink-0" />
                <span>
                  الإيقاف يمنع التداول: عروض هذه المنشأة تنزل من السوق ومن نتائج المطابقة والبحث القريب،
                  ولا يستطيع مشترٍ الوصول إليها.
                </span>
              </p>
            )}
          </div>
        ))}
      </div>
    </PanelShell>
  );
};

/* ─────────────────────── platform document review desk ───────────────────── */

const DOCUMENT_STATUSES: { value: string; label: string }[] = [
  { value: 'pending', label: 'بانتظار القرار' },
  { value: 'accepted', label: 'مقبولة' },
  { value: 'rejected', label: 'مرفوضة' },
];

const DOCUMENT_TYPE_OPTIONS: { value: string; label: string }[] = (
  Object.keys(TYPE_LABELS) as (keyof typeof TYPE_LABELS)[]
).map((key) => ({ value: key, label: TYPE_LABELS[key] }));

/**
 * Every paper every business has filed, in one queue.
 *
 * The organizations tab rules on a business as a whole ("may it trade?"). This
 * desk rules on a single copy — the commercial registration, the tax
 * certificate, one side of a national ID — across every business at once, which
 * is the only way a reviewer sees what is actually waiting. A paper accepted
 * here does not by itself open the market; `decideOrganizationVerification`
 * does. The two are shown apart so no one mistakes a filed copy for a traded
 * business.
 *
 * The headline numbers are the server's own counts (`document-summary`), taken
 * over the same rows the queue reads, never added up in the browser from one
 * page. A rejection carries a reason because a business that is not told which
 * paper failed resubmits the same one.
 */
const DocumentReviewDeskPanel: React.FC<{ onNotify?: (m: string) => void }> = ({ onNotify }) => {
  const [status, setStatus] = useState('pending');
  const [type, setType] = useState('');
  const [search, setSearch] = useState('');
  const [rows, setRows] = useState<DocumentQueueRow[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [summary, setSummary] = useState<DocumentReviewSummary | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [reasons, setReasons] = useState<Record<number, string>>({});

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    const [queue, counts] = await Promise.all([
      api.getDocumentReviewQueue({
        status: (status || undefined) as DocumentQueueRow['status'] | undefined,
        type: (type || undefined) as DocumentQueueRow['type'] | undefined,
        search: search || undefined,
        per_page: 25,
      }),
      api.getDocumentReviewSummary(),
    ]);
    if (queue.success) {
      setRows(queue.data ?? []);
      setTotal(queue.pagination?.total_items ?? null);
    } else {
      setRows([]);
      setTotal(null);
      setLoadError(queue.message || 'تعذّر تحميل قائمة الوثائق');
    }
    if (counts.success) setSummary(counts.data ?? null);
    setLoading(false);
  }, [status, type, search]);

  useEffect(() => {
    load();
  }, [load]);

  const decide = async (row: DocumentQueueRow, action: 'accept' | 'reject') => {
    const reason = (reasons[row.id] ?? '').trim();
    if (action === 'reject' && !reason) {
      setBanner({ kind: 'error', text: 'الرفض يحتاج سببًا؛ بلا سبب لا تعرف المنشأة أي ورقة تصلّح.' });
      return;
    }
    setBusyId(row.id);
    setBanner(null);
    const result = await api.reviewOrganizationDocument(row.organization_id, row.id, {
      action,
      reason: action === 'reject' ? reason : undefined,
    });
    setBusyId(null);
    if (result.success) {
      setBanner({ kind: 'ok', text: result.message || 'تم تسجيل القرار' });
      setReasons((prev) => ({ ...prev, [row.id]: '' }));
      load();
      onNotify?.('تم تحديث قائمة الوثائق');
      return;
    }
    setBanner({ kind: 'error', text: result.message || 'تعذّر تسجيل القرار' });
  };

  return (
    <PanelShell
      title="مراجعة وثائق المنشآت"
      hint="كل نسخة رفعتها أي منشأة، في قائمة واحدة. القبول هنا إجراء على الورقة نفسها؛ ما يفتح السوق للمنشأة هو قرار «اعتماد للتداول» في تبويب المنشآت. المنصة لا تتصل بسجل رسمي ولا تدّعي تحققًا حكوميًا."
    >
      {summary && (
        <div className="space-y-2">
          <div className="text-[11px] font-bold text-slate-700">
            أرقامٌ عدّها الخادم على كل صفوف المراجعة — اضغط رقمًا لتفتح القائمة بتلك الحالة:
          </div>
          <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
            {DOCUMENT_STATUSES.map((option) => {
              const tone =
                option.value === 'pending'
                  ? 'bg-amber-50 text-amber-900 border-amber-200'
                  : option.value === 'accepted'
                    ? 'bg-emerald-50 text-emerald-900 border-emerald-200'
                    : 'bg-rose-50 text-rose-900 border-rose-200';
              return (
                <button
                  key={option.value}
                  type="button"
                  onClick={() => setStatus(option.value)}
                  className={`min-w-0 rounded-xl border px-3 py-2 text-right cursor-pointer ${tone} ${
                    status === option.value ? 'ring-2 ring-emerald-700/40' : ''
                  }`}
                >
                  <div className="text-lg font-black tabular-nums">
                    {summary.by_status[option.value as keyof DocumentReviewSummary['by_status']]}
                  </div>
                  <div className="truncate text-[10px] font-bold" title={option.label}>
                    {option.label}
                  </div>
                </button>
              );
            })}
            <div className="min-w-0 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-slate-700">
              <div className="text-lg font-black tabular-nums">
                {summary.organizations_with_pending_documents}
              </div>
              <div className="truncate text-[10px] font-bold" title="منشأة لديها وثيقة لم تُحسم">
                منشأة لديها وثيقة لم تُحسم
              </div>
            </div>
          </div>
        </div>
      )}

      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <LabeledSelect
          label="حالة الوثيقة"
          value={status}
          onChange={setStatus}
          options={[{ value: '', label: 'كل الحالات' }, ...DOCUMENT_STATUSES]}
        />
        <LabeledSelect
          label="نوع الوثيقة"
          value={type}
          onChange={setType}
          options={[{ value: '', label: 'كل الأنواع' }, ...DOCUMENT_TYPE_OPTIONS]}
        />
        <TextInput label="بحث" value={search} onChange={setSearch} placeholder="اسم المنشأة أو المرجع" />
        <div className="flex flex-col gap-1">
          <button
            onClick={load}
            className="flex w-full items-center justify-center gap-1.5 text-xs font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تحديث القائمة</span>
          </button>
          <span className="text-center text-[11px] font-medium text-slate-500">
            {total !== null ? `${total} وثيقة بالفلتر الحالي` : 'لم يردّ الخادم بعدد النتائج'}
          </span>
        </div>
      </div>

      <BannerView banner={banner} />
      {loadError && <ErrorNote text={loadError} />}

      <p className="text-[10px] text-slate-400 sm:hidden">اسحب الجدول أفقيًا لقراءة كل الأعمدة؛ عمود القرار آخرها.</p>
      <div className="min-w-0 border border-slate-200 rounded-xl overflow-hidden">
        <table className="w-full text-xs text-right">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
            <tr>
              <th className="p-3 min-w-0">الوثيقة</th>
              <th className="p-3 min-w-0">المنشأة</th>
              <th className="p-3 min-w-0">المرفوع</th>
              <th className="p-3 min-w-0">الانتظار</th>
              <th className="p-3 w-full min-w-0 text-left">القرار</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {loading && (
              <tr><td colSpan={5} className="p-6 text-center text-slate-400">جارٍ التحميل…</td></tr>
            )}
            {!loading && !rows.length && (
              <tr><td colSpan={5} className="p-6 text-center text-slate-400">لا توجد وثائق بهذه الحالة الآن.</td></tr>
            )}
            {!loading && rows.map((row) => (
              <tr key={row.id} className="align-top hover:bg-slate-50/60">
                <td className="p-3 min-w-0">
                  <div className="font-bold text-slate-900">{TYPE_LABELS[row.type] ?? row.type}</div>
                  <div className="text-[10px] font-mono text-slate-400 mt-0.5">{row.status} · #{row.id}</div>
                  {row.reference_number && (
                    <div className="text-[11px] text-slate-500">مرجع: {row.reference_number}</div>
                  )}
                </td>
                <td className="p-3 min-w-0 text-slate-700">
                  {row.organization ? (
                    <>
                      <div className="font-bold text-slate-900">{row.organization.name_ar}</div>
                      <div className="text-[11px] text-slate-500">
                        {row.organization.type} · {statusLabel(row.organization.status)}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        مقبول: {row.organization.accepted_types.length
                          ? row.organization.accepted_types
                              .map((t) => TYPE_LABELS[t as keyof typeof TYPE_LABELS] ?? t)
                              .join('، ')
                          : 'لا شيء بعد'}
                      </div>
                    </>
                  ) : (
                    <span className="text-slate-400">منشأة محذوفة</span>
                  )}
                </td>
                <td className="p-3 min-w-0 text-slate-700">
                  {recorded(row.uploaded_by_name)}
                  <div className="text-[11px] text-slate-500">
                    {row.uploaded_at ? formatDateTime(String(row.uploaded_at), getLocale()) || String(row.uploaded_at) : '—'}
                  </div>
                  {row.url && (
                    <a
                      href={row.url}
                      target="_blank"
                      rel="noreferrer"
                      className="mt-1.5 inline-block rounded-lg border border-emerald-200 bg-emerald-50 px-2 py-1 text-[11px] font-bold text-emerald-700 hover:underline"
                    >
                      فتح نسخة الوثيقة
                    </a>
                  )}
                </td>
                <td className="p-3 min-w-0 text-slate-700">
                  {row.status === 'pending' && row.waiting_hours != null ? (
                    <span className="whitespace-nowrap text-[11px] font-bold text-amber-700">
                      {Math.round(Number(row.waiting_hours))} ساعة انتظار
                    </span>
                  ) : row.status === 'pending' ? (
                    <span className="text-[11px] text-slate-400">—</span>
                  ) : (
                    <div className="text-[11px] text-slate-500">
                      {recorded(row.reviewed_by_name)}
                      {row.reviewed_at ? (
                        <div>{formatDateTime(String(row.reviewed_at), getLocale()) || String(row.reviewed_at)}</div>
                      ) : null}
                      {row.review_reason && <div className="text-slate-600 mt-0.5">{row.review_reason}</div>}
                    </div>
                  )}
                </td>
                <td className="p-3 w-full min-w-0">
                  <textarea
                    value={reasons[row.id] ?? ''}
                    onChange={(event) => setReasons((prev) => ({ ...prev, [row.id]: event.target.value }))}
                    placeholder="سبب الرفض (مطلوب عند الرفض فقط)"
                    rows={2}
                    className="w-full min-w-[10rem] mb-2 bg-slate-50 border border-slate-200 rounded-lg p-2 text-[11px] focus:bg-white focus:outline-none"
                  />
                  <div className="flex flex-wrap items-center gap-1.5">
                    <button
                      onClick={() => decide(row, 'accept')}
                      disabled={busyId === row.id || row.status === 'accepted'}
                      className="bg-emerald-600 disabled:opacity-40 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg font-bold flex items-center gap-1 cursor-pointer"
                    >
                      <Check className="w-3.5 h-3.5" />
                      <span>قبول</span>
                    </button>
                    <button
                      onClick={() => decide(row, 'reject')}
                      disabled={busyId === row.id || row.status === 'rejected'}
                      className="bg-white disabled:opacity-40 border border-slate-300 hover:bg-slate-100 text-slate-800 px-3 py-1.5 rounded-lg font-bold flex items-center gap-1 cursor-pointer"
                    >
                      <Undo2 className="w-3.5 h-3.5" />
                      <span>رفض</span>
                    </button>
                  </div>
                  {row.status !== 'pending' && (
                    <div className="text-[10px] text-slate-400 pt-1">هذه الوثيقة محسومة بالفعل؛ القرار يحدّثها إن لزم.</div>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </PanelShell>
  );
};

/* ───────────────────────────── platform accounts ─────────────────────────── */

/**
 * The permissions behind a role name, from the server's own vocabulary.
 *
 * This used to live in a `title` attribute, which no phone can open: the
 * reviewer saw "12 صلاحيات" and had to guess whether they were about to hand
 * over `users.manage`. A native disclosure needs no state and no endpoint.
 */
const PermissionsList: React.FC<{ label: string; permissions: string[] }> = ({ label, permissions }) => (
  <details className="min-w-0 text-[10px] text-slate-500">
    <summary className="cursor-pointer font-bold text-slate-600">{label}</summary>
    <ul className="mt-1 list-disc space-y-0.5 border-s border-slate-200 ps-4">
      {permissions.map((permission) => (
        <li key={permission} className="break-all font-mono">
          {permission}
        </li>
      ))}
    </ul>
  </details>
);

const UsersPanel: React.FC<{ onNotify?: (m: string) => void; can: (permission: string) => boolean }> = ({ onNotify, can }) => {
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [users, setUsers] = useState<ManagedUser[]>([]);
  const [roles, setRoles] = useState<PlatformRoleOption[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [banner, setBanner] = useState<Banner>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [pendingRole, setPendingRole] = useState<Record<number, string>>({});
  const [reasons, setReasons] = useState<Record<number, string>>({});
  /**
   * The five business standings the server will accept, and the choice made for
   * one membership row. Keyed `user:organization` because the same person is a
   * different rank in each company they belong to.
   */
  const [membershipRoles, setMembershipRoles] = useState<MembershipRoleOption[]>([]);
  const [pendingMemberRole, setPendingMemberRole] = useState<Record<string, string>>({});

  /**
   * The account door (§4.12): the catalog, the roles and the names a person may
   * not be given. Read only where it can also be written — `roles.manage` guards
   * both halves — because a `users.manage` operator would spend a request on a
   * refusal for an editor they are not shown anyway.
   *
   * A standing is asked for here rather than inferred from the tab being open:
   * `users.manage` edits a person, `roles.manage` edits what a role is worth, and
   * one operator may hold exactly one of them.
   */
  const [snapshot, setSnapshot] = useState<PlatformRbacSnapshot | null>(null);
  const [selected, setSelected] = useState<number[]>([]);
  const [editing, setEditing] = useState<number[] | null>(null);
  const mayGovernAccounts = can('roles.manage');

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(null);
    const [usersResult, rolesResult, memberRolesResult, rbacResult] = await Promise.all([
      api.getUsers({ search: search || undefined, status: status || undefined, per_page: 25 }),
      api.getUserRoles(),
      api.getMembershipRoles(),
      mayGovernAccounts ? api.getPlatformRbac() : Promise.resolve(null),
    ]);

    if (usersResult.success) {
      setUsers(usersResult.data ?? []);
      setTotal(usersResult.pagination?.total_items ?? null);
      // Nobody stays picked who is no longer on the page: the bulk decision names
      // its people by the rows the operator could see when they picked them.
      setSelected((current) => current.filter((id) => (usersResult.data ?? []).some((user) => user.id === id)));
    } else {
      setUsers([]);
      setTotal(null);
      setSelected([]);
      setLoadError(usersResult.message || 'تعذّر تحميل الحسابات');
    }
    if (rolesResult.success) setRoles(rolesResult.data ?? []);
    if (memberRolesResult.success) setMembershipRoles(memberRolesResult.data ?? []);
    if (rbacResult?.success) setSnapshot(rbacResult.data ?? null);
    setLoading(false);
  }, [search, status, mayGovernAccounts]);

  useEffect(() => {
    load();
  }, [load]);

  const toggleSelected = (id: number) => {
    setSelected((current) => (current.includes(id) ? current.filter((value) => value !== id) : [...current, id]));
  };

  const run = async (id: number, action: () => Promise<ApiResult<unknown>>, done: string) => {
    setBusyId(id);
    setBanner(null);
    const result = await action();
    setBusyId(null);
    if (result.success) {
      setBanner({ kind: 'ok', text: result.message || done });
      load();
      onNotify?.(done);
    } else {
      setBanner({
        kind: 'error',
        text: result.error_code === 'CANNOT_DISABLE_SELF'
          ? 'لا يمكنك إيقاف الحساب الذي أنت مسجَّل به.'
          : result.message || 'تعذّر تنفيذ الإجراء',
      });
    }
  };

  /** The account door is only drawn where it can be opened and has been read. */
  const governAccounts = mayGovernAccounts && snapshot !== null;

  const targets = (ids: number[]) =>
    ids
      .map((id) => {
        const row = users.find((user) => user.id === id);
        return { id, name: row?.name?.trim() || `مستخدم #${id}` };
      });

  return (
    <PanelShell
      title="حسابات المنصة وأدوارها"
      hint="عمودان لا واحد: «أدوار المنصة» ما منحه حسابُ المنصة لنفسه، و«الدور داخل منشآته» ما هو فيه داخل كل شركة ينتسب إليها. تغيير أيٍّ منهما لا يمس الآخر، وكل إجراء يحتاج سببًا يُحفظ في سجل التدقيق. القائمتان تأتيان من الخادم حتى لا يعرض النظام اسمًا لم يعد يقبله. والمنح المباشر الذي لا يأتي من دور يُقرأ ويُكتب من صفّ الحساب نفسه، فردًا أو بعد تحديد عدة حسابات لقرار واحد."
    >
      <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <TextInput label="بحث" value={search} onChange={setSearch} placeholder="الاسم أو البريد أو الهاتف" />
        <LabeledSelect
          label="حالة الحساب"
          value={status}
          onChange={setStatus}
          options={[
            { value: '', label: 'كل الحالات' },
            { value: 'active', label: 'نشط' },
            { value: 'suspended', label: 'موقوف' },
            { value: 'inactive', label: 'غير نشط' },
          ]}
        />
        <div className="flex flex-col gap-1">
          <button
            onClick={load}
            className="flex w-full items-center justify-center gap-1.5 text-xs font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-3 py-2 hover:bg-slate-50 cursor-pointer"
          >
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تحديث القائمة</span>
          </button>
          <span className="text-center text-[11px] font-medium text-slate-500">
            {total !== null ? `${total} حساب بالفلتر الحالي` : 'لم يردّ الخادم بعدد النتائج'}
          </span>
        </div>
      </div>

      <BannerView banner={banner} />
      {loadError && <ErrorNote text={loadError} />}
      {mayGovernAccounts && !snapshot && !loading ? (
        <ErrorNote text="لم تصل قائمة صلاحيات المنصة من الخادم؛ زرّ «المنح المباشر» غائب حتى تُقرأ القائمة، لأن الشاشة لا تعرض اسمًا قد يرفضه الباب." />
      ) : null}

      {/* The bulk handle sits above the rows it acts on, not in a corner nobody
          reads: one decision over eight accounts is the riskiest write on this
          page, and the names it covers are the ones already on screen. */}
      {governAccounts && users.length > 1 ? (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-[11px] dark:border-hassal-line dark:bg-hassal-raised">
          <button
            type="button"
            onClick={() =>
              setSelected((current) =>
                current.length === users.length ? [] : users.map((user) => user.id)
              )
            }
            className="cursor-pointer rounded-lg border border-slate-300 bg-white px-2.5 py-1 font-bold text-slate-700 hover:bg-slate-100 dark:border-hassal-line dark:bg-hassal-night dark:text-slate-100"
          >
            {selected.length === users.length ? 'إلغاء تحديد الكل' : `تحديد الكل (${users.length})`}
          </button>
          {selected.length > 0 ? (
            <>
              <span className="font-bold text-slate-700 dark:text-slate-200">
                محدد {selected.length} من {users.length}
              </span>
              <button
                type="button"
                onClick={() => setEditing(selected)}
                className="cursor-pointer rounded-lg bg-emerald-600 px-3 py-1 font-bold text-white hover:bg-emerald-700"
              >
                قرار واحد على {selected.length}
              </button>
              <button
                type="button"
                onClick={() => setSelected([])}
                className="cursor-pointer rounded-lg border border-slate-300 px-2.5 py-1 font-bold text-slate-600 hover:bg-slate-100"
              >
                مسح التحديد
              </button>
            </>
          ) : (
            <span className="text-slate-500 dark:text-slate-400">
              حدّد حسابات لتكتب لهم نفس المنح أو السحب في قرار واحد وسبب واحد.
            </span>
          )}
        </div>
      ) : null}

      <p className="text-[10px] text-slate-400 sm:hidden">
        اسحب الجدول أفقيًا: سبب الإجراء يظهر في عمود الحساب أول الجدول، وهو ما تحتاجه بقية الأعمدة.
      </p>
      <div className="min-w-0 border border-slate-200 rounded-xl overflow-hidden">
        <table className="w-full text-xs text-right">
          <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
            <tr>
              <th className="p-3 min-w-0">الحساب وسبب الإجراء</th>
              <th className="p-3 min-w-0">أدوار المنصة</th>
              <th className="p-3 min-w-0">الدور داخل منشآته</th>
              <th className="p-3 min-w-0">الحالة</th>
              <th className="p-3 min-w-0 text-left">إجراءات</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {loading && (
              <tr>
                <td colSpan={5} className="p-6 text-center text-slate-400">جارٍ التحميل…</td>
              </tr>
            )}
            {!loading && !users.length && (
              <tr>
                <td colSpan={5} className="p-6 text-center text-slate-400">لا توجد حسابات مطابقة.</td>
              </tr>
            )}
            {!loading && users.map((user) => {
              const heldNames = user.roles ?? [];
              // Split by what the server is willing to grant here, not by how the
              // row was labelled. `roles` is the platform list the same endpoint
              // refuses anything outside, so a name absent from it belongs to a
              // company and is changed in the column beside this one.
              const platformNames = heldNames.filter((name) => roles.some((option) => option.name === name));
              const companyNames = heldNames.filter((name) => !roles.some((option) => option.name === name));
              const assignable = roles.filter((role) => !platformNames.includes(role.name));
              const nextRole = pendingRole[user.id] ?? assignable[0]?.name ?? '';
              const nextRoleOption = roles.find((role) => role.name === nextRole);
              const reason = (reasons[user.id] ?? '').trim();
              const targetStatus = user.status === 'active' ? 'suspended' : 'active';
              const memberships = user.organizations ?? [];

              return (
                <tr key={user.id} className="align-top hover:bg-slate-50/60">
                  <td className="p-3 min-w-0">
                    {governAccounts ? (
                      <label className="mb-1 flex cursor-pointer items-center gap-2 text-[11px] font-bold text-slate-600 dark:text-slate-300">
                        <input
                          type="checkbox"
                          checked={selected.includes(user.id)}
                          onChange={() => toggleSelected(user.id)}
                          className="h-3.5 w-3.5 accent-emerald-600"
                        />
                        <span>اختيار لقرار جماعي</span>
                      </label>
                    ) : null}
                    <div className="font-bold text-slate-900">{user.name}</div>
                    <div className="text-[11px] text-slate-500 break-all" dir="ltr">
                      {recorded(user.email)}
                    </div>
                    <div className="text-[11px] text-slate-400">#{user.id}</div>
                    {/* One reason per account, written where the row starts: the
                        server stores it with every decision made below it. */}
                    <label className="block space-y-1 pt-2">
                      <span className="block text-[10px] font-bold text-slate-600">سبب الإجراء (مطلوب)</span>
                      <input
                        value={reasons[user.id] ?? ''}
                        onChange={(event) => setReasons((prev) => ({ ...prev, [user.id]: event.target.value }))}
                        placeholder="مثال: انتقال المسؤول عن فريق الاستيراد"
                        className="w-full min-w-[11rem] bg-slate-50 border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] focus:bg-white focus:outline-none"
                      />
                    </label>
                  </td>
                  <td className="p-3 min-w-0">
                    {platformNames.length ? (
                      <div className="space-y-1">
                        {platformNames.map((role) => {
                          const held = roles.find((option) => option.name === role);
                          return (
                            <div key={role} className="flex flex-wrap items-center gap-x-1.5 gap-y-1">
                              <span
                                className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-indigo-50 border border-indigo-200 text-indigo-800"
                                title={held?.permissions?.length ? held.permissions.join('، ') : undefined}
                              >
                                {role}
                              </span>
                              {held?.permissions?.length ? (
                                <span className="text-[10px] text-slate-500">{held.permissions.length} صلاحية</span>
                              ) : null}
                              <button
                                disabled={busyId === user.id}
                                onClick={() => run(user.id, () => api.revokeUserRole(user.id, role, reason), 'تم سحب الدور')}
                                className="text-[10px] font-bold text-rose-700 border border-rose-200 rounded-lg px-2 py-1 disabled:opacity-40 cursor-pointer"
                              >
                                سحب الدور
                              </button>
                            </div>
                          );
                        })}
                      </div>
                    ) : (
                      <span className="text-[11px] text-slate-400">لا دور على المنصة</span>
                    )}

                    {assignable.length ? (
                      <div className="mt-2 space-y-1.5 rounded-lg border border-slate-200 bg-slate-50/70 p-2">
                        <div className="text-[10px] font-bold text-slate-600">إسناد دور منصة</div>
                        <div className="flex flex-wrap items-center gap-1.5">
                          <select
                            value={nextRole}
                            onChange={(event) => setPendingRole((prev) => ({ ...prev, [user.id]: event.target.value }))}
                            className="min-w-0 flex-1 bg-white border border-slate-200 rounded-lg px-2 py-1.5 text-[11px] font-mono"
                          >
                            {assignable.map((role) => (
                              <option key={role.id} value={role.name}>
                                {role.name}
                                {role.permissions?.length ? ` (${role.permissions.length} صلاحية)` : ''}
                              </option>
                            ))}
                          </select>
                          <button
                            disabled={busyId === user.id || !nextRole || !reason}
                            onClick={() => run(user.id, () => api.assignUserRole(user.id, nextRole, reason), 'تم إسناد الدور')}
                            className="text-[11px] font-bold text-emerald-800 bg-emerald-50 border border-emerald-200 rounded-lg px-2.5 py-1.5 disabled:opacity-40 cursor-pointer"
                          >
                            إسناد
                          </button>
                        </div>
                        {/* Naming the grant without its worth is how an admin gives
                            `users.manage` expecting a read-only reviewer. The list opens
                            on tap as well as on hover, because a phone has no hover. */}
                        {nextRoleOption?.permissions?.length ? (
                          <PermissionsList
                            label={`يمنح ${nextRoleOption.permissions.length} صلاحية على المنصة كلها — اضغط لعرضها`}
                            permissions={nextRoleOption.permissions}
                          />
                        ) : null}
                        {!reason ? (
                          <div className="text-[10px] font-bold text-amber-700">اكتب سبب الإجراء في عمود الحساب أولًا.</div>
                        ) : null}
                      </div>
                    ) : null}

                    {companyNames.length > 0 && (
                      <div className="flex flex-wrap gap-1 mt-2">
                        {companyNames.map((role) => (
                          <span
                            key={role}
                            title="دور داخل منشأة؛ يُغيَّر من العمود المجاور"
                            className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-slate-100 border border-slate-200 text-slate-500"
                          >
                            {role}
                          </span>
                        ))}
                      </div>
                    )}
                  </td>
                  <td className="p-3 min-w-0">
                    {memberships.length ? (
                      <div className="space-y-2">
                        {memberships.map((org) => {
                          const key = `${user.id}:${org.id}`;
                          const choices = membershipRoles.length
                            ? membershipRoles
                            : [{ value: org.role, label: org.role }];
                          const wanted = pendingMemberRole[key] ?? org.role;
                          const changed = wanted !== org.role;
                          const active = org.membership_active !== false;
                          const roleLabel = (value: string) =>
                            choices.find((option) => option.value === value)?.label ?? value;
                          // The standing about to take effect, with the grants the server says
                          // it carries. Absent when the vocabulary endpoint has not answered,
                          // in which case the row shows only the name it already showed.
                          const picked = choices.find((option) => option.value === wanted);

                          return (
                            <div
                              key={org.id}
                              className="min-w-0 space-y-1.5 rounded-lg border border-slate-200 bg-slate-50/70 p-2"
                            >
                              <div className="flex min-w-0 items-start justify-between gap-2">
                                <span className="min-w-0 text-[11px] font-bold text-slate-700">
                                  <span className="block truncate" title={recorded(getLocale() === 'ar' ? org.name_ar : org.name_en)}>
                                    {recorded(getLocale() === 'ar' ? org.name_ar : org.name_en)}
                                  </span>
                                  {!active && (
                                    <span className="block text-[10px] font-medium text-amber-700">
                                      عضوية منتهية الوصول
                                    </span>
                                  )}
                                </span>
                                {!changed && (
                                  <span className="shrink-0 text-[10px] text-slate-400">مسجَّل: {roleLabel(org.role)}</span>
                                )}
                              </div>
                              <div className="flex flex-wrap items-center gap-1.5">
                                <select
                                  value={wanted}
                                  onChange={(event) => setPendingMemberRole((prev) => ({ ...prev, [key]: event.target.value }))}
                                  className="min-w-0 flex-1 bg-white border border-slate-200 rounded-lg px-1.5 py-1 text-[11px]"
                                >
                                  {choices.map((option) => (
                                    <option key={option.value} value={option.value}>
                                      {option.label}
                                      {option.permissions?.length ? ` (${option.permissions.length} صلاحية)` : ''}
                                    </option>
                                  ))}
                                </select>
                                <button
                                  disabled={!changed || busyId === user.id || !reason}
                                  onClick={() =>
                                    run(
                                      user.id,
                                      () => api.updateOrganizationMember(org.id, user.id, wanted, reason),
                                      'تم تحديث الدور داخل المنشأة'
                                    )
                                  }
                                  className="text-[10px] font-bold text-slate-700 bg-white border border-slate-300 rounded-lg px-2 py-1 disabled:opacity-40 cursor-pointer"
                                >
                                  حفظ الدور
                                </button>
                                <button
                                  disabled={busyId === user.id || !reason}
                                  title="الوصول داخل المنشأة، لا الحالة العامة للحساب"
                                  onClick={() =>
                                    run(
                                      user.id,
                                      () => api.updateOrganizationMember(org.id, user.id, org.role, reason, !active),
                                      active ? 'أُنهيت عضوية الشخص في هذه المنشأة' : 'أُعيدت العضوية'
                                    )
                                  }
                                  className="text-[10px] font-bold text-slate-600 bg-white border border-slate-300 rounded-lg px-2 py-1 disabled:opacity-40 cursor-pointer"
                                >
                                  {active ? 'إنهاء الوصول' : 'إعادة الوصول'}
                                </button>
                              </div>
                              {/* What the standing is worth, from the server's own matrix: a
                                  dropdown that names a role without saying what it grants is
                                  an appointment made by guessing. Opens on tap too. */}
                              {picked?.permissions?.length ? (
                                <PermissionsList
                                  label={`${wanted === org.role ? 'يملك' : 'سيصبح'} ${roleLabel(wanted)} بـ ${picked.permissions.length} صلاحية داخل هذه المنشأة — اضغط لعرضها`}
                                  permissions={picked.permissions}
                                />
                              ) : null}
                            </div>
                          );
                        })}
                      </div>
                    ) : (
                      <span className="text-[11px] text-slate-400">لا عضوية في منشأة</span>
                    )}
                  </td>
                  <td className="p-3 min-w-0">
                    <span
                      title={user.status}
                      className={`inline-block whitespace-nowrap text-[11px] font-bold px-2 py-0.5 rounded-full border ${
                        user.status === 'active'
                          ? 'bg-emerald-50 text-emerald-800 border-emerald-200'
                          : 'bg-rose-50 text-rose-800 border-rose-200'
                      }`}
                    >
                      {USER_STATUS_LABELS[user.status] ?? user.status}
                    </span>
                  </td>
                  <td className="p-3 w-full min-w-0">
                    <div className="flex flex-col gap-1.5 sm:flex-row sm:items-center">
                      {governAccounts ? (
                        <button
                          disabled={busyId === user.id}
                          onClick={() => setEditing([user.id])}
                          title="ما أُسنِد لهذا الحساب باسمه، لا ما أتاه من دور"
                          className="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-40 cursor-pointer dark:border-hassal-line dark:bg-hassal-raised dark:text-slate-100"
                        >
                          المنح المباشر
                        </button>
                      ) : null}
                      <button
                        disabled={busyId === user.id || !reason}
                        onClick={() =>
                          run(
                            user.id,
                            () => api.updateUserStatus(user.id, targetStatus, reason),
                            'تم تحديث حالة الحساب'
                          )
                        }
                        className={`text-[11px] font-bold px-2.5 py-1.5 rounded-lg border disabled:opacity-40 cursor-pointer ${
                          targetStatus === 'suspended'
                            ? 'bg-rose-600 text-white border-rose-600'
                            : 'bg-emerald-600 text-white border-emerald-600'
                        }`}
                      >
                        {targetStatus === 'suspended' ? 'إيقاف الحساب' : 'إعادة تفعيل الحساب'}
                      </button>
                      {/* Saying what the lock does, since the row above it changes
                          a person's standing inside one company, not this. */}
                      <span className="text-[10px] leading-relaxed text-slate-500">
                        {targetStatus === 'suspended'
                          ? 'يمنع الحساب من الدخول نهائيًا؛ لا يغيّر أدواره ولا عضوياته.'
                          : 'يعيد للحساب دخول المنصة بأدواره وعضوياته المسجَّلة كما هي.'}
                      </span>
                    </div>
                    {!reason ? (
                      <div className="text-[10px] font-bold text-amber-700 pt-1">اكتب سبب الإجراء في عمود الحساب أولًا.</div>
                    ) : null}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      {editing?.length && snapshot ? (
        <AccountPermissionsEditor
          targets={targets(editing)}
          snapshot={snapshot}
          onClose={() => setEditing(null)}
          onSaved={(message) => {
            setBanner({ kind: 'ok', text: message });
            setSelected([]);
            load();
            onNotify?.(message);
          }}
        />
      ) : null}

      <p className="text-[11px] text-slate-500 flex items-start gap-1.5">
        <Info className="w-3.5 h-3.5 mt-0.5 shrink-0 text-slate-400" />
        <span>
          السجل هنا للقرارات وتواريخها، وهو ما يفتح باب الاعتراض لاحقًا. المنصة لا تحتفظ بكلمات المرور ولا تعرضها،
          ولا تمنح أي حساب صلاحية من هذه الشاشة خارج الأدوار المذكورة.
        </span>
      </p>
    </PanelShell>
  );
};

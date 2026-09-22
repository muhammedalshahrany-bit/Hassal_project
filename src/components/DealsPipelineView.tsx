import React, { useState, useEffect, useCallback } from 'react';
import {
  Clock,
  CheckCircle2,
  AlertTriangle,
  ShieldCheck,
  Receipt,
  ChevronRight,
  PackageCheck,
  Truck,
  HandCoins,
} from 'lucide-react';
import { api } from '../lib/api';
import { formatMoney, isFeatureEnabled, usePlatformConfig } from '../lib/config';
import { formatDateTime, getLocale } from '../lib/i18n';
import type { Deal, DealStatus, PurchaseRequest, PurchaseRequestStatus, Reservation } from '../types';

interface DealsPipelineProps {
  currentPersonaId: string;
  onOpenDispute?: (dealId: number) => void;
  /**
   * The session's permission check. Offered actions are filtered by it, but it
   * is only a courtesy: the route middlewares decide, and their refusal is what
   * the screen shows (§36).
   */
  can?: (permission: string) => boolean;
}

const t = () => (getLocale() === 'ar' ? AR : EN);

/**
 * What each deal state means to the person looking at it.
 *
 * The words follow the server's vocabulary exactly. Earlier this screen folded
 * sixteen real states into seven friendlier ones — INSPECTION, RESOLVED and
 * DRAFT all vanished into labels like "مسددة" — so a buyer who asked "is my
 * money paid?" was told yes by a map nobody could defend.
 */
const AR: Record<DealStatus, string> = {
  DRAFT: 'مسودة',
  PENDING_SELLER_APPROVAL: 'بانتظار موافقة البائع',
  ACCEPTED: 'مقبولة من الطرفين',
  PENDING_PAYMENT: 'بانتظار السداد',
  ESCROW_FUNDED: 'مموّلة في حساب الضمان',
  PROCESSING: 'قيد التجهيز',
  READY_FOR_PICKUP: 'جاهزة للاستلام',
  DISPATCHED: 'تم الشحن',
  DELIVERED: 'تم التسليم',
  INSPECTION: 'داخل مهلة الفحص',
  COMPLETED: 'مكتملة',
  SETTLED: 'مسوّاة',
  CANCELLED: 'ملغاة',
  DISPUTED: 'متنازَع عليها',
  RESOLVED: 'تم حل النزاع',
  REFUNDED: 'مسترجع فيها',
};

const EN: Record<DealStatus, string> = {
  DRAFT: 'Draft',
  PENDING_SELLER_APPROVAL: 'Awaiting seller approval',
  ACCEPTED: 'Accepted',
  PENDING_PAYMENT: 'Payment pending',
  ESCROW_FUNDED: 'Escrow funded',
  PROCESSING: 'Preparing',
  READY_FOR_PICKUP: 'Ready for pickup',
  DISPATCHED: 'Dispatched',
  DELIVERED: 'Delivered',
  INSPECTION: 'In inspection window',
  COMPLETED: 'Completed',
  SETTLED: 'Settled',
  CANCELLED: 'Cancelled',
  DISPUTED: 'Disputed',
  RESOLVED: 'Dispute resolved',
  REFUNDED: 'Refunded',
};

const FULFILMENT_STEPS: DealStatus[] = [
  'PENDING_SELLER_APPROVAL',
  'ACCEPTED',
  'PENDING_PAYMENT',
  'PROCESSING',
  'READY_FOR_PICKUP',
  'DISPATCHED',
  'DELIVERED',
  'INSPECTION',
  'COMPLETED',
  'SETTLED',
];

/**
 * Mirrors DealStateMachineService::ALLOWED_TRANSITIONS so the screen offers the
 * moves that exist. This is presentation only — the server re-decides every
 * transition, including whether this actor is the buyer or the seller and
 * whether the escrow capability is switched on at all.
 */
const NEXT_STATES: Partial<Record<DealStatus, DealStatus[]>> = {
  PENDING_SELLER_APPROVAL: ['ACCEPTED', 'CANCELLED'],
  ACCEPTED: ['PENDING_PAYMENT', 'ESCROW_FUNDED', 'CANCELLED'],
  PENDING_PAYMENT: ['ESCROW_FUNDED', 'PROCESSING', 'CANCELLED'],
  ESCROW_FUNDED: ['PROCESSING', 'CANCELLED', 'DISPUTED'],
  PROCESSING: ['READY_FOR_PICKUP', 'DISPATCHED', 'DELIVERED', 'DISPUTED', 'CANCELLED'],
  READY_FOR_PICKUP: ['DELIVERED', 'DISPUTED', 'CANCELLED'],
  DISPATCHED: ['DELIVERED', 'DISPUTED'],
  DELIVERED: ['INSPECTION', 'COMPLETED', 'DISPUTED'],
  INSPECTION: ['COMPLETED', 'DISPUTED'],
  COMPLETED: ['SETTLED'],
  DISPUTED: ['RESOLVED', 'REFUNDED', 'SETTLED'],
  RESOLVED: ['SETTLED', 'REFUNDED'],
  SETTLED: [],
  REFUNDED: [],
  CANCELLED: [],
  DRAFT: ['PENDING_SELLER_APPROVAL', 'CANCELLED'],
};

/** Transitions the server will refuse unless the escrow capability is on. */
const ESCROW_STATES: DealStatus[] = ['ESCROW_FUNDED', 'REFUNDED'];

/** Transitions that cannot be made without stating why. */
const REASON_REQUIRED: DealStatus[] = ['CANCELLED', 'DISPUTED', 'RESOLVED', 'REFUNDED'];

const ACTION_LABELS: Record<string, { ar: string; en: string }> = {
  ACCEPTED: { ar: 'قبول الصفقة', en: 'Accept deal' },
  PENDING_PAYMENT: { ar: 'بانتظار السداد', en: 'Mark payment pending' },
  ESCROW_FUNDED: { ar: 'تأكيد تمويل الضمان', en: 'Confirm escrow funding' },
  PROCESSING: { ar: 'بدء التجهيز', en: 'Start preparing' },
  READY_FOR_PICKUP: { ar: 'جاهزة للاستلام', en: 'Ready for pickup' },
  DISPATCHED: { ar: 'تم الشحن', en: 'Mark dispatched' },
  DELIVERED: { ar: 'تأكيد التسليم', en: 'Confirm delivery' },
  INSPECTION: { ar: 'بدء الفحص', en: 'Open inspection' },
  COMPLETED: { ar: 'اكتملت واستُلمت', en: 'Mark completed' },
  SETTLED: { ar: 'تسوية الحساب', en: 'Settle' },
  CANCELLED: { ar: 'إلغاء', en: 'Cancel' },
  DISPUTED: { ar: 'فتح نزاع', en: 'Open dispute' },
};

const num = (value: unknown): number => Number(value ?? 0);

/** One request for a written reason, and what to do with the answer. */
type ReasonAsk = {
  title: string;
  placeholder: string;
  confirmLabel: string;
  optional: boolean;
  proceed: (text: string) => void;
};

/**
 * The rates on a deal are stored as fractions (0.0500 is five percent), which
 * is how they were agreed with the operator's settings. Percentages are only
 * ever a display concern.
 */
const percent = (value: unknown): string => `${(num(value) * 100).toFixed(2)}%`;

const statusLabel = (status: DealStatus): string => t()[status] ?? status;

/**
 * The drawn fulfilment path. It is wide by nature — ten states with their own
 * Arabic names — so it scrolls inside its own strip (`min-w` keeps the steps
 * legible instead of crushing them) and the parent decides whether the person
 * sees it inline or behind a disclosure.
 */
const StepsTrack: React.FC<{ steps: DealStatus[]; currentIndex: number }> = ({ steps, currentIndex }) => (
  <ol className="relative flex min-w-[680px] items-center justify-between">
    {steps.map((step, index) => {
      const isCurrent = currentIndex === index;
      const isDone = currentIndex >= index && currentIndex !== -1;

      return (
        <li key={step} className="z-10 flex flex-1 flex-col items-center text-center">
          <span
            className={`flex h-7 w-7 items-center justify-center rounded-full text-xs font-bold transition-colors ${
              isCurrent
                ? 'bg-emerald-600 text-white ring-4 ring-emerald-100'
                : isDone
                ? 'bg-emerald-500 text-white'
                : 'bg-slate-100 text-slate-400'
            }`}
            aria-current={isCurrent ? 'step' : undefined}
          >
            {index + 1}
          </span>
          <span
            className={`mt-1.5 px-1 text-[10px] leading-tight ${
              isCurrent ? 'font-bold text-emerald-800' : isDone ? 'font-medium text-slate-700' : 'text-slate-400'
            }`}
          >
            {statusLabel(step)}
          </span>
        </li>
      );
    })}
  </ol>
);

export const DealsPipelineView: React.FC<DealsPipelineProps> = ({ currentPersonaId, onOpenDispute, can }) => {
  usePlatformConfig();
  const escrowActive = isFeatureEnabled('escrow');
  const locale = getLocale();

  const [activeTab, setActiveTab] = useState<'deals' | 'requests' | 'reservations'>('deals');
  const [deals, setDeals] = useState<Deal[]>([]);
  const [requests, setRequests] = useState<PurchaseRequest[]>([]);
  const [reservations, setReservations] = useState<Reservation[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedDeal, setSelectedDeal] = useState<Deal | null>(null);
  const [pendingDealId, setPendingDealId] = useState<number | null>(null);
  const [releasingId, setReleasingId] = useState<number | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  // A list the server refused, named. `requests.length === 0` is not the same
  // fact as "the server would not answer this account", and printing the first
  // for the second tells a platform operator that nothing is waiting when the
  // real answer is "you have no organization in context to ask about".
  const [refusals, setRefusals] = useState<string[]>([]);
  // Which of the three tabs the server would not answer, so its counter can
  // read "—" instead of a "0" that would be a number nobody counted.
  const [unreadable, setUnreadable] = useState<string[]>([]);

  const [dealDraft, setDealDraft] = useState<Reservation | null>(null);
  const [deliveryMethod, setDeliveryMethod] = useState<'PICKUP' | 'SELLER_DELIVERY' | 'THIRD_PARTY_CARRIER'>('PICKUP');
  const [deliveryAddress, setDeliveryAddress] = useState('');
  /**
   * A reason the record asks for, collected on this screen instead of in
   * `window.prompt`: iOS Safari has no prompt, so on an iPhone every one of
   * those buttons read `null` and did nothing at all. The text is not sent
   * anywhere new — it goes to the same call, with the same checks, as before.
   */
  const [reasonAsk, setReasonAsk] = useState<ReasonAsk | null>(null);

  const loadData = useCallback(async () => {
    setLoading(true);
    try {
      const [dealsRes, requestsRes, reservationsRes] = await Promise.all([
        api.getDeals(),
        api.getPurchaseRequests(),
        api.getReservations({ status: 'ACTIVE' }),
      ]);

      const messages: string[] = [];
      const keys: string[] = [];
      const note = (key: string, label: string, res: { success?: boolean; error_code?: string }) => {
        if (res.success) return;
        keys.push(key);
        messages.push(
          res.error_code === 'TENANT_REQUIRED'
            ? locale === 'ar'
              ? `${label}: الخادم طلب منشأة في السياق، وهذا الحساب لا يُمثّل واحدة الآن.`
              : `${label}: the server asked for an organization in context, and this account represents none.`
            : `${label}: ${res.error_code ?? 'ERROR'}`,
        );
      };

      if (dealsRes.success) setDeals(dealsRes.data ?? []);
      if (requestsRes.success) setRequests(requestsRes.data ?? []);
      if (reservationsRes.success) setReservations(reservationsRes.data ?? []);

      note('deals', locale === 'ar' ? 'الصفقات' : 'Deals', dealsRes);
      note('requests', locale === 'ar' ? 'طلبات الشراء' : 'Purchase requests', requestsRes);
      note('reservations', locale === 'ar' ? 'الحجوزات النشطة' : 'Active holds', reservationsRes);

      setRefusals(messages);
      setUnreadable(keys);
    } catch (error) {
      console.error(error);
      // Nothing on this screen was read, so nothing may claim to have counted
      // zero either.
      setRefusals([locale === 'ar' ? 'لم يستجب الخادم لسجل الصفقات.' : 'The server did not answer for the deal record.']);
      setUnreadable(['deals', 'requests', 'reservations']);
      setActionError(locale === 'ar' ? 'تعذر تحميل سجل الصفقات.' : 'The deal record could not be loaded.');
    } finally {
      setLoading(false);
    }
  }, [locale]);

  useEffect(() => {
    loadData();
  }, [currentPersonaId, loadData]);

  /** The move itself: the same call, the same server answer as before. */
  const runTransition = async (deal: Deal, target: DealStatus, notes?: string) => {
    setPendingDealId(deal.id);
    setActionError(null);
    const response = await api.transitionDeal(deal.id, target, notes);
    setPendingDealId(null);

    if (!response.success) {
      // The server's own words, because it is the one that knows whether this
      // actor, in this state, with this capability configured, may do that.
      setActionError(response.message || (locale === 'ar' ? 'تعذر تغيير حالة الصفقة.' : 'The deal state could not be changed.'));
      return;
    }

    setNotice(response.message || undefined);
    await loadData();
    if (selectedDeal?.id === deal.id) {
      const fresh = await api.getDeal(deal.id);
      if (fresh.success && fresh.data) setSelectedDeal(fresh.data);
    }
  };

  const askTransition = (deal: Deal, target: DealStatus) => {
    const label = statusLabel(target);

    if (!REASON_REQUIRED.includes(target)) {
      void runTransition(deal, target);
      return;
    }

    setReasonAsk({
      title: locale === 'ar'
        ? `سبب "${label}" مطلوب ويُحفظ في سجل التدقيق`
        : `A reason for "${label}" is required and written to the audit log`,
      placeholder: locale === 'ar' ? 'اكتب السبب هنا' : 'Write the reason',
      confirmLabel: locale === 'ar' ? 'تسجيل السبب' : 'Record the reason',
      optional: false,
      proceed: (answer) => {
        setReasonAsk(null);
        if (answer.trim().length < 3) {
          setActionError(locale === 'ar' ? 'السبب قصير جداً؛ المطلوب سبب مكتوب لا تأكيد.' : 'A short reason is required, not just a confirmation.');
          return;
        }
        void runTransition(deal, target, answer.trim());
      },
    });
  };

  const convertReservation = async () => {
    if (!dealDraft) return;
    setPendingDealId(dealDraft.id);
    const response = await api.createDealFromReservation({
      reservation_id: dealDraft.id,
      delivery_method: deliveryMethod,
      delivery_address: deliveryAddress.trim() || undefined,
    });
    setPendingDealId(null);

    if (!response.success) {
      setActionError(response.message || (locale === 'ar' ? 'تعذر إنشاء الصفقة من الحجز.' : 'The deal could not be formed from this hold.'));
      return;
    }
    setDealDraft(null);
    setDeliveryAddress('');
    setNotice(response.message || undefined);
    setActiveTab('deals');
    await loadData();
  };

  const runRelease = async (reservation: Reservation, answer: string) => {
    setReleasingId(reservation.id);
    setActionError(null);
    const response = await api.cancelReservation(reservation.id, answer.trim() || undefined);
    setReleasingId(null);

    if (!response.success) {
      setActionError(
        response.error?.message ??
          response.message ??
          (locale === 'ar' ? 'لم يُطلَق الحجز.' : 'The hold could not be released.')
      );
      await loadData();
      return;
    }

    setNotice(
      response.message ??
        (locale === 'ar'
          ? 'أُطلقت الكمية وعادت إلى مخزون البائع المتاح.'
          : 'The quantity was released back to the seller’s available stock.')
    );
    await loadData();
  };

  /**
   * Releasing a hold the buyer no longer wants. The quantity goes back to the
   * seller's shelf, so the screen says that happened rather than "request sent".
   */
  const releaseReservation = (reservation: Reservation) => {
    setReasonAsk({
      title: locale === 'ar' ? 'التخلّي عن الكمية المحجوزة' : 'Giving this hold back',
      placeholder: locale === 'ar'
        ? 'سبب التخلّي (اختياري، يُحفظ في السجل)'
        : 'Why this hold is given up (optional, kept on record)',
      confirmLabel: locale === 'ar' ? 'إطلاق الكمية' : 'Release the stock',
      optional: true,
      proceed: (answer) => {
        setReasonAsk(null);
        void runRelease(reservation, answer);
      },
    });
  };

  /**
   * The escrow stage appears on the drawn path only when this deployment really
   * can hold funds, or when the deal's own recorded history shows it passed
   * through that state. Otherwise the stepper shows a stage the deal could never
   * reach, and a buyer reads the gap as a step somebody skipped.
   *
   * A recorded payment is NOT that evidence. `paid_at` is written when money
   * changes hands between the two parties, which is exactly what happens in a
   * deployment with escrow switched off; treating it as an escrow marker drew a
   * "funded in the escrow account" stage for funds the platform never held.
   */
  const stepsFor = (deal: Deal): DealStatus[] => {
    const reachedEscrow =
      deal.status === 'ESCROW_FUNDED'
      || Boolean(deal.escrow)
      || (deal.status_histories ?? []).some((entry) => entry.to_status === 'ESCROW_FUNDED');

    const usesEscrow = escrowActive || reachedEscrow;
    if (!usesEscrow) return FULFILMENT_STEPS;

    const steps = [...FULFILMENT_STEPS];
    steps.splice(steps.indexOf('PROCESSING'), 0, 'ESCROW_FUNDED');
    return steps;
  };

  const visibleNext = (deal: Deal): DealStatus[] =>
    (NEXT_STATES[deal.status] ?? []).filter((state) => escrowActive || !ESCROW_STATES.includes(state));

  return (
    <div className="mx-auto w-full max-w-7xl space-y-4 sm:space-y-6 px-3 sm:px-6 lg:px-8 py-6">
      <div className="flex flex-col items-stretch gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:p-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0">
          <h2 className="font-serif text-lg font-black text-slate-900">
            {locale === 'ar' ? 'مركز الصفقات وعقود التوريد' : 'Deals and supply contracts'}
          </h2>
          <p className="text-xs text-slate-500">
            {locale === 'ar'
              ? 'المسار: عرض ← طلب شراء ← موافقة البائع وحجز الكمية ← صفقة ← تسليم وفحص ← تسوية.'
              : 'Path: offer → purchase request → seller acceptance and held stock → deal → delivery and inspection → settlement.'}
          </p>
        </div>

        <div className="grid w-full grid-cols-3 gap-1 rounded-xl bg-slate-100 p-1 text-xs font-semibold sm:flex sm:w-auto sm:items-center">
          {(
            [
              ['deals', locale === 'ar' ? 'الصفقات' : 'Deals', deals.length],
              ['requests', locale === 'ar' ? 'طلبات الشراء' : 'Requests', requests.length],
              ['reservations', locale === 'ar' ? 'الحجوزات النشطة' : 'Active holds', reservations.length],
            ] as const
          ).map(([key, label, count]) => (
            <button
              key={key}
              type="button"
              onClick={() => setActiveTab(key)}
              aria-pressed={activeTab === key}
              className={`min-w-0 cursor-pointer rounded-lg px-2 py-2 text-center leading-tight transition ${
                activeTab === key ? 'bg-white font-bold text-emerald-800 shadow-2xs' : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              {unreadable.includes(key) ? `${label} (—)` : `${label} (${count})`}
            </button>
          ))}
        </div>
      </div>

      {refusals.length > 0 && (
        <div className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 sm:p-3.5 text-xs text-amber-900">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" />
          <div className="min-w-0 space-y-1">
            <p className="font-bold">
              {locale === 'ar'
                ? 'بعض قوائم هذه الشاشة لم تُحمَل؛ ما يُعرض أدناه ليس كل ما عند الخادم.'
                : 'Some lists on this screen did not load; what is below is not everything the server holds.'}
            </p>
            {refusals.map((message) => (
              <p key={message}>• {message}</p>
            ))}
          </div>
        </div>
      )}

      {actionError && (
        <div className="flex items-start justify-between gap-3 rounded-xl border border-rose-200 bg-rose-50 p-3 sm:p-3.5 text-xs text-rose-800">
          <div className="flex min-w-0 items-start gap-2">
            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-rose-600" />
            <span className="min-w-0">{actionError}</span>
          </div>
          <button type="button" className="shrink-0 cursor-pointer font-bold text-rose-500 hover:text-rose-800" onClick={() => setActionError(null)}>
            ✕
          </button>
        </div>
      )}

      {notice && (
        <div className="flex items-start justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-3 sm:p-3.5 text-xs text-emerald-900">
          <div className="flex min-w-0 items-start gap-2">
            <CheckCircle2 className="mt-0.5 h-4 w-4 shrink-0" />
            <span className="min-w-0">{notice}</span>
          </div>
          <button type="button" className="shrink-0 cursor-pointer font-bold text-emerald-600" onClick={() => setNotice(null)}>
            ✕
          </button>
        </div>
      )}

      {loading ? (
        <div className="space-y-2 py-20 text-center">
          <div className="mx-auto h-8 w-8 animate-spin rounded-full border-2 border-emerald-600 border-t-transparent" />
          <p className="text-xs text-slate-500">{locale === 'ar' ? 'جارٍ التحميل…' : 'Loading…'}</p>
        </div>
      ) : activeTab === 'requests' ? (
        <RequestsTab
          requests={requests}
          locale={locale}
          onRejected={async (message) => {
            setNotice(message);
            await loadData();
          }}
          onError={setActionError}
        />
      ) : activeTab === 'reservations' ? (
        <ReservationsTab
          reservations={reservations}
          locale={locale}
          canRelease={Boolean(can?.('requests.cancel'))}
          releasingId={releasingId}
          onRelease={releaseReservation}
          onSelect={(reservation) => {
            setDealDraft(reservation);
            setActionError(null);
          }}
        />
      ) : deals.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500 sm:p-12">
          {locale === 'ar'
            ? 'لا توجد صفقات بعد. تظهر الصفقة هنا بعد أن يقبل البائع طلب شراء ويحوَّل الحجز إلى صفقة.'
            : 'No deals yet. A deal appears once the seller accepts a purchase request and the hold is converted.'}
        </div>
      ) : (
        <div className="space-y-4">
          {deals.map((deal) => {
            const steps = stepsFor(deal);
            const currentIndex = steps.indexOf(deal.status);
            const terminal = ['CANCELLED', 'REFUNDED', 'SETTLED'].includes(deal.status);
            const nextStates = visibleNext(deal);
            /* The one move that carries the deal onward, and the rest of the same
               server-offered transitions a tap deeper. Nothing here adds or
               removes a state: `visibleNext` is the list unchanged. */
            const forward = nextStates.find((state) => state !== 'CANCELLED' && state !== 'DISPUTED');
            const otherStates = nextStates.filter((state) => state !== forward);

            return (
              <article
                key={deal.id}
                className="space-y-3.5 sm:space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs transition hover:border-slate-300"
              >
                <header className="flex flex-col items-start justify-between gap-3 border-b border-slate-100 pb-3 sm:flex-row sm:items-center">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-x-2.5 gap-y-1.5">
                      <span className="font-mono text-base font-black tracking-wide text-slate-900">{deal.deal_number}</span>
                      <span
                        className={`rounded-md border px-2.5 py-0.5 text-xs font-bold ${
                          terminal ? 'border-slate-200 bg-slate-50 text-slate-600' : 'border-emerald-200 bg-emerald-50 text-emerald-800'
                        }`}
                      >
                        {statusLabel(deal.status)}
                      </span>
                      {deal.escrow && (
                        <span className="flex items-center gap-1 rounded border border-blue-200 bg-blue-50 px-2 py-0.5 text-[11px] font-medium text-blue-700">
                          <ShieldCheck className="h-3 w-3 shrink-0" />
                          {locale === 'ar' ? `ضمان: ${deal.escrow.status}` : `Escrow: ${deal.escrow.status}`}
                        </span>
                      )}
                    </div>
                    <p className="pt-1 text-xs text-slate-500">
                      {locale === 'ar' ? 'البائع' : 'Seller'}: {deal.seller_organization?.name_ar || '—'} •{' '}
                      {locale === 'ar' ? 'المشتري' : 'Buyer'}: {deal.buyer_organization?.name_ar || '—'}
                    </p>
                  </div>

                  <div className="shrink-0 text-start sm:text-left">
                    <div className="font-serif text-lg font-black text-slate-900">
                      {formatMoney(deal.buyer_total_amount, deal.currency_code)}
                    </div>
                    <div className="text-[11px] text-slate-400">
                      {num(deal.quantity)} × {formatMoney(deal.unit_price, deal.currency_code)}
                    </div>
                  </div>
                </header>

                {/*
                  A phone cannot swipe a ten-step track with a thumb and still read
                  it, so on a handset the one thing that matters — how far along
                  the drawn path this deal is — stands alone, and the whole track
                  sits behind a single disclosure. The status itself is already on
                  the card header, so it is not printed twice.
                */}
                {currentIndex >= 0 && (
                  <div className="rounded-lg border border-emerald-100 bg-emerald-50/60 px-3 py-2 text-xs font-bold text-emerald-900 sm:hidden">
                    {locale === 'ar'
                      ? `الخطوة ${currentIndex + 1} من ${steps.length} في مسار التسليم`
                      : `Step ${currentIndex + 1} of ${steps.length} on the fulfilment path`}
                  </div>
                )}
                <div className="sm:hidden">
                  <details className="text-xs">
                    <summary className="cursor-pointer select-none font-bold text-slate-500 hover:text-slate-800">
                      {locale === 'ar' ? 'مسار التسليم الكامل' : 'Full fulfilment path'}
                    </summary>
                    <div className="mt-1 overflow-x-auto py-1 scrollbar-none">
                      <StepsTrack steps={steps} currentIndex={currentIndex} />
                    </div>
                  </details>
                </div>
                <div className="hidden overflow-x-auto py-2 scrollbar-none sm:block">
                  <StepsTrack steps={steps} currentIndex={currentIndex} />
                </div>

                {!terminal && (
                  <div className="space-y-2.5 rounded-lg bg-slate-50 p-3">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600">
                      <span className="flex min-w-0 items-center gap-1.5">
                        <Receipt className="h-4 w-4 shrink-0 text-slate-500" />
                        <span className="min-w-0">
                          {deal.warehouse_id
                            ? `${locale === 'ar' ? 'مستودع' : 'Warehouse'} #${deal.warehouse_id}`
                            : locale === 'ar'
                              ? 'لا مستودع مسجَّل على هذه الصفقة'
                              : 'No warehouse recorded'}
                        </span>
                      </span>
                      {deal.inspection_deadline_at && formatDateTime(deal.inspection_deadline_at, locale) && (
                        <span className="flex min-w-0 items-center gap-1 text-amber-700">
                          <Clock className="h-3.5 w-3.5 shrink-0" />
                          {locale === 'ar'
                            ? `مهلة الفحص حتى ${formatDateTime(deal.inspection_deadline_at, locale)}`
                            : `Inspection until ${formatDateTime(deal.inspection_deadline_at, locale)}`}
                        </span>
                      )}
                    </div>

                    {/* One move forward, and the contract; everything else the
                        server would still accept stays under one disclosure. */}
                    <div className="flex flex-wrap items-center gap-2">
                      <button
                        type="button"
                        onClick={async () => {
                          const detailed = await api.getDeal(deal.id);
                          setSelectedDeal(detailed.success && detailed.data ? detailed.data : deal);
                        }}
                        className="flex-1 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-center text-xs font-semibold text-slate-700 hover:bg-slate-50 sm:flex-none"
                      >
                        {locale === 'ar' ? 'تفاصيل العقد والفاتورة' : 'Contract and invoice'}
                      </button>

                      {forward && (
                        <button
                          key={forward}
                          type="button"
                          disabled={pendingDealId === deal.id}
                          onClick={() => askTransition(deal, forward)}
                          className="flex-1 cursor-pointer rounded-lg bg-emerald-600 px-3 py-2 text-center text-xs font-bold text-white transition hover:bg-emerald-700 disabled:opacity-50 sm:flex-none"
                        >
                          {ACTION_LABELS[forward]?.[locale] ?? statusLabel(forward)}
                        </button>
                      )}
                    </div>

                    {(otherStates.length > 0 || (deal.status === 'DELIVERED' && onOpenDispute)) && (
                      <details className="text-xs">
                        <summary className="cursor-pointer select-none font-bold text-slate-500 hover:text-slate-800">
                          {locale === 'ar' ? 'بقية الإجراءات على هذه الصفقة' : 'Other moves on this deal'}
                        </summary>
                        <div className="mt-2 flex flex-wrap items-center gap-2 border-t border-slate-200 pt-2">
                          {otherStates.map((target) => (
                            <button
                              key={target}
                              type="button"
                              disabled={pendingDealId === deal.id}
                              onClick={() => askTransition(deal, target)}
                              className={`cursor-pointer rounded-lg px-3 py-1.5 text-xs font-bold text-white transition disabled:opacity-50 ${
                                target === 'CANCELLED' ? 'bg-slate-500 hover:bg-slate-600' : 'bg-emerald-600 hover:bg-emerald-700'
                              }`}
                            >
                              {ACTION_LABELS[target]?.[locale] ?? statusLabel(target)}
                            </button>
                          ))}

                          {deal.status === 'DELIVERED' && onOpenDispute && (
                            <button
                              type="button"
                              onClick={() => onOpenDispute(deal.id)}
                              className="cursor-pointer rounded-lg border border-rose-200 bg-rose-50 px-3 py-1.5 text-xs font-semibold text-rose-700 hover:bg-rose-100"
                            >
                              {locale === 'ar' ? 'فتح نزاع' : 'Open dispute'}
                            </button>
                          )}
                        </div>
                      </details>
                    )}
                  </div>
                )}

                {terminal && deal.status === 'CANCELLED' && deal.cancellation_reason && (
                  <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
                    {locale === 'ar' ? 'سبب الإلغاء: ' : 'Cancellation reason: '}
                    {deal.cancellation_reason}
                  </p>
                )}
              </article>
            );
          })}
        </div>
      )}

      {dealDraft && (
        <Modal title={locale === 'ar' ? 'تحويل الحجز إلى صفقة' : 'Convert hold into a deal'} onClose={() => setDealDraft(null)}>
          <p className="text-xs text-slate-600">
            {locale === 'ar'
              ? 'الكمية محجوزة بالفعل لهذا الحجز؛ إنشاء الصفقة يثبّت السعر وتاريخ الحجز في لقطة لا تتغير بتغيير الإعدادات لاحقاً.'
              : 'The quantity is already held. Forming the deal freezes price and hold terms into a snapshot that later setting changes never rewrite.'}
          </p>

          <dl className="space-y-1.5 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt className="text-slate-500">{locale === 'ar' ? 'رقم الحجز' : 'Reservation'}</dt>
              <dd className="font-mono font-bold">{dealDraft.reservation_number}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt className="text-slate-500">{locale === 'ar' ? 'الكمية' : 'Quantity'}</dt>
              <dd className="font-semibold">
                {num(dealDraft.quantity)} {dealDraft.offer?.unit ?? ''}
              </dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt className="text-slate-500">{locale === 'ar' ? 'سعر الوحدة' : 'Unit price'}</dt>
              <dd className="font-semibold">{formatMoney(dealDraft.unit_price, dealDraft.currency_code)}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 border-t border-slate-200 pt-1.5">
              <dt className="text-slate-500">{locale === 'ar' ? 'قيمة البضاعة' : 'Goods value'}</dt>
              <dd className="font-black text-emerald-800">{formatMoney(dealDraft.total_amount, dealDraft.currency_code)}</dd>
            </div>
            {dealDraft.expires_at && formatDateTime(dealDraft.expires_at, locale) && (
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-amber-700">
                <dt>{locale === 'ar' ? 'ينتهي الحجز' : 'Hold expires'}</dt>
                <dd className="font-semibold">{formatDateTime(dealDraft.expires_at, locale)}</dd>
              </div>
            )}
          </dl>

          <label className="block text-xs font-bold text-slate-700">
            {locale === 'ar' ? 'طريقة التسليم' : 'Delivery method'}
            <select
              value={deliveryMethod}
              onChange={(event) => setDeliveryMethod(event.target.value as typeof deliveryMethod)}
              className="mt-1 w-full rounded-lg border border-slate-200 bg-white p-2.5 font-normal outline-none focus:ring-2 focus:ring-emerald-500/20"
            >
              <option value="PICKUP">{locale === 'ar' ? 'استلام من المستودع' : 'Buyer pickup'}</option>
              <option value="SELLER_DELIVERY">{locale === 'ar' ? 'توصيل من البائع' : 'Seller delivery'}</option>
              <option value="THIRD_PARTY_CARRIER">{locale === 'ar' ? 'ناقل خارجي' : 'Third-party carrier'}</option>
            </select>
          </label>

          {deliveryMethod !== 'PICKUP' && (
            <label className="block text-xs font-bold text-slate-700">
              {locale === 'ar' ? 'عنوان التسليم' : 'Delivery address'}
              <input
                value={deliveryAddress}
                onChange={(event) => setDeliveryAddress(event.target.value)}
                className="mt-1 w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 text-xs font-normal focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
              />
            </label>
          )}

          <div className="flex flex-col-reverse gap-2.5 pt-1 sm:flex-row sm:gap-3">
            <button
              type="button"
              onClick={() => setDealDraft(null)}
              className="w-full cursor-pointer rounded-xl bg-slate-100 py-3 text-xs font-semibold text-slate-700 hover:bg-slate-200 sm:w-auto sm:flex-1 sm:py-2.5"
            >
              {locale === 'ar' ? 'تراجع' : 'Back'}
            </button>
            <button
              type="button"
              disabled={pendingDealId === dealDraft.id}
              onClick={convertReservation}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-600 py-3 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50 sm:w-auto sm:flex-1 sm:py-2.5"
            >
              <PackageCheck className="h-4 w-4" />
              {locale === 'ar' ? 'إنشاء الصفقة' : 'Form the deal'}
            </button>
          </div>
        </Modal>
      )}

      {selectedDeal && (
        <DealDetail
          deal={selectedDeal}
          escrowActive={escrowActive}
          locale={locale}
          onClose={() => setSelectedDeal(null)}
        />
      )}

      {reasonAsk && (
        <ReasonSheet
          ask={reasonAsk}
          cancelLabel={locale === 'ar' ? 'تراجع' : 'Back'}
          onClose={() => setReasonAsk(null)}
        />
      )}
    </div>
  );
};

/* ── Purchase requests ─────────────────────────────────────────────────────── */

/**
 * The written reason, in the same sheet every dialog here uses. Closing it
 * without typing is the person changing their mind, exactly as dismissing the
 * browser prompt was, and no call is made.
 */
const ReasonSheet: React.FC<{
  ask: ReasonAsk;
  cancelLabel: string;
  onClose: () => void;
}> = ({ ask, cancelLabel, onClose }) => {
  const [text, setText] = useState('');

  return (
    <Modal title={ask.title} onClose={onClose}>
      <label className="block space-y-1">
        <span className="block text-[11px] font-bold text-slate-600">{ask.placeholder}</span>
        <textarea
          autoFocus
          rows={3}
          maxLength={500}
          value={text}
          onChange={(event) => setText(event.target.value)}
          className="w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 text-xs font-normal focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
        />
      </label>
      <div className="flex flex-col-reverse gap-2.5 sm:flex-row">
        <button
          type="button"
          onClick={onClose}
          className="w-full cursor-pointer rounded-xl bg-slate-100 py-3 text-xs font-semibold text-slate-700 hover:bg-slate-200 sm:flex-1 sm:py-2.5"
        >
          {cancelLabel}
        </button>
        <button
          type="button"
          disabled={!ask.optional && text.trim().length === 0}
          onClick={() => ask.proceed(text)}
          className="w-full cursor-pointer rounded-xl bg-emerald-600 py-3 text-xs font-bold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-1 sm:py-2.5"
        >
          {ask.confirmLabel}
        </button>
      </div>
    </Modal>
  );
};

const REQUEST_STATUS_CHIP: Record<PurchaseRequestStatus, { ar: string; en: string; style: string }> = {
  REQUESTED: {
    ar: 'بانتظار قرار البائع — لا حجز بعد',
    en: 'Awaiting the seller — nothing held yet',
    style: 'bg-amber-100 text-amber-800',
  },
  PROPOSED: {
    ar: 'البائع اقترح شروطاً — بانتظار المشتري',
    en: 'Seller countered — awaiting the buyer',
    style: 'bg-blue-100 text-blue-800',
  },
  ACCEPTED: {
    ar: 'مقبول: الكمية محجوزة',
    en: 'Accepted: the quantity is held',
    style: 'bg-emerald-100 text-emerald-800',
  },
  REJECTED: { ar: 'مرفوض', en: 'Declined', style: 'bg-rose-100 text-rose-800' },
  CANCELLED: { ar: 'مسحوب', en: 'Withdrawn', style: 'bg-slate-100 text-slate-600' },
};

const RequestsTab: React.FC<{
  requests: PurchaseRequest[];
  locale: 'ar' | 'en';
  onRejected: (message: string) => void;
  onError: (message: string) => void;
}> = ({ requests, locale, onRejected, onError }) => {
  const [busy, setBusy] = useState<number | null>(null);
  const [proposalFor, setProposalFor] = useState<number | null>(null);
  const [proposalQty, setProposalQty] = useState('');
  const [proposalPrice, setProposalPrice] = useState('');
  const [proposalNotes, setProposalNotes] = useState('');
  const [reasonAsk, setReasonAsk] = useState<ReasonAsk | null>(null);

  if (requests.length === 0) {
    return (
      <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500 sm:p-12">
        {locale === 'ar' ? 'لا توجد طلبات شراء.' : 'No purchase requests.'}
      </div>
    );
  }

  /** The server answers refusals at `error.message`; `message` alone hides the reason. */
  const refusal = (response: { message?: string; error?: { message?: string } }, fallback: string) =>
    response.error?.message ?? response.message ?? fallback;

  /** The decision itself: same endpoints, same refusal handling as before. */
  const runDecide = async (id: number, kind: 'accept' | 'reject', reason?: string) => {
    setBusy(id);
    const response = kind === 'accept' ? await api.acceptPurchaseRequest(id) : await api.rejectPurchaseRequest(id, reason ?? '');
    setBusy(null);

    if (!response.success) {
      onError(refusal(response, locale === 'ar' ? 'تعذر تنفيذ القرار.' : 'The decision could not be recorded.'));
      return;
    }
    onRejected(response.message ?? '');
  };

  const decide = (id: number, kind: 'accept' | 'reject') => {
    if (kind === 'accept') {
      void runDecide(id, 'accept');
      return;
    }

    setReasonAsk({
      title: locale === 'ar' ? 'رفض طلب الشراء' : 'Declining this request',
      placeholder: locale === 'ar'
        ? 'سبب الرفض (يُرسل للمشتري ويُحفظ في السجل)'
        : 'Reason for rejecting (sent to the buyer, kept on record)',
      confirmLabel: locale === 'ar' ? 'تسجيل الرفض' : 'Record the rejection',
      optional: false,
      proceed: (answer) => {
        setReasonAsk(null);
        const reason = answer.trim();
        if (reason.length < 3) {
          onError(locale === 'ar' ? 'الرفض يحتاج سبباً مكتوباً.' : 'A rejection needs a written reason.');
          return;
        }
        void runDecide(id, 'reject', reason);
      },
    });
  };

  /** Seller counter-offer: records proposed terms and leaves the buyer's ask intact. */
  const sendProposal = async (request: PurchaseRequest) => {
    const quantity = proposalQty.trim() ? Number(proposalQty) : undefined;
    const unitPrice = proposalPrice.trim() ? Number(proposalPrice) : undefined;
    if (quantity === undefined && unitPrice === undefined) {
      onError(
        locale === 'ar'
          ? 'اكتب الكمية أو السعر المقترح؛ اقتراحٌ لا يغيّر شيئاً سيرفضه الخادم.'
          : 'Give a quantity or a price. A proposal that changes nothing will be refused.'
      );
      return;
    }
    if (quantity !== undefined && !(quantity >= 0.01)) {
      onError(locale === 'ar' ? 'الكمية المقترحة يجب أن تكون أكبر من صفر.' : 'The proposed quantity must be above zero.');
      return;
    }
    if (unitPrice !== undefined && !(unitPrice >= 0.0001)) {
      onError(locale === 'ar' ? 'السعر المقترح يجب أن يكون أكبر من صفر.' : 'The proposed price must be above zero.');
      return;
    }

    setBusy(request.id);
    const response = await api.proposePurchaseRequest(
      request.id,
      {
        ...(quantity !== undefined ? { quantity } : {}),
        ...(unitPrice !== undefined ? { unit_price: unitPrice } : {}),
      },
      proposalNotes.trim() || undefined
    );
    setBusy(null);

    if (!response.success) {
      onError(refusal(response, locale === 'ar' ? 'تعذّر تسجيل الاقتراح.' : 'The proposal could not be recorded.'));
      return;
    }
    setProposalFor(null);
    setProposalQty('');
    setProposalPrice('');
    setProposalNotes('');
    onRejected(response.message ?? '');
  };

  /** Buyer adopts the counter-offer, which puts the ask back on the seller. */
  const adoptProposal = async (id: number) => {
    setBusy(id);
    const response = await api.acceptProposal(id);
    setBusy(null);
    if (!response.success) {
      onError(refusal(response, locale === 'ar' ? 'تعذّر قبول اقتراح البائع.' : 'The proposal could not be adopted.'));
      return;
    }
    onRejected(response.message ?? '');
  };

  const runWithdraw = async (id: number, answer: string) => {
    setBusy(id);
    const response = await api.cancelPurchaseRequest(id, answer.trim() || undefined);
    setBusy(null);
    if (!response.success) {
      onError(refusal(response, locale === 'ar' ? 'تعذّر سحب الطلب.' : 'The request could not be withdrawn.'));
      return;
    }
    onRejected(response.message ?? '');
  };

  const withdraw = (id: number) => {
    setReasonAsk({
      title: locale === 'ar' ? 'سحب طلب الشراء' : 'Withdrawing this ask',
      placeholder: locale === 'ar'
        ? 'سبب السحب (اختياري، يُحفظ في السجل)'
        : 'Why the ask is withdrawn (optional, kept on record)',
      confirmLabel: locale === 'ar' ? 'سحب الطلب' : 'Withdraw the request',
      optional: true,
      proceed: (answer) => {
        setReasonAsk(null);
        void runWithdraw(id, answer);
      },
    });
  };

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      {requests.map((request) => {
        // Which side of the ask this organization is on decides which of the
        // server's actions it may even attempt. The backend re-checks the party
        // and the permission on every call; this only stops offering a button
        // that belongs to the other side of the table.
        const organizationId = api.getTenantId();
        const isBuyer = organizationId !== null && Number(request.buyer_organization_id) === organizationId;
        const isSeller = organizationId !== null && Number(request.seller_organization_id) === organizationId;
        const chip = REQUEST_STATUS_CHIP[request.status];
        return (
          <article key={request.id} className="space-y-3.5 sm:space-y-4 rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0 space-y-1.5">
                <span className="block font-mono text-xs font-bold text-slate-900">{request.purchase_request_number}</span>
                <span
                  className={`inline-block max-w-full rounded-full px-2 py-0.5 text-[10px] font-bold leading-relaxed ${
                    chip?.style ?? 'bg-slate-100 text-slate-600'
                  }`}
                >
                  {chip ? (locale === 'ar' ? chip.ar : chip.en) : request.status}
                </span>
                <p className="text-xs text-slate-500 line-clamp-2" title={request.offer?.title_ar ?? undefined}>
                  {request.offer?.title_ar || (locale === 'ar' ? 'عرض محذوف أو غير متاح' : 'Offer removed or unavailable')}
                </p>
              </div>

              <div className="shrink-0 text-start sm:text-left">
                <div className="font-serif text-sm font-black text-emerald-800">
                  {formatMoney(num(request.requested_quantity) * num(request.requested_price), request.currency_code)}
                </div>
                <div className="text-[10px] text-slate-400">
                  {num(request.requested_quantity)} {request.unit} × {formatMoney(request.requested_price, request.currency_code)}
                </div>
              </div>
            </div>

            {request.proposed_terms && (
              <div className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900">
                {locale === 'ar' ? 'البائع اقترح بدلاً من ذلك: ' : 'The seller proposed instead: '}
                {request.proposed_terms.quantity != null && `${request.proposed_terms.quantity} ${request.unit}`}
                {request.proposed_terms.unit_price != null &&
                  ` × ${formatMoney(request.proposed_terms.unit_price, request.proposed_terms.currency_code ?? request.currency_code)}`}
              </div>
            )}

            {request.notes && (
              <p className="border-t border-slate-100 pt-2 text-[11px] italic text-slate-500">“{request.notes}”</p>
            )}

            {proposalFor === request.id && (
              <div className="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                <p className="text-[11px] font-bold text-slate-700">
                  {locale === 'ar'
                    ? 'شروط بديلة تُرسل للمشتري (سؤاله الأصلي يبقى كما هو حتى يقبلها)'
                    : 'Terms sent back to the buyer (their ask stays as it was until they adopt these)'}
                </p>
                <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                  <label className="space-y-1 min-w-0">
                    <span className="block text-[10px] font-bold text-slate-600">
                      {locale === 'ar'
                        ? `الكمية المقترحة (المطلوبة: ${num(request.requested_quantity)} ${request.unit})`
                        : `Proposed quantity (asked: ${num(request.requested_quantity)} ${request.unit})`}
                    </span>
                    <input
                      type="number"
                      inputMode="decimal"
                      step="0.01"
                      value={proposalQty}
                      onChange={(e) => setProposalQty(e.target.value)}
                      className="w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs"
                    />
                  </label>
                  <label className="space-y-1 min-w-0">
                    <span className="block text-[10px] font-bold text-slate-600">
                      {locale === 'ar'
                        ? `سعر الوحدة المقترح (المطلوب: ${formatMoney(request.requested_price, request.currency_code)})`
                        : `Proposed unit price (asked: ${formatMoney(request.requested_price, request.currency_code)})`}
                    </span>
                    <input
                      type="number"
                      inputMode="decimal"
                      step="0.0001"
                      value={proposalPrice}
                      onChange={(e) => setProposalPrice(e.target.value)}
                      className="w-full min-w-0 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs"
                    />
                  </label>
                </div>
                <label className="block space-y-1">
                  <span className="block text-[10px] font-bold text-slate-600">
                    {locale === 'ar' ? 'ملاحظة تُحفظ مع الطلب (اختياري)' : 'Note kept on the request (optional)'}
                  </span>
                  <input
                    type="text"
                    maxLength={500}
                    value={proposalNotes}
                    onChange={(e) => setProposalNotes(e.target.value)}
                    className="w-full rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-xs"
                  />
                </label>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => sendProposal(request)}
                    className="flex-1 basis-full cursor-pointer rounded-lg bg-blue-600 py-2 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50 sm:basis-auto"
                  >
                    {locale === 'ar' ? 'إرسال الاقتراح' : 'Send the proposal'}
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setProposalFor(null);
                      setProposalQty('');
                      setProposalPrice('');
                      setProposalNotes('');
                    }}
                    className="cursor-pointer rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100"
                  >
                    {locale === 'ar' ? 'إلغاء' : 'Cancel'}
                  </button>
                </div>
              </div>
            )}

            <div className="border-t border-slate-100 pt-3">
              {isSeller && request.status === 'REQUESTED' && (
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => decide(request.id, 'accept')}
                    className="flex-1 basis-full cursor-pointer rounded-lg bg-emerald-600 py-2 text-xs font-bold text-white hover:bg-emerald-700 disabled:opacity-50 sm:basis-auto"
                  >
                    {locale === 'ar' ? 'قبول وحجز الكمية' : 'Accept and hold the stock'}
                  </button>
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => {
                      setProposalFor(request.id);
                      setProposalQty(String(num(request.requested_quantity)));
                      setProposalPrice(String(num(request.requested_price)));
                      setProposalNotes('');
                    }}
                    className="flex-1 cursor-pointer rounded-lg bg-blue-50 px-3 py-2 text-xs font-bold text-blue-800 hover:bg-blue-100"
                  >
                    {locale === 'ar' ? 'اقتراح شروط' : 'Counter'}
                  </button>
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => decide(request.id, 'reject')}
                    className="flex-1 cursor-pointer rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-rose-50 hover:text-rose-700"
                  >
                    {locale === 'ar' ? 'رفض' : 'Decline'}
                  </button>
                </div>
              )}

              {isBuyer && request.status === 'PROPOSED' && (
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => adoptProposal(request.id)}
                    className="flex-1 basis-full cursor-pointer rounded-lg bg-blue-600 py-2 text-xs font-bold text-white hover:bg-blue-700 disabled:opacity-50 sm:basis-auto"
                  >
                    {locale === 'ar' ? 'قبول شروط البائع' : 'Adopt the seller’s terms'}
                  </button>
                  <button
                    type="button"
                    disabled={busy === request.id}
                    onClick={() => withdraw(request.id)}
                    className="flex-1 cursor-pointer rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-rose-50 hover:text-rose-700"
                  >
                    {locale === 'ar' ? 'سحب الطلب' : 'Withdraw'}
                  </button>
                </div>
              )}

              {isBuyer && request.status === 'REQUESTED' && (
                <button
                  type="button"
                  disabled={busy === request.id}
                  onClick={() => withdraw(request.id)}
                  className="w-full cursor-pointer rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-rose-50 hover:text-rose-700"
                >
                  {locale === 'ar' ? 'سحب الطلب' : 'Withdraw the ask'}
                </button>
              )}

              {!isBuyer && !isSeller && (
                <p className="text-[11px] text-slate-500">
                  {locale === 'ar'
                    ? 'منشأتك ليست الطرف المشتري ولا البائع في هذا الطلب، فلا إجراء لك عليه.'
                    : 'Your organization is neither the buyer nor the seller here, so there is nothing for it to do.'}
                </p>
              )}

              {(request.status === 'ACCEPTED' || request.status === 'REJECTED' || request.status === 'CANCELLED') && (
                <p className="text-[11px] text-slate-500">
                  {request.status === 'ACCEPTED'
                    ? locale === 'ar'
                      ? 'الكمية صارت محجوزة؛ تحويلها إلى صفقة من تبويب الحجوزات.'
                      : 'The quantity is held; convert it to a deal from the holds tab.'
                    : locale === 'ar'
                      ? 'انتهى مسار هذا الطلب.'
                      : 'This request is closed.'}
                </p>
              )}
            </div>
          </article>
        );
      })}

      {reasonAsk && (
        <ReasonSheet
          ask={reasonAsk}
          cancelLabel={locale === 'ar' ? 'تراجع' : 'Back'}
          onClose={() => setReasonAsk(null)}
        />
      )}
    </div>
  );
};

/* ── Active holds ──────────────────────────────────────────────────────────── */

const ReservationsTab: React.FC<{
  reservations: Reservation[];
  locale: 'ar' | 'en';
  canRelease: boolean;
  releasingId: number | null;
  onRelease: (reservation: Reservation) => void;
  onSelect: (reservation: Reservation) => void;
}> = ({ reservations, locale, canRelease, releasingId, onRelease, onSelect }) => {
  if (reservations.length === 0) {
    return (
      <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500 sm:p-12">
        {locale === 'ar'
          ? 'لا توجد حجوزات نشطة. الحجز يبدأ حين يقبل البائع طلب شراء مرسلاً إليه من عرض أو من مطابقة.'
          : 'No active holds. A hold begins when the seller accepts a purchase request raised against one of their offers or matches.'}
      </div>
    );
  }

  return (
    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
      {reservations.map((reservation) => (
        <article key={reservation.id} className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 sm:p-5 shadow-2xs">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0 space-y-0.5">
              <span className="block font-mono text-xs font-bold text-slate-900">{reservation.reservation_number}</span>
              <p className="text-xs text-slate-500 line-clamp-2" title={reservation.offer?.title_ar ?? undefined}>
                {reservation.offer?.title_ar || '—'}
              </p>
            </div>
            <span className="shrink-0 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-800">
              {locale === 'ar' ? 'كمية محجوزة' : 'Stock held'}
            </span>
          </div>

          <dl className="space-y-1 text-xs text-slate-600">
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt>{locale === 'ar' ? 'الكمية' : 'Quantity'}</dt>
              <dd className="font-semibold">
                {num(reservation.quantity)} {reservation.offer?.unit ?? ''}
              </dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt>{locale === 'ar' ? 'القيمة' : 'Value'}</dt>
              <dd className="font-semibold">{formatMoney(reservation.total_amount, reservation.currency_code)}</dd>
            </div>
            <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5">
              <dt>{locale === 'ar' ? 'تفضيل التسليم' : 'Delivery preference'}</dt>
              <dd className="font-semibold">
                {reservation.delivery_preference === 'DELIVERY'
                  ? locale === 'ar'
                    ? 'توصيل'
                    : 'Delivery'
                  : locale === 'ar'
                    ? 'استلام'
                    : 'Pickup'}
              </dd>
            </div>
            {reservation.expires_at && formatDateTime(reservation.expires_at, locale) && (
              <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-amber-700">
                <dt className="flex items-center gap-1">
                  <Clock className="h-3.5 w-3.5 shrink-0" />
                  {locale === 'ar' ? 'ينتهي' : 'Expires'}
                </dt>
                <dd className="font-semibold">{formatDateTime(reservation.expires_at, locale)}</dd>
              </div>
            )}
          </dl>

          <button
            type="button"
            onClick={() => onSelect(reservation)}
            className="flex w-full cursor-pointer items-center justify-center gap-1.5 rounded-lg bg-emerald-600 py-2 text-xs font-bold text-white hover:bg-emerald-700"
          >
            <Truck className="h-3.5 w-3.5" />
            {locale === 'ar' ? 'تحويل الحجز إلى صفقة' : 'Form a deal from this hold'}
            <ChevronRight className="h-3.5 w-3.5" />
          </button>

          {canRelease ? (
            <button
              type="button"
              disabled={releasingId !== null}
              onClick={() => onRelease(reservation)}
              className="w-full cursor-pointer rounded-lg border border-slate-300 bg-white py-2 text-xs font-bold text-slate-700 hover:bg-slate-100 disabled:cursor-wait disabled:opacity-50"
            >
              {releasingId === reservation.id
                ? locale === 'ar'
                  ? 'جارٍ الإطلاق…'
                  : 'Releasing…'
                : locale === 'ar'
                  ? 'التخلّي عن الكمية وإعادتها للبائع'
                  : 'Give this hold back'}
            </button>
          ) : (
            <p className="text-[10px] leading-relaxed text-slate-400">
              {locale === 'ar'
                ? 'إطلاق الكمية المحجوزة ليس ضمن صلاحية هذه الجلسة.'
                : 'Releasing held stock is outside this session’s permissions.'}
            </p>
          )}
        </article>
      ))}
    </div>
  );
};

/* ── Deal detail ───────────────────────────────────────────────────────────── */

const DealDetail: React.FC<{
  deal: Deal;
  escrowActive: boolean;
  locale: 'ar' | 'en';
  onClose: () => void;
}> = ({ deal, escrowActive, locale, onClose }) => {
  const vat = locale === 'ar' ? 'الرقم الضريبي' : 'Tax number';

  return (
    <Modal
      title={
        <div>
          <div className="flex items-center gap-2">
            <span className="font-mono text-base font-black text-slate-900">{deal.deal_number}</span>
            <span className="rounded border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-xs font-bold text-emerald-800">
              {statusLabel(deal.status)}
            </span>
          </div>
          <p className="pt-0.5 text-xs text-slate-500">
            {locale === 'ar' ? 'لقطة تجارية مثبتة وقت إنشاء الصفقة' : 'Commercial snapshot frozen when the deal was formed'}
          </p>
        </div>
      }
      onClose={onClose}
      wide
    >
      <div className="grid grid-cols-1 gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3.5 text-xs sm:grid-cols-2">
        <div className="space-y-1">
          <span className="text-slate-400">{locale === 'ar' ? 'الطرف الأول (البائع)' : 'First party (seller)'}</span>
          <p className="font-bold text-slate-900">{deal.seller_organization?.name_ar || '—'}</p>
          <p className="text-[11px] text-slate-500">
            {vat}: {deal.seller_organization?.vat_number || (locale === 'ar' ? 'غير مسجَّل' : 'not recorded')}
          </p>
        </div>
        <div className="space-y-1">
          <span className="text-slate-400">{locale === 'ar' ? 'الطرف الثاني (المشتري)' : 'Second party (buyer)'}</span>
          <p className="font-bold text-slate-900">{deal.buyer_organization?.name_ar || '—'}</p>
          <p className="text-[11px] text-slate-500">
            {vat}: {deal.buyer_organization?.vat_number || (locale === 'ar' ? 'غير مسجَّل' : 'not recorded')}
          </p>
        </div>
      </div>

      {deal.items && deal.items.length > 0 && (
        <div className="space-y-2">
          <h4 className="text-xs font-bold text-slate-800">
            {locale === 'ar' ? 'أصناف الصفقة (لقطة ثابتة)' : 'Deal lines (immutable snapshot)'}
          </h4>
          <div className="overflow-hidden rounded-xl border border-slate-200">
            <table className="w-full min-w-[520px] text-right text-xs">
              <thead className="bg-slate-100 text-slate-600">
                <tr>
                  <th className="p-2.5 font-semibold">{locale === 'ar' ? 'المنتج' : 'Product'}</th>
                  <th className="p-2.5 font-semibold">SKU</th>
                  <th className="p-2.5 font-semibold">{locale === 'ar' ? 'الكمية' : 'Qty'}</th>
                  <th className="p-2.5 font-semibold">{locale === 'ar' ? 'سعر الوحدة' : 'Unit'}</th>
                  <th className="p-2.5 font-semibold">{locale === 'ar' ? 'الإجمالي' : 'Total'}</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {deal.items.map((item) => (
                  <tr key={item.id}>
                    <td className="p-2.5 font-bold text-slate-900">{item.snapshot_product_name || '—'}</td>
                    <td className="p-2.5 font-mono text-slate-500">{item.snapshot_sku || '—'}</td>
                    <td className="p-2.5">
                      {num(item.quantity)} {item.snapshot_unit_name ?? ''}
                    </td>
                    <td className="p-2.5">{formatMoney(item.unit_price, deal.currency_code)}</td>
                    <td className="p-2.5 font-bold text-emerald-800">{formatMoney(item.subtotal_amount, deal.currency_code)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="space-y-1.5 rounded-xl border border-slate-200 bg-slate-50 p-3 text-xs sm:p-4">
        <Row label={locale === 'ar' ? 'قيمة البضاعة' : 'Goods value'} value={formatMoney(deal.base_amount, deal.currency_code)} />
        <Row
          label={
            locale === 'ar'
              ? `الضريبة (${percent(deal.tax_rate_snapshot)})`
              : `Tax (${percent(deal.tax_rate_snapshot)})`
          }
          value={formatMoney(deal.tax_amount, deal.currency_code)}
        />
        <Row
          label={
            locale === 'ar'
              ? `عمولة المنصة (${percent(deal.platform_fee_rate_snapshot)})`
              : `Platform fee (${percent(deal.platform_fee_rate_snapshot)})`
          }
          value={formatMoney(deal.platform_fee_amount, deal.currency_code)}
        />
        {num(deal.delivery_fee_amount) > 0 && (
          <Row
            label={locale === 'ar' ? 'أجرة التوصيل' : 'Delivery fee'}
            value={formatMoney(deal.delivery_fee_amount, deal.currency_code)}
          />
        )}
        <div className="flex items-center justify-between border-t border-slate-200 pt-2 text-sm font-black text-slate-900">
          <span>{locale === 'ar' ? 'الإجمالي على المشتري' : 'Buyer total'}</span>
          <span className="font-serif text-emerald-700">{formatMoney(deal.buyer_total_amount, deal.currency_code)}</span>
        </div>
        <div className="flex items-center justify-between text-[11px] text-slate-500">
          <span>{locale === 'ar' ? 'صافي البائع' : 'Seller net'}</span>
          <span>{formatMoney(deal.seller_net_amount, deal.currency_code)}</span>
        </div>
        {deal.exchange_rate_snapshot && deal.base_currency && deal.base_currency !== deal.currency_code && (
          <div className="flex items-center justify-between text-[11px] text-slate-500">
            <span>{locale === 'ar' ? `سعر الصرف المثبَّت إلى ${deal.base_currency}` : `Frozen rate to ${deal.base_currency}`}</span>
            <span className="font-mono">
              {num(deal.exchange_rate_snapshot)} • {deal.rate_source || '—'}
            </span>
          </div>
        )}
      </div>

      {/*
        Money. There is no payment endpoint on this platform: HASSAL does not
        hold a customer's money unless the escrow capability is switched on and
        wired to a real provider. So this block reports what the record says and
        never offers to type a transfer into existence.
      */}
      <div className="space-y-2 rounded-xl border border-slate-200 p-3.5">
        <h4 className="flex items-center gap-2 text-xs font-bold text-slate-800">
          <HandCoins className="h-4 w-4 text-slate-500" />
          {locale === 'ar' ? 'السداد' : 'Settlement'}
        </h4>

        {!escrowActive && (
          <p className="text-[11px] leading-relaxed text-slate-600">
            {locale === 'ar'
              ? 'في هذه التهيئة لا تحتفظ المنصة بأموال العملاء: تتم التسوية بين المنشأتين، وتُسجَّل هنا فقط حالات الصفقة التي يقررها الطرفان. لا تعرض هذه الشاشة رقم حساب ولا تؤكد دفعاً لم تصله رسالة تأكيد من مصدر حقيقي.'
              : 'In this configuration the platform holds no customer money: settlement happens between the two businesses, and only the states the parties record appear here. This screen shows no bank account and never confirms a payment a real source has not reported.'}
          </p>
        )}

        {escrowActive && deal.escrow && (
          <p className="text-[11px] text-blue-800">
            {locale === 'ar'
              ? `حساب الضمان ${deal.escrow.escrow_number} — حالته ${deal.escrow.status}`
              : `Escrow ${deal.escrow.escrow_number} — ${deal.escrow.status}`}
          </p>
        )}

        {(deal.payments ?? []).length > 0 ? (
          <ul className="space-y-1 text-[11px] text-slate-600">
            {deal.payments!.map((payment) => (
              <li key={payment.id} className="flex flex-wrap items-center justify-between gap-x-2 gap-y-0.5 border-t border-slate-100 pt-1">
                <span dir="ltr" className="min-w-0 font-mono">{payment.payment_number}</span>
                <span className="min-w-0">{formatMoney(payment.amount, payment.currency_code)}</span>
                <span dir="ltr" className={`min-w-0 text-left ${payment.status === 'SUCCEEDED' ? 'font-bold text-emerald-700' : 'text-amber-700'}`}>
                  {payment.status}
                  {payment.verified_at && formatDateTime(payment.verified_at, locale)
                    ? ` • ${formatDateTime(payment.verified_at, locale)}`
                    : ''}
                </span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-[11px] text-slate-500">
            {locale === 'ar' ? 'لا مدفوعات مسجَّلة على هذه الصفقة.' : 'No payments recorded on this deal.'}
          </p>
        )}
      </div>

      {deal.status_histories && deal.status_histories.length > 0 && (
        <div className="space-y-2">
          <h4 className="text-xs font-bold text-slate-800">{locale === 'ar' ? 'سجل الحالات' : 'Status history'}</h4>
          <ul className="max-h-44 space-y-1.5 overflow-y-auto rounded-xl border border-slate-200 bg-slate-50 p-3">
            {deal.status_histories.map((entry) => (
              <li key={entry.id} className="flex flex-wrap items-start justify-between gap-x-3 gap-y-0.5 text-[11px] text-slate-600">
                <span className="min-w-0 flex-1">
                  {statusLabel(entry.from_status as DealStatus)} ← {statusLabel(entry.to_status as DealStatus)}
                  {entry.notes ? ` — ${entry.notes}` : ''}
                </span>
                <span className="shrink-0 text-[10px] text-slate-400">
                  {formatDateTime(entry.created_at, locale) || entry.created_at}
                </span>
              </li>
            ))}
          </ul>
        </div>
      )}
    </Modal>
  );
};

const Row: React.FC<{ label: string; value: string }> = ({ label, value }) => (
  <div className="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-0.5 text-slate-600">
    <span className="min-w-0">{label}</span>
    <span className="shrink-0 font-semibold text-slate-900">{value}</span>
  </div>
);

/**
 * The sheet every dialog on this screen rides in.
 *
 * On a phone it is a bottom sheet — the place a thumb already is — and its own
 * scroll, because a deal contract is taller than any handset. Escape and a
 * backdrop tap close it, the same as the ✕, so nothing on this screen traps
 * the person inside it.
 */
const Modal: React.FC<{
  title: React.ReactNode;
  onClose: () => void;
  wide?: boolean;
  children: React.ReactNode;
}> = ({ title, onClose, wide, children }) => {
  useEffect(() => {
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [onClose]);

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 backdrop-blur-xs sm:items-center sm:p-4"
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <div
        className={`max-h-[92dvh] w-full overflow-y-auto overscroll-contain rounded-t-2xl border border-slate-200 bg-white pb-[env(safe-area-inset-bottom)] shadow-2xl sm:rounded-2xl sm:pb-0 ${
          wide ? 'sm:max-w-2xl' : 'sm:max-w-md'
        }`}
        role="dialog"
        aria-modal="true"
      >
        <div className="sticky top-0 z-10 flex items-start justify-between gap-3 border-b border-slate-100 bg-white p-4 sm:p-6">
          <div className="min-w-0">{title}</div>
          <button
            type="button"
            onClick={onClose}
            aria-label={getLocale() === 'ar' ? 'إغلاق' : 'Close'}
            className="-m-1 shrink-0 cursor-pointer p-1 text-lg font-bold text-slate-400 hover:text-slate-700"
          >
            ✕
          </button>
        </div>
        <div className="space-y-4 p-4 sm:p-6">{children}</div>
      </div>
    </div>
  );
};

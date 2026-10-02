import React, { useState, useEffect, useCallback } from 'react';
import {
  Clock,
  CheckCircle2,
  AlertTriangle,
  Receipt,
  ChevronRight,
  PackageCheck,
  Truck,
  QrCode,
  ScanLine,
  Copy,
  Printer,
} from 'lucide-react';
import { api } from '../lib/api';
import { CameraCapture } from './CameraCapture';
import { formatMoney, usePlatformConfig } from '../lib/config';
import { formatDateTime, getLocale } from '../lib/i18n';
import type { Deal, DealStatus, HandoverView, PurchaseRequest, PurchaseRequestStatus, QrSymbol, Reservation } from '../types';

interface DealsPipelineProps {
  /**
   * The active organization's id, as the session holds it — an account with no
   * organization in context passes an empty string, which on this screen means
   * "nobody's side of the trade", and the platform sees every move it can rule.
   *
   * The side matters because one endpoint serves both counterparties: the state
   * machine asks not "may this account do that" but "is this account the buyer or
   * the seller of *this* order", and a button that ignores the difference is a
   * refusal waiting to be clicked.
   */
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
 * The words follow the server's vocabulary exactly, which now means twelve names
 * instead of sixteen: `PENDING_PAYMENT`, `ESCROW_FUNDED`, `SETTLED` and
 * `REFUNDED` were deleted from the state machine rather than hidden here, because
 * HASSAL never holds the price of a trade. A state that cannot be reached must not
 * have a label waiting for it — that is how a buyer ends up being told "مسددة"
 * about money the platform has never seen.
 */
const AR: Record<DealStatus, string> = {
  DRAFT: 'مسودة',
  PENDING_SELLER_APPROVAL: 'بانتظار موافقة البائع',
  ACCEPTED: 'قبلها البائع',
  PROCESSING: 'قيد التجهيز',
  READY_FOR_PICKUP: 'جاهزة للاستلام',
  DISPATCHED: 'تم الشحن',
  DELIVERED: 'تم التسليم',
  INSPECTION: 'داخل مهلة الفحص',
  COMPLETED: 'مكتملة',
  CANCELLED: 'ملغاة',
  DISPUTED: 'متنازَع عليها',
  RESOLVED: 'تم حل النزاع',
};

const EN: Record<DealStatus, string> = {
  DRAFT: 'Draft',
  PENDING_SELLER_APPROVAL: 'Awaiting seller approval',
  ACCEPTED: 'Accepted by the seller',
  PROCESSING: 'Preparing',
  READY_FOR_PICKUP: 'Ready for pickup',
  DISPATCHED: 'Dispatched',
  DELIVERED: 'Delivered',
  INSPECTION: 'In inspection window',
  COMPLETED: 'Completed',
  CANCELLED: 'Cancelled',
  DISPUTED: 'Disputed',
  RESOLVED: 'Dispute resolved',
};

/**
 * The path an order walks, drawn as a track.
 *
 * It is the machine's own forward line, with the two short-cuts left out of the
 * picture rather than denied: `ACCEPTED → DISPATCHED` and
 * `DELIVERED → COMPLETED` are legal moves the buttons below offer, and drawing
 * every legal edge would turn a straight line into a web nobody can read.
 */
const FULFILMENT_STEPS: DealStatus[] = [
  'PENDING_SELLER_APPROVAL',
  'ACCEPTED',
  'PROCESSING',
  'READY_FOR_PICKUP',
  'DISPATCHED',
  'DELIVERED',
  'INSPECTION',
  'COMPLETED',
];

/**
 * Mirrors `DealStateMachineService::ALLOWED_TRANSITIONS` so the screen offers the
 * moves that exist. This is presentation only — the server re-decides every
 * transition, including whether this actor is the buyer or the seller, and it is
 * the scan of the delivery code, not this list, that decides whether an order can
 * be closed.
 */
const NEXT_STATES: Partial<Record<DealStatus, DealStatus[]>> = {
  DRAFT: ['PENDING_SELLER_APPROVAL', 'CANCELLED'],
  PENDING_SELLER_APPROVAL: ['ACCEPTED', 'CANCELLED'],
  ACCEPTED: ['PROCESSING', 'READY_FOR_PICKUP', 'DISPATCHED', 'CANCELLED', 'DISPUTED'],
  PROCESSING: ['READY_FOR_PICKUP', 'DISPATCHED', 'CANCELLED', 'DISPUTED'],
  READY_FOR_PICKUP: ['DELIVERED', 'CANCELLED', 'DISPUTED'],
  DISPATCHED: ['DELIVERED', 'DISPUTED'],
  DELIVERED: ['INSPECTION', 'COMPLETED', 'DISPUTED'],
  INSPECTION: ['COMPLETED', 'DISPUTED'],
  COMPLETED: [],
  DISPUTED: ['RESOLVED'],
  RESOLVED: ['COMPLETED', 'CANCELLED'],
  CANCELLED: [],
};

/**
 * Who may make each move, and what the move asserts — the web's copy of
 * `DealStateMachineService::TRANSITION_RULES`.
 *
 * The route cannot express this and neither can a permission list, because
 * `deals.confirm` is held by a buyer's owner and a seller's owner alike, and the
 * same endpoint serves both. What separates them is which side of *this* order
 * their organization sits on, and that is a fact about the row, not about the
 * session. So the screen filters by it — to spare a person a refusal they could
 * have read coming — while the server keeps deciding every time.
 */
const ACTOR_RULES: Partial<Record<DealStatus, Array<'buyer' | 'seller'>>> = {
  PENDING_SELLER_APPROVAL: ['buyer'],
  ACCEPTED: ['seller'],
  PROCESSING: ['seller'],
  READY_FOR_PICKUP: ['seller'],
  DISPATCHED: ['seller'],
  DELIVERED: ['seller'],
  INSPECTION: ['buyer'],
  COMPLETED: ['buyer'],
  DISPUTED: ['buyer', 'seller'],
  CANCELLED: ['buyer', 'seller'],
  // RESOLVED is a platform ruling, and it is made on the disputes screen.
};

/**
 * The permission each target state requires, mirroring
 * `DealController::TRANSITION_PERMISSIONS`. Display courtesy again, never an
 * entitlement: the middleware is what enforces it.
 */
const STATE_PERMISSIONS: Partial<Record<DealStatus, string>> = {
  PENDING_SELLER_APPROVAL: 'deals.confirm',
  ACCEPTED: 'deals.confirm',
  PROCESSING: 'deals.dispatch',
  READY_FOR_PICKUP: 'deals.dispatch',
  DISPATCHED: 'deals.dispatch',
  DELIVERED: 'deals.dispatch',
  INSPECTION: 'deals.confirm',
  COMPLETED: 'deals.confirm',
  DISPUTED: 'deals.dispute',
  CANCELLED: 'deals.confirm',
};

/** The states at which a delivery code may be scanned, from `HANDOVER_ELIGIBLE_STATUSES`. */
const HANDOVER_ELIGIBLE: DealStatus[] = ['DELIVERED', 'INSPECTION', 'DISPUTED', 'RESOLVED'];

/**
 * Transitions the server refuses without a written reason — the three the state
 * machine checks for `blank($notes)`. Every one of them is somebody deciding
 * against the other party, which is why the note is kept.
 */
const REASON_REQUIRED: DealStatus[] = ['CANCELLED', 'DISPUTED', 'RESOLVED'];

/**
 * Transitions the server refuses until the delivery code has been spent — the two
 * `requires_handover` rules in `DealStateMachineService::TRANSITION_RULES`, which
 * answer `HANDOVER_NOT_VERIFIED`.
 *
 * *Requiring* the scan is the backend's job and it does that job whether or not
 * this file agrees. Hiding the buttons is only the courtesy of not handing
 * someone a control whose whole purpose is to be refused, for a sale is closed
 * once and a person who clicks twice at a gate learns to distrust both.
 */
const HANDOVER_GATED: DealStatus[] = ['INSPECTION', 'COMPLETED'];

/**
 * The moves this screen offers, in the words of the party who makes them.
 *
 * `RESOLVED` is deliberately absent: a dispute ruling belongs to the platform and
 * is made on the disputes screen, where the ruling and the reason go together.
 */
const ACTION_LABELS: Record<string, { ar: string; en: string }> = {
  ACCEPTED: { ar: 'قبول الصفقة', en: 'Accept deal' },
  PROCESSING: { ar: 'بدء التجهيز', en: 'Start preparing' },
  READY_FOR_PICKUP: { ar: 'جاهزة للاستلام', en: 'Ready for pickup' },
  DISPATCHED: { ar: 'تم الشحن', en: 'Mark dispatched' },
  DELIVERED: { ar: 'تأكيد التسليم', en: 'Confirm delivery' },
  INSPECTION: { ar: 'بدء الفحص بعد المسح', en: 'Open inspection after the scan' },
  COMPLETED: { ar: 'استُلمت وأُغلقت', en: 'Received and closed' },
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
  const locale = getLocale();
  // Which side of a trade this session is standing on, for the two filters
  // below. An account with no organization in context is nobody's party, and the
  // platform's own moves are made from the disputes screen.
  const orgId = Number(currentPersonaId) || null;

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

  /**
   * The order whose delivery code is being written down at the gate, and the
   * request in flight. Kept apart from `reasonAsk` on purpose: a code is not a
   * reason, it goes to a different endpoint, and its failure is a fact about the
   * goods rather than about somebody's decision.
   */
  const [scanAsk, setScanAsk] = useState<Deal | null>(null);
  const [scanningId, setScanningId] = useState<number | null>(null);

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

  /**
   * Spend the order's delivery code, on the server's authority and no other.
   *
   * This client sends what it was handed — a camera's payload or sixteen
   * characters read off a label — and prints the answer. That is not laziness:
   * "already spent", "this code belongs to another order", "this one was retired"
   * and "this is not an order a scan can close" are four different things to say
   * to a person standing at a gate with a phone in one hand, and only the service
   * holding the row lock knows which happened. A check duplicated here could only
   * be a guess, and a guess that disagrees with the server costs someone their
   * delivery receipt.
   */
  const runScan = async (deal: Deal, code: string) => {
    setScanningId(deal.id);
    setActionError(null);
    const response = await api.verifyHandover(deal.id, { code: code.trim() });
    setScanningId(null);

    if (!response.success) {
      setActionError(
        response.error?.message ??
          response.message ??
          (locale === 'ar' ? 'لم يُقبل رمز التسليم.' : 'The delivery code was not accepted.')
      );
      return;
    }

    setScanAsk(null);
    setNotice(
      locale === 'ar'
        ? 'مُسح رمز التسليم وسُجِّل. للمشتري الآن أن يفتح مهلة الفحص أو يؤكد الاستلام؛ الصفقة لا تُغلق بدون هذه الخطوة.'
        : 'The delivery code was spent and recorded. The buyer can now open the inspection window or confirm receipt — an order does not close without this step.'
    );
    await loadData();
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
   * Which side of this order the session stands on.
   *
   * `null` is a real answer and not a bug to paper over: an organization can see
   * a deal in a report and still not be its buyer or its seller. Such an account
   * gets no buttons, because the state machine would answer "this deal does not
   * belong to your organization", and a screen that offers a refusal has taught
   * the person that the refusal is their fault.
   */
  const sideOf = (deal: Deal): 'buyer' | 'seller' | 'platform' | null => {
    if (orgId === null) return 'platform';
    if (orgId === Number(deal.buyer_organization_id)) return 'buyer';
    if (orgId === Number(deal.seller_organization_id)) return 'seller';
    return null;
  };

  /**
   * The moves this account may make on this order: the state's own forward edges,
   * narrowed by who performs them and what the session holds.
   *
   * Both filters are presentation, and neither is enforcement. The party rule is
   * here because a seller's "confirm receipt" button is a refusal waiting to be
   * clicked, and clicking it teaches the wrong story about whose sentence "I have
   * it" is. The permission filter is the same courtesy. The server applies its own
   * copy of both, every time, and where it refuses its words are what appears.
   */
  const openMovesFor = (deal: Deal): DealStatus[] => {
    const side = sideOf(deal);

    return (NEXT_STATES[deal.status] ?? []).filter((target) => {
      if (side === null) return false;
      if (side !== 'platform' && !(ACTOR_RULES[target] ?? []).includes(side)) return false;
      const permission = STATE_PERMISSIONS[target];
      return !permission || Boolean(can?.(permission));
    });
  };

  /**
   * …and the same moves once the delivery code is taken into account.
   *
   * `awaitingScan` at the call site is the difference between these two lists,
   * which is why it cannot be guessed from the state: an order whose code was
   * spent from another session, or one that arrived before this gate existed,
   * has the same `deal.status` as one still waiting at the gate and must not be
   * told to scan twice.
   */
  const movesFor = (deal: Deal): DealStatus[] => {
    const open = openMovesFor(deal);
    if (deal.handover?.status === 'USED') return open;
    return open.filter((target) => !HANDOVER_GATED.includes(target));
  };

  /**
   * Whose turn it is, for the line that stands where a button would otherwise be.
   *
   * An order with no moves for this account is not an order with nothing to do:
   * usually the goods are with the other party. Saying which one is the difference
   * between a screen a dispatcher trusts and a screen they telephone somebody
   * about. `null` means the answer is genuinely mixed — both parties can still
   * fall out or call the order off — and then no claim is made.
   */
  const waitingOn = (deal: Deal): string | null => {
    const sides = new Set((NEXT_STATES[deal.status] ?? []).flatMap((target) => ACTOR_RULES[target] ?? []));

    if (sides.size === 1 && sides.has('buyer')) return locale === 'ar' ? 'المشتري' : 'the buyer';
    if (sides.size === 1 && sides.has('seller')) return locale === 'ar' ? 'البائع' : 'the seller';
    return null;
  };

  return (
    <div className="mx-auto w-full max-w-7xl space-y-4 sm:space-y-6 px-3 sm:px-6 lg:px-8 py-6">
      <div className="flex flex-col items-stretch gap-3 rounded-xl border border-slate-200 bg-white p-3 sm:p-4 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0">
          <h2 className="font-serif text-lg font-black text-slate-900">
            {locale === 'ar' ? 'مركز الصفقات وعقود التوريد' : 'Deals and supply contracts'}
          </h2>
          <p className="text-xs text-slate-500">
            {locale === 'ar'
              ? 'المسار: عرض ← طلب شراء ← قبول البائع وحجز الكمية ← أمر شراء ← تسليم برُمز مسح ← فحص ← إغلاق. السداد بين الطرفين خارج المنصة.'
              : 'Path: offer → purchase request → seller acceptance and held stock → order → delivery attested by a scanned code → inspection → closed. The price is settled between the two businesses, outside the platform.'}
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
            const steps = FULFILMENT_STEPS;
            const currentIndex = steps.indexOf(deal.status);
            const terminal = ['CANCELLED', 'COMPLETED'].includes(deal.status);
            const nextStates = movesFor(deal);
            /* The scan gate is the only thing presentation removes beyond party and
               permission here, so the two lists differ exactly where a code is
               still unspent. */
            const awaitingScan = nextStates.length !== openMovesFor(deal).length;
            /* The one move that carries the deal onward, and the rest of the same
               server-offered transitions a tap deeper. Nothing here adds or
               removes a state: `movesFor` only removes the ones this account is
               not a party to. */
            const forward = nextStates.find((state) => state !== 'CANCELLED' && state !== 'DISPUTED');
            const otherStates = nextStates.filter((state) => state !== forward);
            const handover = deal.handover ?? null;
            const owedTo = waitingOn(deal);

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
                      {/*
                        The order's delivery code, in the one word that says which
                        of its three lives it is in. No escrow chip beside it, and
                        none will return: the platform holds no funds, so there was
                        never a state of theirs to badge.
                      */}
                      {handover && (
                        <span
                          className={`flex items-center gap-1 rounded border px-2 py-0.5 text-[11px] font-medium ${
                            handover.status === 'USED'
                              ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                              : handover.status === 'ACTIVE'
                              ? 'border-blue-200 bg-blue-50 text-blue-700'
                              : 'border-slate-200 bg-slate-50 text-slate-500'
                          }`}
                        >
                          <QrCode className="h-3 w-3 shrink-0" />
                          {handover.status === 'USED'
                            ? (locale === 'ar' ? 'رُمز التسليم مُمسوح' : 'Handover scanned')
                            : handover.status === 'ACTIVE'
                            ? (locale === 'ar' ? 'رُمز التسليم جاهز' : 'Handover code ready')
                            : (locale === 'ar' ? 'رُمز التسليم مُسحب' : 'Handover code retired')}
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

                  <HandoverStrip
                    deal={deal}
                    locale={locale}
                    side={sideOf(deal)}
                    canScan={
                      handover?.status === 'ACTIVE'
                      && HANDOVER_ELIGIBLE.includes(deal.status)
                      // The buyer presents the code; the seller spends it. A
                      // platform session keeps the button because a settlement
                      // is carried out at the gate by somebody holding no side
                      // of the contract, and the printed label does not care
                      // whose phone reads it — the server accepts either party.
                      && (sideOf(deal) === 'seller' || sideOf(deal) === 'platform')
                      && Boolean(can?.('deals.deliver'))
                    }
                    onScan={() => setScanAsk(deal)}
                  />

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

                  {/*
                    A card with no button is a question, and it deserves an answer:
                    whose move this is. The order is not stuck and nothing has been
                    skipped — the goods, or the word about them, are with the other
                    business. When the machine offers moves but none of them belong
                    to this account, that is said too, because the alternative is a
                    person assuming the platform has lost their order.
                  */}
                  {nextStates.length === 0 && !awaitingScan && !terminal && (
                    <p className="text-[11px] text-slate-500">
                      {sideOf(deal) === null
                        ? (locale === 'ar'
                          ? 'هذه المنشأة ليست طرفًا في هذا العقد، لذا لا إجراءات لك هنا.'
                          : 'This organization is not a party to this contract, so there is nothing for it to do here.')
                        : (locale === 'ar'
                          ? 'لا انتقال متاح لهذه الصفحة من العقد.'
                          : 'No transition is offered from this state.')}
                    </p>
                  )}
                  {nextStates.length === 0 && !awaitingScan && !terminal && sideOf(deal) !== null && owedTo && (
                    <p className="text-[11px] font-semibold text-slate-600">
                      {locale === 'ar' ? `الخطوة التالية على ${owedTo}.` : `The next move is ${owedTo}’s.`}
                    </p>
                  )}

                  {/*
                    The step that is missing is a physical one: somebody has to
                    stand at the goods with a camera. Saying so keeps an unscanned
                    order from reading as a stuck one, and naming the permission
                    covers the account that can see the gate but not operate it.
                  */}
                  {awaitingScan && !terminal && (
                    <>
                      <p className="text-[11px] font-semibold text-slate-600">
                        {sideOf(deal) === 'buyer'
                          ? (locale === 'ar'
                            ? 'لا فحص ولا إغلاق قبل أن يمسح البائع كود التسليم: أعْرِضه عليه من الشريط أعلاه.'
                            : 'Neither inspection nor closure is offered until the seller spends your delivery code — show it to them from the strip above.')
                          : (locale === 'ar'
                            ? 'لا فحص ولا إغلاق قبل مسح كود المشتري: امسحه من الشريط أعلاه واحفظه.'
                            : 'Neither inspection nor closure is offered until the buyer’s code is scanned — spend it from the strip above and save.')}
                      </p>
                      {sideOf(deal) !== 'buyer' && !can?.('deals.deliver') && (
                        <p className="text-[11px] text-slate-500">
                          {locale === 'ar'
                            ? 'والمسح صلاحيةٌ (deals.deliver) لا تحملها هذه الجلسة؛ يجريه من سلّم البضاعة.'
                            : 'Scanning is a deals.deliver permission this session does not hold; whoever delivered the goods runs it.'}
                        </p>
                      )}
                    </>
                  )}

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
          locale={locale}
          onClose={() => setSelectedDeal(null)}
        />
      )}

      {scanAsk && (
        <ScanSheet
          deal={scanAsk}
          locale={locale}
          busy={scanningId === scanAsk.id}
          onCancelLabel={locale === 'ar' ? 'تراجع' : 'Back'}
          onClose={() => setScanAsk(null)}
          onSubmit={(code) => runScan(scanAsk, code)}
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

/* ── The delivery code ─────────────────────────────────────────────────────── */

/**
 * What this order's handover looks like from where the reader is standing.
 *
 * Three sentences and no more, because each is a different fact about goods:
 *
 *   no code      — the seller has not accepted, so nothing has been issued. The
 *                  server says the same thing when asked, which is why this is a
 *                  line rather than an empty box.
 *   active code  — the label is printed and unspent. The order cannot be opened
 *                  for inspection or closed until somebody at the gate reads it
 *                  to the platform, so the screen says that in the same breath as
 *                  it shows the characters.
 *   spent        — the receipt the whole cycle exists to produce: when, and that
 *                  it happened once.
 *
 * A retired code is shown as retired. It is not deleted from view, because "the
 * order was called off and its proof of delivery was withdrawn" is a thing two
 * businesses may need to read back months later.
 */
/**
 * The symbol the backend encoded, drawn as it was meant to be drawn.
 *
 * Every module is a unit square in a `viewBox` sized to the grid plus the quiet
 * zone the encoder documented but deliberately did not include, so the same
 * component fills a card on a laptop and a sheet of A4 at the printer: the
 * browser scales the canvas, and a QR stays readable as long as its modules do
 * not blur together — which is why the box has a floor as well as a ceiling.
 *
 * Two things here are not styling preferences:
 *
 * **`shapeRendering="crispEdges"`.** A QR's edges are its data. An
 * anti-aliased boundary between a dark and a light module is a camera reading a
 * grey module it cannot decide about, which is the difference between a scan and
 * a person turning up the brightness and trying again.
 *
 * **The white ground, always.** This sits on a console that goes dark, and an
 * inverted symbol — light modules on a dark field — is a symbol most cameras
 * will not find, because the locator patterns it looks for are dark rings on
 * light. The panel that shows the code to a driver is the one surface in this
 * app that is not allowed to follow the theme.
 *
 * When the server sends no symbol (a payload longer than the platform encodes),
 * the caller is left with the characters, and says so.
 */
const QrGlyph: React.FC<{ symbol: QrSymbol; className?: string }> = ({ symbol, className }) => {
  const quiet = symbol.quiet_zone > 0 ? symbol.quiet_zone : 4;
  const edge = symbol.size + quiet * 2;

  // Runs rather than rectangles: a version-4 symbol is over five hundred dark
  // modules, and one `<path>` with a horizontal run per row is a tenth of the
  // nodes the same picture made a module at a time.
  const ink = symbol.rows
    .map((row, y) => {
      let d = '';
      let run = 0;
      for (let x = 0; x <= symbol.size; x++) {
        if (row[x] === '1') {
          run += 1;
          continue;
        }
        if (run > 0) d += `M${x - run + quiet} ${y + quiet}h${run}v1h-${run}z`;
        run = 0;
      }
      return d;
    })
    .join('');

  return (
    <svg
      viewBox={`0 0 ${edge} ${edge}`}
      shapeRendering="crispEdges"
      role="img"
      aria-label={symbol.payload}
      className={className}
    >
      <rect width={edge} height={edge} fill="#ffffff" />
      <path d={ink} fill="#0f172a" />
    </svg>
  );
};

/**
 * Show the code — and ask, once, for the thing to show.
 *
 * The pipeline list carries the handover row: the characters, their status, who
 * spent them. It does not carry a symbol, because encoding one for every order on
 * the screen would be forty QR builds for the handful of times a person actually
 * holds a phone up to a monitor. So the grid arrives from `GET /deals/{id}/handover`
 * — the same endpoint the detail sheet re-reads for `verifiable` — fetched on the
 * press that means somebody wants it.
 *
 * `symbol` therefore has three states and they are not interchangeable: `undefined`
 * means not asked yet or still on the wire, `null` means asked and the platform
 * cannot build one (the payload needs a bigger symbol than §4.7 encodes), and a
 * value means draw it. The last two both end at the characters, but only the second
 * one says why — a screen that silently downgrades to a text code reads as a design
 * choice, and it is a limitation somebody at a loading bay has a right to see.
 */
const HandoverStrip: React.FC<{
  deal: Deal;
  locale: 'ar' | 'en';
  side: 'buyer' | 'seller' | 'platform' | null;
  canScan: boolean;
  onScan: () => void;
}> = ({ deal, locale, side, canScan, onScan }) => {
  const handover = deal.handover ?? null;
  const [copied, setCopied] = useState(false);
  const [presented, setPresented] = useState(false);
  const [symbol, setSymbol] = useState<QrSymbol | null>();
  const [labelUrl, setLabelUrl] = useState<string | null>(null);
  const [labelNote, setLabelNote] = useState<string | null>(null);

  // An object URL is a live reference to a document holding a live code. When
  // this card stops being looked at, the reference goes with it.
  useEffect(() => () => {
    if (labelUrl) window.URL.revokeObjectURL(labelUrl);
  }, [labelUrl]);

  const openLabel = () => {
    setLabelNote(null);
    void api.handoverLabelUrl(deal.id).then((result) => {
      if (result.success && result.url) setLabelUrl(result.url);
      else setLabelNote(result.message ?? (locale === 'ar' ? 'تعذّر تجهيز الملصق.' : 'The label could not be prepared.'));
    });
  };

  const present = () => {
    const opening = !presented;
    setPresented(opening);

    if (!opening || handover === null || symbol !== undefined) return;

    void api.getHandover(deal.id).then((response) => {
      // A failure is not turned into a message: the response's own error code
      // belongs to the scan sheet and the closing gate, not to a picture. Here
      // the only useful outcome is that the characters stay on screen, which is
      // what `null` already produces.
      setSymbol(response.success ? (response.data?.qr ?? null) : null);
    });
  };

  if (!handover) {
    if (deal.status !== 'PENDING_SELLER_APPROVAL' && deal.status !== 'DRAFT') return null;

    return (
      <p className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-[11px] text-slate-500">
        {locale === 'ar'
          ? 'يصدر رمز التسليم حين يقبل البائع هذا العقد، ولا تُغلق الصفقة بدونه.'
          : 'The delivery code is issued when the seller accepts this contract, and the order cannot be closed without it.'}
      </p>
    );
  }

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(handover.code);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1800);
    } catch {
      // A browser that refuses the clipboard is not a reason to hide the code;
      // it is on the screen to be read, and the label it was printed on is
      // beside the goods anyway.
      setCopied(false);
    }
  };

  return (
    <div
      className={`space-y-1.5 rounded-lg border px-3 py-2 text-[11px] ${
        handover.status === 'USED'
          ? 'border-emerald-200 bg-emerald-50/70 text-emerald-900'
          : handover.status === 'ACTIVE'
          ? 'border-blue-200 bg-white text-slate-700'
          : 'border-slate-200 bg-white text-slate-500'
      }`}
    >
      <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
        <QrCode className="h-3.5 w-3.5 shrink-0" />
        <span className="font-bold">
          {locale === 'ar' ? 'رمز التسليم' : 'Handover code'}
        </span>
        <span dir="ltr" className="min-w-0 font-mono text-[12px] font-black tracking-[0.12em]">
          {handover.code}
        </span>
        {handover.status === 'ACTIVE' && (
          <button
            type="button"
            onClick={copy}
            className="flex cursor-pointer items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-semibold text-slate-600 hover:bg-slate-100"
          >
            <Copy className="h-3 w-3" />
            {copied ? (locale === 'ar' ? 'نُسخ' : 'Copied') : (locale === 'ar' ? 'نسخ' : 'Copy')}
          </button>
        )}
      </div>

      {handover.status === 'ACTIVE' && (
        <>
          <p className="text-slate-500">
            {side === 'buyer'
              ? (locale === 'ar'
                ? 'هذا رمزك: أعْرِضه للبائع أو لسائقه عند وصول البضاعة، فهو من يمسحه ويحفظ إثبات التسليم. لا يبدأ الفحص ولا تُغلق الصفقة قبل ذلك المسح.'
                : 'This is your code: show it to the seller or their driver when the goods arrive — theirs is the scan that records the handover. Neither the inspection window nor the closing of this order opens before it.')
              : (locale === 'ar'
                ? 'امسح رمز المشتري أو اكتُب ما على الملصق واحفظه: لا يفتح الفحص ولا تُغلق الصفقة قبل أن يُسجَّل مسحه مرة واحدة.'
                : 'Scan the buyer’s code, or type what the label says, and save it: the inspection window and the closing of this order both wait for that one recording.')}
          </p>

          {/* The buyer's side of the handover is a screen the other business
              reads with a camera held at arm's length, so it is drawn for that:
              the symbol the platform encoded, at the size a phone pointed at it
              can resolve, on the whitest surface the console has. The characters
              stay underneath, because a cracked lens or a glare-washed panel
              ends in somebody reading them out across a loading bay — and the
              scan sheet accepts either. */}
          {side === 'buyer' && (
            <>
              <button
                type="button"
                onClick={present}
                className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-blue-200 bg-blue-50 px-3 py-1.5 font-bold text-blue-800 transition hover:bg-blue-100"
              >
                <QrCode className="h-3.5 w-3.5" />
                {presented
                  ? (locale === 'ar' ? 'إخفاء العرض' : 'Hide the display')
                  : (locale === 'ar' ? 'اعرض الرمز للبائع' : 'Show the code to the seller')}
              </button>
              {presented && (
                <div className="rounded-lg border border-slate-200 bg-white px-4 py-5 text-center">
                  <div className="text-[11px] text-slate-500" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                    {locale === 'ar' ? 'كود تسليم العقد' : 'Handover code for'}{' '}
                    <span className="font-mono">{deal.deal_number}</span>
                  </div>

                  {symbol === undefined && (
                    <div className="flex items-center justify-center py-4 text-[11px] text-slate-400">
                      {locale === 'ar' ? '… يُجهَّز الرمز' : '… preparing the symbol'}
                    </div>
                  )}

                  {symbol !== undefined && (
                    <div className="flex justify-center py-4">
                      {symbol ? (
                        <QrGlyph
                          symbol={symbol}
                          className="h-[min(58vw,260px)] w-[min(58vw,260px)] min-w-[150px]"
                        />
                      ) : (
                        /* No symbol, so the characters take the picture's place at
                           the picture's size — large enough that a person across a
                           cab can read them off the screen without leaning on it. */
                        <div dir="ltr" className="font-mono text-3xl font-black tracking-[0.18em] text-slate-900 sm:text-4xl">
                          {handover.code}
                        </div>
                      )}
                    </div>
                  )}

                  {/* Under the picture, not instead of it: what the symbol says,
                      for the person who has to type it or read it aloud. */}
                  <div dir="ltr" className="pt-1 font-mono text-[13px] font-bold tracking-[0.14em] text-slate-700">
                    {handover.code}
                  </div>

                  <p className="pt-3 text-[11px] text-slate-500">
                    {symbol === undefined
                      ? (locale === 'ar'
                        ? 'انتظر حتى يظهر الرمز، ثم وجّه كاميرا البائع إليه.'
                        : 'Wait for the symbol, then point the seller’s camera at it.')
                      : symbol
                      ? (locale === 'ar'
                        ? 'وجّه كاميرا البائع إلى هذا الرمز؛ يمسحه ثم يحفظ إثبات التسليم. أو امسح ملصق الصندوق بنفسك إن كان معك.'
                        : 'Point the seller’s camera at this symbol; they scan it and save the proof of handover — or spend the label printed on the box if it is with you.')
                      : (locale === 'ar'
                        ? 'لم يرسل النظام رمزًا قابلًا للمسح لهذا الطلب؛ اقرأ الكود على البائع ليسجله يدويًا.'
                        : 'The platform sent no scannable symbol for this order, so read the code to the seller and let them type it in.')}
                  </p>

                  {/* The box leaves before the phone does, so the same symbol is
                      offered as a document — the server's own SVG, at the route
                      built for it. Two steps rather than one link because a
                      browser sends no bearer token when somebody clicks an href:
                      the first press asks with this session's headers, and only
                      then does the download become real. */}
                  <div className="flex flex-wrap items-center justify-center gap-2 pt-3">
                    {labelUrl ? (
                      <a
                        href={labelUrl}
                        download={`hassal-handover-${deal.deal_number}.svg`}
                        className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-50"
                      >
                        <Printer className="h-3.5 w-3.5" />
                        {locale === 'ar' ? 'حفظ الملصق للطباعة' : 'Save the label to print'}
                      </a>
                    ) : (
                      <button
                        type="button"
                        onClick={openLabel}
                        className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 font-bold text-slate-700 transition hover:bg-slate-50"
                      >
                        <Printer className="h-3.5 w-3.5" />
                        {locale === 'ar' ? 'ملصق للطباعة' : 'Printable label'}
                      </button>
                    )}
                    {labelNote && (
                      <span className="text-[11px] text-red-600" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
                        {labelNote}
                      </span>
                    )}
                  </div>
                </div>
              )}
            </>
          )}

          {canScan && (
            <button
              type="button"
              onClick={onScan}
              className="flex cursor-pointer items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 font-bold text-white transition hover:bg-blue-700"
            >
              <ScanLine className="h-3.5 w-3.5" />
              {locale === 'ar' ? 'مسح كود المشتري وحفظه' : 'Scan the buyer’s code and save'}
            </button>
          )}
          {side === 'buyer' && !presented && (
            <p className="text-[11px] text-slate-500">
              {locale === 'ar'
                ? 'المسح من جهة البائع؛ لا زرّ مسح عندك في هذه الصفحة.'
                : 'The scan is the seller’s button; this page gives you none.'}
            </p>
          )}
        </>
      )}

      {handover.status === 'USED' && (
        <p>
          {locale === 'ar' ? 'مُسح' : 'Scanned'}
          {handover.verified_by_organization_id != null && (
            <>
              {' • '}
              {Number(handover.verified_by_organization_id) === Number(deal.seller_organization_id)
                ? (locale === 'ar' ? 'بمسح البائع' : 'by the seller')
                : Number(handover.verified_by_organization_id) === Number(deal.buyer_organization_id)
                ? (locale === 'ar' ? 'بمسح المشتري' : 'by the buyer')
                : (locale === 'ar' ? 'بمسح المنصة' : 'by the platform')}
            </>
          )}
          {formatDateTime(handover.verified_at ?? '', locale) ? ` • ${formatDateTime(handover.verified_at ?? '', locale)}` : ''}
          {handover.verification_notes ? ` • ${handover.verification_notes}` : ''}
        </p>
      )}

      {handover.status === 'INVALIDATED' && (
        <p>
          {locale === 'ar'
            ? 'سُحب هذا الرمز مع إيقاف العقد، ولا يمكن مسحهُ مرة أخرى.'
            : 'This code was retired when the order was called off, and it cannot be scanned.'}
        </p>
      )}
    </div>
  );
};

/**
 * The minimum the browser's own barcode reader needs to be described with.
 *
 * `BarcodeDetector` is not in TypeScript's DOM library yet, and the whole point
 * of this sheet is that it works when the API is there and says something useful
 * when it is not — so the type is written down rather than a library added.
 */
type BarcodeDetectorLike = {
  detect: (source: CanvasImageSource) => Promise<Array<{ rawValue?: string; format?: string }>>;
};

const barcodeDetectorCtor = (): (new (config?: { formats?: string[] }) => BarcodeDetectorLike) | null => {
  const ctor = (window as unknown as { BarcodeDetector?: new (config?: { formats?: string[] }) => BarcodeDetectorLike })
    .BarcodeDetector;
  return typeof ctor === 'function' ? ctor : null;
};

/**
 * Writing the code down — by camera where the browser can read one, by keyboard
 * where it cannot.
 *
 * The typed field is not a fallback for a failing camera; it is the path a
 * damaged label, a driver's dictation and a dark loading bay all take, and the
 * server accepts it on exactly the same terms. Nothing here decides whether the
 * code is good: the sheet sends the string, and the answer comes back with the
 * server's own reason.
 */
const ScanSheet: React.FC<{
  deal: Deal;
  locale: 'ar' | 'en';
  busy: boolean;
  onCancelLabel: string;
  onClose: () => void;
  onSubmit: (code: string) => void;
}> = ({ deal, locale, busy, onCancelLabel, onClose, onSubmit }) => {
  const [code, setCode] = useState('');
  const [camera, setCamera] = useState(false);
  const [note, setNote] = useState<string | null>(null);
  const supported = Boolean(barcodeDetectorCtor());

  const decode = async (file: File) => {
    const Ctor = barcodeDetectorCtor();

    if (!Ctor) {
      setNote(locale === 'ar' ? 'متصفحك لا يقرأ الرموز الشريطية؛ اكتب الرمز من الملصق.' : 'This browser cannot read barcodes. Type the code from the label.');
      return;
    }

    try {
      const bitmap = await createImageBitmap(file);
      const found = await new Ctor({ formats: ['qr_code', 'code_128'] }).detect(bitmap);
      const value = found.find((entry) => entry.rawValue)?.rawValue ?? '';

      if (!value) {
        setNote(locale === 'ar'
          ? 'لم يقترن بالصورة رمز؛ أعد الالتقاط قريبًا وبإضاءة أفضل، أو اكتُب الرمز.'
          : 'No code came back from that frame. Take it again closer and without glare, or type the code.');
        return;
      }

      setCode(value);
      setNote(null);
      setCamera(false);
    } catch {
      // A decoder that throws is the same event as one that finds nothing, and
      // the typed field is still there.
      setNote(locale === 'ar'
        ? 'تعذّر فحص الصورة؛ الرمز مكتوب على الملصق ويمكن إدخاله.'
        : 'That frame could not be read. The code is printed on the label and can be typed.');
    }
  };

  return (
    <Modal
      title={locale === 'ar' ? `مسح رمز التسليم للعقد ${deal.deal_number}` : `Scan the handover code for ${deal.deal_number}`}
      onClose={onClose}
    >
      <p className="text-xs text-slate-600">
        {locale === 'ar'
          ? 'يُسجَّل المسح باسمك ومنشأتك ووقته، ولا يُقبل إلا مرة واحدة لهذا العقد. لا تُغلق الصفقة بهذا الإجراء؛ يشتري العقد حقه في الفحص والإغلاق بعده.'
          : 'The scan is recorded with your name, your organization and the moment it happened, and it is accepted once for this order. This action does not close the deal — it is what lets the buyer’s own receipt close it.'}
      </p>

      {camera ? (
        <div className="space-y-2">
          <CameraCapture
            subjectLabel={locale === 'ar' ? 'وجّه الكاميرا إلى ملصق رمز التسليم' : 'Point the camera at the handover label'}
            fileName={`handover-${deal.deal_number}.jpg`}
            onCaptured={decode}
            onClose={() => setCamera(false)}
          />
          {!supported && (
            <p className="text-[11px] text-amber-800">
              {locale === 'ar'
                ? 'متصفحك لا يقرأ الرموز من الصورة؛ الالتقاط هنا للتوثيق فقط، واكتب الرمز في الحقل أدناه.'
                : 'This browser cannot read a code out of a picture — the capture is for the record only, so type the code into the field below.'}
            </p>
          )}
        </div>
      ) : (
        supported && (
          <button
            type="button"
            onClick={() => {
              setNote(null);
              setCamera(true);
            }}
            className="flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
          >
            <ScanLine className="h-4 w-4" />
            {locale === 'ar' ? 'امسح بالكاميرا' : 'Scan with the camera'}
          </button>
        )
      )}

      <label className="block space-y-1">
        <span className="block text-[11px] font-bold text-slate-600">
          {locale === 'ar' ? 'الرمز كما على الملصق' : 'The code as printed on the label'}
        </span>
        <input
          autoFocus
          dir="ltr"
          value={code}
          onChange={(event) => setCode(event.target.value.toUpperCase())}
          placeholder="HASSAL:HANDOVER:… أو الرمز وحده"
          className="w-full rounded-lg border border-slate-200 bg-slate-50 p-2.5 font-mono text-xs font-normal tracking-wider focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
        />
      </label>

      {note && <p className="text-[11px] text-amber-800">{note}</p>}

      <div className="flex flex-col-reverse gap-2.5 sm:flex-row">
        <button
          type="button"
          onClick={onClose}
          className="w-full cursor-pointer rounded-xl bg-slate-100 py-3 text-xs font-semibold text-slate-700 hover:bg-slate-200 sm:flex-1 sm:py-2.5"
        >
          {onCancelLabel}
        </button>
        <button
          type="button"
          disabled={busy || code.trim().length < 4}
          onClick={() => onSubmit(code)}
          className="w-full cursor-pointer rounded-xl bg-emerald-600 py-3 text-xs font-bold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-50 sm:flex-1 sm:py-2.5"
        >
          {busy
            ? (locale === 'ar' ? 'جارٍ التسجيل…' : 'Recording…')
            : (locale === 'ar' ? 'تسجيل المسح' : 'Record the scan')}
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

/**
 * The contract as one record: the two businesses, the frozen price, the delivery
 * code and everything the platform has witnessed about it.
 *
 * The code is re-read from its own endpoint rather than taken on trust from the
 * list. The list row is a moment old by the time a person opens this sheet, and
 * somebody at the gate may have spent the code in between; this is also where
 * `verifiable` comes from, since that answer is the state machine's, not a column.
 */
const DealDetail: React.FC<{
  deal: Deal;
  locale: 'ar' | 'en';
  onClose: () => void;
}> = ({ deal, locale, onClose }) => {
  const vat = locale === 'ar' ? 'الرقم الضريبي' : 'Tax number';
  const [handover, setHandover] = useState<HandoverView | null>(null);

  useEffect(() => {
    let live = true;
    setHandover(null);

    // Only when a code is known to exist: `deals/{id}/handover` answers 422 with
    // `HANDOVER_NOT_ISSUED` before the seller accepts, and asking a question the
    // list already answered would only turn a normal state into a failed call.
    if (!deal.handover) return;

    void api.getHandover(deal.id).then((response) => {
      if (live && response.success && response.data) setHandover(response.data);
    });

    return () => {
      live = false;
    };
  }, [deal.id, deal.handover]);

  const record = handover ?? deal.handover;

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
        Money and the delivery code.

        There is no payment endpoint on this platform: HASSAL does not hold a
        customer's money, so this block reports the price the record froze and
        says plainly that settling it happens between the two businesses. No
        screen here shows a bank account or confirms a transfer a real source
        never reported.

        What the platform *does* witness is the handover of the goods, and that
        is the record below: the code issued when the seller accepted the order,
        and the moment a scan spent it.
      */}
      <div className="space-y-2 rounded-xl border border-slate-200 p-3.5">
        <h4 className="flex items-center gap-2 text-xs font-bold text-slate-800">
          <QrCode className="h-4 w-4 text-slate-500" />
          {locale === 'ar' ? 'كود التسليم' : 'Delivery code'}
        </h4>

        {record ? (
          <div className="space-y-1.5 text-[11px] text-slate-600">
            <div className="flex items-center justify-between gap-2">
              <span>{locale === 'ar' ? 'الرمز' : 'Code'}</span>
              <span dir="ltr" className="font-mono font-bold text-slate-900">{record.code}</span>
            </div>

            {/* This sheet is the record, and a record is also what somebody prints
                when the phone that carried the code did not arrive with the goods.
                The symbol from `GET /deals/{id}/handover` is drawn here — unspent
                only — so the same picture is on the screen whether the buyer is
                holding it up at a gate or a desk is sending it to a printer. */}
            {handover?.qr && handover.status === 'ACTIVE' && (
              <div className="flex justify-center border-y border-slate-100 py-3">
                <QrGlyph symbol={handover.qr} className="h-[min(52vw,220px)] w-[min(52vw,220px)] min-w-[140px]" />
              </div>
            )}
            <div className="flex items-center justify-between gap-2">
              <span>{locale === 'ar' ? 'حالته' : 'State'}</span>
              <span className={`font-bold ${record.status === 'USED' ? 'text-emerald-700' : record.status === 'ACTIVE' ? 'text-amber-700' : 'text-slate-500'}`}>
                {record.status === 'USED'
                  ? (locale === 'ar' ? 'استُخدم مرة واحدة' : 'Spent, once')
                  : record.status === 'ACTIVE'
                    ? (locale === 'ar' ? 'فعّال لم يُستخدم' : 'Live, unused')
                    : (locale === 'ar' ? 'مسحوب مع إيقاف العقد' : 'Retired with the order')}
              </span>
            </div>
            {record.issued_at && (
              <div className="flex items-center justify-between gap-2">
                <span>{locale === 'ar' ? 'أُصدر' : 'Issued'}</span>
                <span>{formatDateTime(record.issued_at, locale) || record.issued_at}</span>
              </div>
            )}
            {record.verified_at && (
              <div className="flex items-center justify-between gap-2">
                <span>{locale === 'ar' ? 'مُسح في' : 'Scanned'}</span>
                <span>{formatDateTime(record.verified_at, locale) || record.verified_at}</span>
              </div>
            )}
            {record.verified_at && record.verified_by_organization_id && (
              <div className="flex items-center justify-between gap-2">
                <span>{locale === 'ar' ? 'من طرف' : 'By side'}</span>
                <span>
                  {record.verified_by_organization_id === deal.buyer_organization_id
                    ? (locale === 'ar' ? 'المشتري' : 'Buyer')
                    : record.verified_by_organization_id === deal.seller_organization_id
                      ? (locale === 'ar' ? 'البائع' : 'Seller')
                      : `#${record.verified_by_organization_id}`}
                </span>
              </div>
            )}
            {record.verification_notes && (
              <p className="border-t border-slate-100 pt-1.5 leading-relaxed text-slate-600">
                {record.verification_notes}
              </p>
            )}

            {/* The label's own text, from the endpoint that computes it. Whoever
                prints the sticker encodes this string, not the bare code — the
                payload carries the order number, so a label that wanders off to
                another pallet says so the moment it is read. */}
            {handover && (
              <div className="space-y-1 border-t border-slate-100 pt-1.5">
                <div className="flex items-center justify-between gap-2">
                  <span>{locale === 'ar' ? 'ما يرمّزه الملصق' : 'What the label encodes'}</span>
                  <button
                    type="button"
                    onClick={() => void navigator.clipboard?.writeText(handover.qr_payload).catch(() => undefined)}
                    className="flex shrink-0 cursor-pointer items-center gap-1 rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 font-semibold text-slate-600 hover:bg-slate-100"
                  >
                    <Copy className="h-3 w-3" />
                    {locale === 'ar' ? 'نسخ' : 'Copy'}
                  </button>
                </div>
                <p dir="ltr" className="break-all font-mono text-[10px] text-slate-500">{handover.qr_payload}</p>
                <p className="text-[10px] leading-relaxed text-slate-500">
                  {handover.verifiable
                    ? (locale === 'ar'
                      ? 'هذا الرمز قابل للمسح في حالة الصفقة الحالية.'
                      : 'This code can be scanned in the order’s current state.')
                    : (locale === 'ar'
                      ? 'لا يقبل النظام مسح هذا الرمز في حالة الصفقة الحالية — إمّا استُخدم أو سُحب أو أن الأمر أُغلق.'
                      : 'The system will not accept a scan of this code in the order’s current state — it is spent, retired, or the matter is closed.')}
                </p>
              </div>
            )}
          </div>
        ) : (
          <p className="text-[11px] leading-relaxed text-slate-500">
            {locale === 'ar'
              ? 'لم يُصدر رمز تسليم بعد؛ يُصدره النظام لحظة قبول البائع للطلب، ولا تُغلق الصفقة بدونه.'
              : 'No delivery code has been issued yet; the system issues it when the seller accepts the order, and the order cannot close without it.'}
          </p>
        )}

        <p className="border-t border-slate-100 pt-2 text-[11px] leading-relaxed text-slate-600">
          {locale === 'ar'
            ? 'أما المال فليس من شأن هذه الشاشة: تتم التسوية بين المنشأتين خارج المنصة، ولا تعرض هنا رقم حساب ولا تؤكد دفعًا لم يرد به خبر من مصدر حقيقي.'
            : 'The money is not this screen’s business: settlement happens between the two businesses outside the platform, and no bank account or payment confirmation appears here.'}
        </p>
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

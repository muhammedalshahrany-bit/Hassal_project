import React, { useState, useEffect } from 'react';
import {
  PlusCircle,
  Warehouse as WarehouseIcon,
  Package,
  Play,
  Pause,
  Send,
  Trash2,
  AlertCircle,
  MapPin,
  History,
  Crosshair,
  Camera,
  X,
} from 'lucide-react';
import { api, fieldError } from '../lib/api';
import type { ApiResult, Pagination } from '../lib/api';
import type { InventoryMovement, Offer, OfferStatus, Product, ProductVariant, Warehouse } from '../types';
import { formatDate, formatDateTime, getLocale } from '../lib/i18n';
import { currencyLabel, formatMoney, opportunityReasonLabel, usePlatformConfig } from '../lib/config';

interface SellerInventoryViewProps {
  currentPersonaId: string;
}

/**
 * The shelf is the seller's view of `/marketplace/my-offers`: this business's
 * offers in every status, as the server states them. Status and opportunity
 * reason are the server's uppercase vocabulary — lowercasing them on the client
 * is how a screen shows a state no backend transition will ever accept.
 */
const STATUS_LABELS: Record<OfferStatus, string> = {
  DRAFT: 'مسودة لم تُرفع بعد',
  PENDING_REVIEW: 'بانتظار مراجعة المنصة',
  ACTIVE: 'معروض في السوق',
  PAUSED: 'موقوف مؤقتاً',
  SOLD_OUT: 'نفدت الكمية',
  EXPIRED: 'منتهي الصلاحية',
  CANCELLED: 'مسحوب',
};

const STATUS_STYLES: Record<OfferStatus, string> = {
  DRAFT: 'bg-slate-100 text-slate-700',
  PENDING_REVIEW: 'bg-amber-100 text-amber-800',
  ACTIVE: 'bg-emerald-100 text-emerald-800',
  PAUSED: 'bg-slate-200 text-slate-700',
  SOLD_OUT: 'bg-sky-100 text-sky-800',
  EXPIRED: 'bg-rose-100 text-rose-800',
  CANCELLED: 'bg-rose-100 text-rose-800',
};

/** Quantities and prices arrive as decimal strings; only the numbers are coerced. */
const normalizeOffer = (offer: Offer): Offer => ({
  ...offer,
  total_quantity: Number(offer.total_quantity ?? 0),
  available_quantity: Number(offer.available_quantity ?? 0),
  reserved_quantity: Number(offer.reserved_quantity ?? 0),
  sold_quantity: Number(offer.sold_quantity ?? 0),
  unit_price: Number(offer.unit_price ?? 0),
  min_order_quantity: Number(offer.min_order_quantity ?? 0),
  max_order_quantity:
    offer.max_order_quantity == null ? undefined : Number(offer.max_order_quantity),
});

/** Every field `OfferController::store()` may reject, so its answer lands where it belongs. */
const OFFER_FIELDS = [
  'warehouse_id',
  'product_variant_id',
  'category_id',
  'title_ar',
  'title_en',
  'description_ar',
  'opportunity_reason',
  'opportunity_reason_notes',
  'min_order_quantity',
  'max_order_quantity',
  'total_quantity',
  'unit',
  'unit_price',
  'currency_code',
  'production_date',
  'expiry_date',
  'storage_condition',
  'delivery_terms',
  'inspection_window_hours',
  'expires_at',
];

const DELIVERY_TRANSLATION: Record<string, string> = {
  BUYER_PICKUP: 'استلام من المستودع',
  SELLER_DELIVERY: 'توصيل من البائع',
  BOTH: 'استلام أو توصيل',
};

/**
 * A refusal, in the server's own terms. The codes this screen can honestly hit
 * are named rather than dumped, because each asks something different of the
 * seller: a permission the role does not hold, a feature the platform switched
 * off, or a status change the offer's history forbids.
 */
function refusalOf(result: ApiResult<unknown>): string {
  const code = result.error?.code ?? result.error_code ?? '';
  const message = result.error?.message ?? result.message ?? 'رفض الخادم الطلب';
  if (code === 'PERMISSION_DENIED' || code === 'UNAUTHORIZED_OFFER_ACTION') {
    return 'رُفض الإجراء: هذا التصرف يتطلب صلاحية لا يملكها حسابك ضمن منشأتك.';
  }
  if (code === 'FEATURE_DISABLED') {
    return 'هذه الميزة موقوفة من إعدادات المنصة، فلا ينفَّذ الإجراء الآن.';
  }
  if (code === 'INVALID_OFFER_TRANSITION') {
    return `مسار العرض لا يسمح بهذه الحالة: ${message}`;
  }
  if (code === 'CURRENCY_NOT_ACTIVE') {
    return 'لم تُعتمد عملة للمنصة بعد، والعرض يحتاج عملة فعّالة؛ يضبطها مسؤول المنصة أولاً.';
  }
  return code ? `${code}: ${message}` : message;
}

/** A validation message, shown under the field it belongs to. */
const FieldNote: React.FC<{ message: string | undefined }> = ({ message }) => {
  if (!message) return null;
  return <p className="text-[10px] text-rose-700 font-semibold pt-0.5">{message}</p>;
};

/**
 * A form on a phone is one long scroll, so each block gets three words that say
 * what it is for. The heading carries no rule the server enforces — it only
 * groups fields that were already grouped by what they answer.
 */
const FormSection: React.FC<{ title: string }> = ({ title }) => (
  <p className="-mb-2 text-[11px] font-black text-slate-500">{title}</p>
);

export const SellerInventoryView: React.FC<SellerInventoryViewProps> = ({ currentPersonaId }) => {
  const config = usePlatformConfig();
  const locale = getLocale();

  const [offers, setOffers] = useState<Offer[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [pagination, setPagination] = useState<Pagination | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [shelfError, setShelfError] = useState<string | null>(null);
  const [actionMessage, setActionMessage] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [editingOffer, setEditingOffer] = useState<Offer | null>(null);

  // New offer
  const [showNewOfferModal, setShowNewOfferModal] = useState(false);
  const [selectedProductId, setSelectedProductId] = useState<number | ''>('');
  const [variants, setVariants] = useState<ProductVariant[]>([]);
  const [selectedVariantId, setSelectedVariantId] = useState<number | ''>('');
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<number | ''>('');
  const [titleAr, setTitleAr] = useState('');
  const [descriptionAr, setDescriptionAr] = useState('');
  const [totalQuantity, setTotalQuantity] = useState('');
  const [minOrderQty, setMinOrderQty] = useState('');
  const [maxOrderQty, setMaxOrderQty] = useState('');
  const [unit, setUnit] = useState('');
  const [unitPrice, setUnitPrice] = useState('');
  const [opportunityReason, setOpportunityReason] = useState('');
  const [reasonNotes, setReasonNotes] = useState('');
  const [deliveryTerms, setDeliveryTerms] = useState<'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH'>('BOTH');
  const [productionDate, setProductionDate] = useState('');
  const [expiryDate, setExpiryDate] = useState('');
  const [storageCondition, setStorageCondition] = useState('');
  const [asDraft, setAsDraft] = useState(false);
  /**
   * Whether this listing names the business on the market. Default shown: a
   * seller who never meets the control publishes exactly as they did before it
   * existed. It is per-listing on purpose — the same factory may want its surplus
   * anonymous and its clearance named.
   */
  const [showIdentity, setShowIdentity] = useState(true);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [offerError, setOfferError] = useState<string | null>(null);

  // New warehouse
  const [showNewWarehouseModal, setShowNewWarehouseModal] = useState(false);
  /** null while creating; the id of the location whose row `PUT /warehouses/{id}` will patch. */
  const [whEditingId, setWhEditingId] = useState<number | null>(null);
  const [whName, setWhName] = useState('');
  const [whCode, setWhCode] = useState('');
  const [whCity, setWhCity] = useState('');
  const [whDistrict, setWhDistrict] = useState('');
  const [whAddress, setWhAddress] = useState('');
  const [whLat, setWhLat] = useState('');
  const [whLon, setWhLon] = useState('');
  const [whContact, setWhContact] = useState('');
  const [whPhone, setWhPhone] = useState('');
  const [warehouseError, setWarehouseError] = useState<string | null>(null);
  const [geoBusy, setGeoBusy] = useState(false);
  const [geoNote, setGeoNote] = useState<string | null>(null);

  const loadOffers = async (targetPage: number) => {
    setLoading(true);
    setShelfError(null);
    try {
      const [offRes, whRes, prodRes] = await Promise.all([
        api.getMyOffers({ page: targetPage }),
        api.getWarehouses(),
        api.getProducts(),
      ]);
      if (offRes.success && Array.isArray(offRes.data)) {
        setOffers((offRes.data as Offer[]).map(normalizeOffer));
        setPagination(offRes.pagination ?? null);
      } else {
        // A shelf the account may not read is a fact about permissions, not an empty shelf.
        setOffers([]);
        setPagination(null);
        setShelfError(refusalOf(offRes));
      }
      setWarehouses(whRes.success && Array.isArray(whRes.data) ? (whRes.data as Warehouse[]) : []);
      setProducts(prodRes.success && Array.isArray(prodRes.data) ? (prodRes.data as Product[]) : []);
    } catch (err) {
      console.error(err);
      setShelfError('تعذّر الاتصال بالمنصة؛ لم تُجلب بيانات الرف.');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    setPage(1);
    loadOffers(1);
  }, [currentPersonaId]);

  // A chosen product loads its real variants and carries the unit the product is
  // counted in — a recorded fact, not a value this form invents.
  useEffect(() => {
    if (!selectedProductId) {
      setVariants([]);
      setSelectedVariantId('');
      return;
    }
    const product = products.find((p) => p.id === Number(selectedProductId));
    if (product?.unit) setUnit((previous) => previous || product.unit);
    api.getProductVariants(Number(selectedProductId)).then((res) => {
      const list = res.success && Array.isArray(res.data) ? (res.data as ProductVariant[]) : [];
      setVariants(list);
      setSelectedVariantId(list[0]?.id ?? '');
    });
  }, [selectedProductId, products]);

  /**
   * Escape dismisses whichever sheet is open — a phone user has no back button
   * for a dialog, and a half-filled form must never feel like a trap.
   */
  useEffect(() => {
    if (!showNewOfferModal && !showNewWarehouseModal) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      setShowNewOfferModal(false);
      setShowNewWarehouseModal(false);
      setOfferError(null);
      setFieldErrors({});
      setWarehouseError(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [showNewOfferModal, showNewWarehouseModal]);

  const runAction = async (label: string, fn: () => Promise<ApiResult<unknown>>) => {
    setBusy(true);
    setActionMessage(null);
    try {
      const result = await fn();
      setActionMessage(
        result.success
          ? `${label}: ${result.message ?? 'تم'}`
          : `تعذّر «${label}» — ${refusalOf(result)}`
      );
    } catch (err) {
      console.error(err);
      setActionMessage(`تعذّر «${label}»: لم يستجب الخادم.`);
    } finally {
      setBusy(false);
      await loadOffers(page);
    }
  };

  const resetOfferForm = () => {
    setSelectedProductId('');
    setSelectedVariantId('');
    setVariants([]);
    setTitleAr('');
    setDescriptionAr('');
    setTotalQuantity('');
    setMinOrderQty('');
    setMaxOrderQty('');
    setUnit('');
    setUnitPrice('');
    setOpportunityReason('');
    setReasonNotes('');
    setDeliveryTerms('BOTH');
    setProductionDate('');
    setExpiryDate('');
    setStorageCondition('');
    setAsDraft(false);
    setShowIdentity(true);
    setFieldErrors({});
    setOfferError(null);
  };

  const handleCreateOffer = async (e: React.FormEvent) => {
    e.preventDefault();
    setOfferError(null);
    setFieldErrors({});

    // The server's own rules, applied here so the seller is not sent on a round
    // trip for what this form can already see.
    const problems: Record<string, string> = {};
    if (!selectedVariantId) problems.product_variant_id = 'اختر الصنف والعبوة.';
    if (!selectedWarehouseId) problems.warehouse_id = 'اختر مستودعاً تابعاً لمنشأتك.';
    if (!titleAr.trim()) problems.title_ar = 'عنوان العرض مطلوب.';
    if (!opportunityReason) problems.opportunity_reason = 'اختر سبب طرح المخزون من قائمة المنصة.';
    if (!unit.trim()) problems.unit = 'حدِّد وحدة العدّ (كرتونة، باليت، كيس...).';
    const total = Number(totalQuantity);
    const min = Number(minOrderQty);
    const price = Number(unitPrice);
    if (!totalQuantity.trim() || !(total >= 1)) problems.total_quantity = 'الكمية الكلية رقم لا يقل عن 1.';
    if (!minOrderQty.trim() || !(min >= 1)) problems.min_order_quantity = 'الحد الأدنى للطلب رقم لا يقل عن 1.';
    else if (total >= 1 && min > total) problems.min_order_quantity = 'الحد الأدنى لا يتجاوز الكمية الكلية.';
    if (!unitPrice.trim() || !(price >= 0.0001)) problems.unit_price = 'سعر الوحدة رقم أكبر من صفر.';
    if (maxOrderQty.trim() && Number(maxOrderQty) < min) {
      problems.max_order_quantity = 'السقف الاختياري لا يقل عن الحد الأدنى.';
    }
    if (Object.keys(problems).length > 0) {
      setFieldErrors(problems);
      setOfferError('راجع الحقول المميّزة بالأحمر قبل الإرسال.');
      return;
    }

    const payload: Record<string, unknown> = {
      product_variant_id: Number(selectedVariantId),
      warehouse_id: Number(selectedWarehouseId),
      title_ar: titleAr.trim(),
      opportunity_reason: opportunityReason,
      total_quantity: total,
      min_order_quantity: min,
      unit: unit.trim(),
      unit_price: price,
      delivery_terms: deliveryTerms,
      as_draft: asDraft,
      show_seller_identity: showIdentity,
    };
    if (descriptionAr.trim()) payload.description_ar = descriptionAr.trim();
    if (maxOrderQty.trim()) payload.max_order_quantity = Number(maxOrderQty);
    if (reasonNotes.trim()) payload.opportunity_reason_notes = reasonNotes.trim();
    if (productionDate) payload.production_date = productionDate;
    if (expiryDate) payload.expiry_date = expiryDate;
    if (storageCondition.trim()) payload.storage_condition = storageCondition.trim();

    setBusy(true);
    const res = await api.createOffer(payload);
    setBusy(false);

    if (res.success) {
      const createdStatus = res.data ? STATUS_LABELS[res.data.status] : null;
      resetOfferForm();
      setShowNewOfferModal(false);
      setActionMessage(
        `أُضيف العرض${createdStatus ? ` وحالته: ${createdStatus}` : ''}.` +
          (createdStatus === STATUS_LABELS.PENDING_REVIEW
            ? ' النشر قرار المنصة بعد المراجعة، لا إجراء البائع.'
            : '')
      );
      setPage(1);
      await loadOffers(1);
      return;
    }

    const serverErrors: Record<string, string> = {};
    for (const field of OFFER_FIELDS) {
      const message = fieldError(res, field);
      if (message) serverErrors[field] = message;
    }
    setFieldErrors(serverErrors);
    setOfferError(refusalOf(res));
  };

  /** Back to the create state, so an abandoned edit cannot reopen as a new row. */
  const resetWarehouseFields = () => {
    setWhEditingId(null);
    setWhName('');
    setWhCode('');
    setWhCity('');
    setWhDistrict('');
    setWhAddress('');
    setWhLat('');
    setWhLon('');
    setWhContact('');
    setWhPhone('');
    setGeoNote(null);
  };

  /**
   * One modal, two jobs.
   *
   * With no argument it clears the fields and creates; with a warehouse it
   * prefills from what the platform holds and patches that row. The coordinates
   * come along either way because `PUT /warehouses/{id}` replaces the record
   * rather than merging into it — a form that sent no latitude would not leave
   * the old one in place.
   */
  const openWarehouseModal = (warehouse?: Warehouse) => {
    setWarehouseError(null);
    setGeoNote(null);

    if (!warehouse) {
      resetWarehouseFields();
    } else {
      setWhEditingId(warehouse.id);
      setWhName(warehouse.name ?? '');
      setWhCode(warehouse.code ?? '');
      setWhCity(warehouse.city ?? '');
      setWhDistrict(warehouse.district ?? '');
      setWhAddress(warehouse.address_line ?? '');
      setWhLat(warehouse.latitude === null || warehouse.latitude === undefined ? '' : String(warehouse.latitude));
      setWhLon(warehouse.longitude === null || warehouse.longitude === undefined ? '' : String(warehouse.longitude));
      setWhContact(warehouse.contact_name ?? '');
      setWhPhone(warehouse.contact_phone ?? '');
    }

    setShowNewWarehouseModal(true);
  };

  /**
   * Ask the browser where this device is.
   *
   * The reading is offered, never assumed: the seller may refuse the permission,
   * the browser may time out, or it may have no location to give — and in every
   * one of those cases the coordinates stay typed-in and editable, because a
   * warehouse is a place the platform holds, not a place this device happens to
   * be. Accuracy is deliberately not high-precision: a loading bay is found to
   * within a few metres and a GPS lock costs battery and seconds.
   */
  const readBrowserLocation = () => {
    if (!('geolocation' in navigator)) {
      setGeoNote('لا يقدّم هذا المتصفح موقعًا؛ أدخل الإحداثيات يدويًا.');
      return;
    }

    setGeoBusy(true);
    setGeoNote('يقرأ موقع الجهاز…');

    navigator.geolocation.getCurrentPosition(
      (position) => {
        setGeoBusy(false);
        setWhLat(position.coords.latitude.toFixed(6));
        setWhLon(position.coords.longitude.toFixed(6));
        setGeoNote(
          `قرأ المتصفح: ${position.coords.latitude.toFixed(5)}، ${position.coords.longitude.toFixed(5)}`
          + ` (بدقة تقريبية ${Math.round(position.coords.accuracy)} م). راجع الرقمين قبل الحفظ.`,
        );
      },
      (error) => {
        setGeoBusy(false);
        setGeoNote(
          error.code === error.PERMISSION_DENIED
            ? 'رفض المتصفح مشاركة الموقع. أدخل الإحداثيات يدويًا أو امنح المتصفح الإذن ثم أعد المحاولة.'
            : error.code === error.TIMEOUT
              ? 'انتهت مهلة قراءة الموقع؛ يمكن إدخال الإحداثيات يدويًا.'
              : 'لم يعثر المتصفح على موقع الآن؛ يمكن إدخال الإحداثيات يدويًا.',
        );
      },
      { enableHighAccuracy: false, timeout: 8000, maximumAge: 60000 },
    );
  };

  /** What either write sends: the same fields, since the update route replaces. */
  const warehousePayload = (): Partial<Warehouse> => ({
    name: whName.trim(),
    city: whCity.trim(),
    district: whDistrict.trim() || null,
    address_line: whAddress.trim(),
    latitude: Number(whLat),
    longitude: Number(whLon),
    contact_name: whContact.trim() || null,
    contact_phone: whPhone.trim() || null,
    ...(whCode.trim() ? { code: whCode.trim() } : {}),
  });

  const handleSaveWarehouse = async (e: React.FormEvent) => {
    e.preventDefault();
    setWarehouseError(null);

    const missing: string[] = [];
    if (!whName.trim()) missing.push('اسم المستودع');
    if (!whCity.trim()) missing.push('المدينة');
    if (!whAddress.trim()) missing.push('العنوان التفصيلي');
    if (!whLat.trim() || Number.isNaN(Number(whLat))) missing.push('خط العرض');
    if (!whLon.trim() || Number.isNaN(Number(whLon))) missing.push('خط الطول');
    if (missing.length > 0) {
      setWarehouseError(`الخادم يطلب هذه الحقول: ${missing.join('، ')}.`);
      return;
    }

    setBusy(true);
    const wasCreating = whEditingId === null;
    const res = wasCreating
      ? await api.createWarehouse(warehousePayload())
      : await api.updateWarehouse(whEditingId as number, warehousePayload());
    setBusy(false);

    if (res.success) {
      setShowNewWarehouseModal(false);
      resetWarehouseFields();
      setWarehouseError(null);
      setActionMessage(
        wasCreating
          ? 'سُجِّل المستودع بالموقع الذي أدخلته.'
          : 'حُدِّث المستودع بما في ذلك إحداثياته؛ البحث القريب يقرأ الموقع الجديد.',
      );
      await loadOffers(page);
    } else {
      setWarehouseError(refusalOf(res));
    }
  };

  const reasonOptions = config.opportunityReasons;

  return (
    <div className="mx-auto w-full max-w-7xl space-y-6 px-3 py-6 sm:px-6 lg:px-8">
      {/* Header */}
      <div className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-2xs sm:flex sm:items-start sm:justify-between sm:gap-4">
        <div className="min-w-0">
          <h2 className="text-lg font-black text-slate-900 font-serif">عروض منشأتي ومستودعاتها</h2>
          <p className="text-xs leading-relaxed text-slate-500">
            كل ما طرحته المنشأة من مخزون بحالته لدى المنصة، مع الكمية المتاحة والمحجوزة لكل عرض.
            النشر في السوق قرارٌ للمنصة حين تكون المراجعة مفعّلة، والسحب يبقي الأثر في السجل.
          </p>
        </div>

        <div className="grid shrink-0 grid-cols-2 gap-2 sm:flex sm:flex-nowrap">
          <button
            onClick={() => openWarehouseModal()}
            className="flex min-w-0 items-center justify-center gap-1.5 rounded-lg bg-slate-100 px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-200 cursor-pointer"
          >
            <WarehouseIcon className="w-4 h-4 shrink-0 text-slate-600" />
            <span className="truncate">تسجيل مستودع</span>
          </button>

          <button
            onClick={() => {
              setShowNewOfferModal(true);
              if (warehouses.length > 0 && !selectedWarehouseId) setSelectedWarehouseId(warehouses[0].id);
            }}
            className="flex min-w-0 items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-3.5 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 cursor-pointer"
          >
            <PlusCircle className="w-4 h-4 shrink-0" />
            <span className="truncate">طرح عرض جديد</span>
          </button>
        </div>
      </div>

      {actionMessage && (
        <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2.5 text-xs text-emerald-900">
          <span className="min-w-0 flex-1 leading-relaxed">{actionMessage}</span>
          <button
            onClick={() => setActionMessage(null)}
            aria-label="إغلاق الرسالة"
            className="shrink-0 rounded-lg p-1 font-bold text-emerald-700 hover:bg-emerald-100 cursor-pointer"
          >
            <X className="w-4 h-4" />
          </button>
        </div>
      )}

      {/* Warehouses */}
      <div className="min-w-0 space-y-3">
        <h3 className="flex items-center gap-1.5 text-xs font-bold text-slate-700">
          <WarehouseIcon className="w-4 h-4 text-emerald-700" />
          <span>مستودعات المنشأة ({warehouses.length})</span>
        </h3>

        {warehouses.length === 0 ? (
          <div className="bg-white border border-slate-200 rounded-xl p-6 text-center text-slate-500 text-xs leading-relaxed">
            لا مستودعات مسجَّلة لهذه المنشأة. سجّل مستودعاً بموقعه الحقيقي، لأن الترتيب حسب القرب
            يعتمد على الإحداثيات المخزَّنة لا على اسم المدينة.
          </div>
        ) : (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            {warehouses.map((w) => (
              <div key={w.id} className="min-w-0 space-y-2 rounded-xl border border-slate-200 bg-white p-3.5 text-xs shadow-2xs">
                <div className="flex min-w-0 items-start justify-between gap-2">
                  <span className="min-w-0 font-bold text-slate-900" title={w.name ?? ''}>
                    <span className="line-clamp-1">{w.name}</span>
                  </span>
                  <span className="shrink-0 rounded bg-slate-100 px-2 py-0.5 text-[10px] text-slate-600">{w.city}</span>
                </div>
                <div className="flex min-w-0 items-start gap-1 text-[11px] text-slate-500">
                  <MapPin className="h-3 w-3 shrink-0 text-emerald-600" />
                  <span className="min-w-0 break-words">{w.address_line || 'عنوان غير مسجَّل'}</span>
                </div>
                <div className="flex min-w-0 flex-wrap items-center justify-between gap-x-2 gap-y-1 border-t border-slate-100 pt-1 text-[11px] text-slate-600">
                  <span className="min-w-0">{w.contact_name ? `المسؤول: ${w.contact_name}` : 'لا مسؤول مسجَّل'}</span>
                  <span className="shrink-0 font-mono" dir="ltr">
                    {w.contact_phone || '—'}
                  </span>
                </div>
                <div className="flex min-w-0 flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-1">
                  <span className="min-w-0 flex-1 basis-32 text-[10px] leading-relaxed text-slate-400">
                    {w.latitude === null || w.longitude === null
                      ? 'بلا إحداثيات: لا يظهر في نتائج القرب'
                      : `الإحداثيات: ${w.latitude}، ${w.longitude}`}
                  </span>
                  <button
                    onClick={() => openWarehouseModal(w)}
                    className="shrink-0 rounded-lg border border-slate-300 px-2.5 py-1.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100 cursor-pointer"
                  >
                    تصحيح
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </div>

      <StockMovementsPanel offers={offers} reloadKey={`${currentPersonaId}:${page}`} />

      {/* The shelf */}
      <div className="min-w-0 space-y-3">
        <h3 className="flex flex-wrap items-center gap-1.5 text-xs font-bold text-slate-700">
          <Package className="w-4 h-4 text-emerald-700" />
          <span>
            عروضك ({pagination ? pagination.total_items : offers.length})
            {pagination && pagination.total_pages > 1
              ? ` — صفحة ${pagination.current_page} من ${pagination.total_pages}`
              : ''}
          </span>
        </h3>

        {shelfError && (
          <div className="text-xs rounded-lg px-3 py-2.5 border bg-rose-50 border-rose-200 text-rose-900 flex items-start gap-2">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span className="min-w-0">{shelfError}</span>
          </div>
        )}

        {loading ? (
          <div className="py-12 text-center text-slate-500 text-xs">جاري جلب الرف...</div>
        ) : offers.length === 0 && !shelfError ? (
          <div className="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500 text-xs leading-relaxed">
            لا عروض للمنشأة بعد. اضغط «طرح عرض جديد» لتسجيل مخزونك بمكان تخزينه وسبب طرحه.
          </div>
        ) : (
          <div className="min-w-0 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-2xs">
            <table className="w-full min-w-[56rem] text-xs text-right">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
                <tr>
                  <th className="p-3">العرض والصنف</th>
                  <th className="p-3">مستودع التخزين</th>
                  <th className="p-3">المتاح</th>
                  <th className="p-3">المحجوز</th>
                  <th className="p-3">سعر الوحدة</th>
                  <th className="p-3">حدّ الطلب</th>
                  <th className="p-3">سبب الطرح</th>
                  <th className="p-3">الحالة</th>
                  <th className="p-3">إجراءاتك</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {offers.map((o) => (
                  <tr key={o.id} className="hover:bg-slate-50/70 transition-colors align-top">
                    <td className="p-3">
                      <div className="max-w-[16rem] font-bold text-slate-900" title={o.title_ar}>
                        {o.title_ar}
                      </div>
                      <div className="text-[11px] text-slate-500">
                        {[o.product_name_ar, o.variant_name_ar || o.variant_sku].filter(Boolean).join(' · ') || '—'}
                      </div>
                      <div className="text-[10px] text-slate-400">
                        التسليم: {DELIVERY_TRANSLATION[o.delivery_terms] ?? o.delivery_terms}
                      </div>
                    </td>
                    <td className="p-3 text-slate-600">
                      <div>{o.warehouse_name || '—'}</div>
                      <div className="text-[10px] text-slate-400">{o.warehouse_city || ''}</div>
                    </td>
                    <td className="p-3 font-bold text-emerald-800">
                      {o.available_quantity} {o.unit}
                    </td>
                    <td className="p-3 font-semibold text-amber-700">
                      {o.reserved_quantity} {o.unit}
                    </td>
                    <td className="p-3 font-black font-serif text-slate-900">
                      {formatMoney(o.unit_price, o.currency_code)}
                    </td>
                    <td className="p-3 text-slate-600 text-[11px]">
                      أدنى {o.min_order_quantity} {o.unit}
                      {o.max_order_quantity ? ` · أقصى ${o.max_order_quantity}` : ''}
                    </td>
                    <td className="p-3">
                      <span className="text-[10px] font-semibold bg-slate-100 text-slate-700 px-2 py-0.5 rounded">
                        {opportunityReasonLabel(o.opportunity_reason, locale)}
                      </span>
                    </td>
                    <td className="p-3">
                      <span
                        className={`text-[10px] font-bold px-2 py-0.5 rounded-full whitespace-nowrap ${
                          STATUS_STYLES[o.status] ?? 'bg-slate-100 text-slate-700'
                        }`}
                      >
                        {STATUS_LABELS[o.status] ?? o.status}
                      </span>
                      {o.expiry_date && formatDate(o.expiry_date, locale) && (
                        <div className="text-[10px] text-slate-400 pt-1">الصلاحية: {formatDate(o.expiry_date, locale)}</div>
                      )}
                    </td>
                    <td className="p-3">
                      <div className="flex min-w-[9rem] flex-wrap items-center gap-1.5">
                        {o.status === 'DRAFT' && (
                          <>
                            <button
                              disabled={busy}
                              onClick={() => setEditingOffer(o)}
                              className="bg-white border border-slate-300 hover:bg-slate-100 disabled:opacity-40 text-slate-700 whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Package className="w-3 h-3" />
                              <span>تعديل المسودة</span>
                            </button>
                            <button
                              disabled={busy}
                              onClick={() => runAction('رفع العرض للمراجعة', () => api.submitOffer(o.id))}
                              className="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-40 text-white whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Send className="w-3 h-3" />
                              <span>رفع للمراجعة</span>
                            </button>
                            <button
                              disabled={busy}
                              onClick={() => runAction('حذف المسودة', () => api.deleteOffer(o.id))}
                              className="bg-white border border-slate-300 hover:bg-slate-100 disabled:opacity-40 text-slate-700 whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Trash2 className="w-3 h-3" />
                              <span>حذف المسودة</span>
                            </button>
                          </>
                        )}

                        {o.status === 'PENDING_REVIEW' && (
                          <span className="basis-full text-[10px] leading-relaxed text-slate-500">
                            القرار بيد مراجع المنصة.
                          </span>
                        )}

                        {o.status === 'ACTIVE' && (
                          <>
                            <button
                              disabled={busy}
                              onClick={() =>
                                runAction('إيقاف العرض مؤقتاً', () =>
                                  api.setOfferStatus(o.id, 'PAUSED', 'إيقاف من البائع')
                                )
                              }
                              className="bg-amber-50 text-amber-700 hover:bg-amber-100 disabled:opacity-40 whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Pause className="w-3 h-3" />
                              <span>إيقاف مؤقت</span>
                            </button>

                            <p className="basis-full text-[10px] leading-relaxed text-slate-400">
                              تعديل بيانات العرض متاح بعد إيقافه: لا تُغَيَّر بطاقةٌ وهي في السوق.
                            </p>
                          </>
                        )}

                        {o.status === 'PAUSED' && (
                          <>
                            <button
                              disabled={busy}
                              onClick={() => setEditingOffer(o)}
                              className="bg-white border border-slate-300 hover:bg-slate-100 disabled:opacity-40 text-slate-700 whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Package className="w-3 h-3" />
                              <span>تعديل العرض الموقوف</span>
                            </button>
                            <button
                              disabled={busy}
                              onClick={() => runAction('استئناف العرض', () => api.setOfferStatus(o.id, 'ACTIVE'))}
                              className="bg-emerald-50 text-emerald-700 hover:bg-emerald-100 disabled:opacity-40 whitespace-nowrap px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center justify-center gap-1 cursor-pointer"
                            >
                              <Play className="w-3 h-3" />
                              <span>استئناف</span>
                            </button>
                          </>
                        )}

                        {(o.status === 'ACTIVE' || o.status === 'PAUSED') && (
                          <button
                            disabled={busy}
                            onClick={() =>
                              runAction('سحب العرض', () =>
                                api.setOfferStatus(o.id, 'CANCELLED', 'سحب من البائع')
                              )
                            }
                            className="whitespace-nowrap rounded-md border border-rose-200 bg-white px-2.5 py-1 text-[11px] font-bold text-rose-700 hover:bg-rose-50 disabled:opacity-40 cursor-pointer"
                          >
                            سحب من السوق
                          </button>
                        )}

                        {(o.status === 'SOLD_OUT' || o.status === 'EXPIRED' || o.status === 'CANCELLED') && (
                          <span className="basis-full text-[10px] leading-relaxed text-slate-400">
                            لا إجراءات: التزويد عرض جديد، والسجل يبقى كما هو.
                          </span>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {pagination && pagination.total_pages > 1 && (
          <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-600">
            <button
              disabled={busy || pagination.current_page <= 1}
              onClick={() => {
                const next = pagination.current_page - 1;
                setPage(next);
                loadOffers(next);
              }}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40 cursor-pointer"
            >
              السابقة
            </button>
            <span className="min-w-0 basis-full text-center sm:basis-auto">
              {pagination.total_items} عرضاً — صفحة {pagination.current_page} من {pagination.total_pages}
            </span>
            <button
              disabled={busy || !pagination.has_more}
              onClick={() => {
                const next = pagination.current_page + 1;
                setPage(next);
                loadOffers(next);
              }}
              className="rounded-lg border border-slate-300 bg-white px-3 py-2 font-bold disabled:opacity-40 cursor-pointer"
            >
              التالية
            </button>
          </div>
        )}
      </div>

      {/* New offer */}
      {showNewOfferModal && (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 backdrop-blur-[2px] sm:items-center sm:p-4"
          role="dialog"
          aria-modal="true"
          onClick={(event) => {
            if (event.target !== event.currentTarget) return;
            setShowNewOfferModal(false);
            setOfferError(null);
            setFieldErrors({});
          }}
        >
          <div className="max-h-[92dvh] w-full space-y-4 overflow-y-auto rounded-t-2xl border border-slate-200 bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-xl sm:rounded-2xl sm:p-6">
            <div className="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
              <div className="min-w-0">
                <h3 className="text-base font-bold text-slate-900">طرح عرض مخزون</h3>
                <p className="pt-0.5 text-[11px] leading-relaxed text-slate-500">
                  الحقول المعلَّمة «مطلوب» لا يقبل الخادم عرضاً بدونها، وسبب رفض أي حقل يظهر تحته.
                </p>
              </div>
              <button
                onClick={() => {
                  setShowNewOfferModal(false);
                  setOfferError(null);
                  setFieldErrors({});
                }}
                aria-label="إغلاق"
                className="-m-1 shrink-0 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {offerError && (
              <div className="p-3 bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-lg flex items-start gap-2">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>{offerError}</span>
              </div>
            )}

            <div
              className={`rounded-lg border px-3 py-2 text-[11px] leading-relaxed ${
                config.loaded && config.baseCurrency === null
                  ? 'border-amber-200 bg-amber-50 text-amber-900'
                  : 'border-slate-200 bg-slate-50 text-slate-600'
              }`}
            >
              العملة: {currencyLabel(config.baseCurrency)}
              {!config.loaded
                ? ' — تُقرأ من إعدادات المنصة.'
                : config.baseCurrency === null
                  ? ' — لم يضبط مسؤول المنصة عملة أساسية، والعرض يحتاج عملة معتمدة، لذا يرفضه الخادم حتى تُضبط.'
                  : ''}
            </div>

            <form onSubmit={handleCreateOffer} className="space-y-4 text-xs">
              <FormSection title="ما تُعرضه" />

              <div className="space-y-1">
                <label className="font-bold text-slate-700">عنوان العرض (مطلوب):</label>
                <input
                  type="text"
                  value={titleAr}
                  onChange={(e) => setTitleAr(e.target.value)}
                  placeholder="مثال: 240 كرتونة أرز 10 كجم — دفعة واحدة"
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
                <FieldNote message={fieldErrors.title_ar} />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المنتج:</label>
                  <select
                    value={selectedProductId}
                    onChange={(e) => setSelectedProductId(e.target.value ? Number(e.target.value) : '')}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    <option value="">— اختر منتجاً —</option>
                    {products.map((p) => (
                      <option key={p.id} value={p.id}>
                        {locale === 'en' && p.name_en ? p.name_en : p.name_ar}
                      </option>
                    ))}
                  </select>
                  {products.length === 0 && (
                    <p className="text-[10px] text-slate-400">لا منتجات في الكتالوج المرئية لهذا الحساب.</p>
                  )}
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الصنف والعبوة (مطلوب):</label>
                  <select
                    value={selectedVariantId}
                    onChange={(e) => setSelectedVariantId(e.target.value ? Number(e.target.value) : '')}
                    disabled={!selectedProductId}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none disabled:opacity-50"
                  >
                    {variants.map((v) => (
                      <option key={v.id} value={v.id}>
                        {(locale === 'en' && v.name_en ? v.name_en : v.name_ar) || v.sku} (SKU: {v.sku})
                      </option>
                    ))}
                  </select>
                  <FieldNote message={fieldErrors.product_variant_id} />
                </div>
              </div>

              <p className="flex items-start gap-1.5 text-[10px] leading-relaxed text-slate-500">
                <Camera className="mt-0.5 w-3.5 h-3.5 shrink-0 text-emerald-700" />
                <span>
                  صورة الصنف في السوق يقرؤها العرض من الكتالوج: تُرفع من شاشة «الكتالوج» بزرّ «صور الصنف» في سطر
                  الصنف، فلا يوجد حقل صورة هنا — العرض يشير إلى الصنف ولا يملك صوراً مستقلة عنه.
                </span>
              </p>

              <FormSection title="المكان وسبب الطرح" />

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">مستودع التخزين (مطلوب):</label>
                  <select
                    value={selectedWarehouseId}
                    onChange={(e) => setSelectedWarehouseId(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    <option value="">— اختر مستودعاً —</option>
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.city})
                      </option>
                    ))}
                  </select>
                  <FieldNote message={fieldErrors.warehouse_id} />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">سبب طرح المخزون (مطلوب):</label>
                  <select
                    value={opportunityReason}
                    onChange={(e) => setOpportunityReason(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    <option value="">— اختر السبب —</option>
                    {reasonOptions.map((r) => (
                      <option key={r.code} value={r.code}>
                        {locale === 'en' ? r.label_en : r.label_ar}
                      </option>
                    ))}
                  </select>
                  {reasonOptions.length === 0 && (
                    <p className="text-[10px] text-slate-400">
                      قائمة الأسباب تأتي من إعدادات المنصة ولم تُقرأ بعد — أعد تحميل الصفحة.
                    </p>
                  )}
                  <FieldNote message={fieldErrors.opportunity_reason} />
                </div>

                <div className="space-y-1 sm:col-span-2">
                  <label className="font-bold text-slate-700">تفصيل سبب الطرح (اختياري):</label>
                  <input
                    type="text"
                    maxLength={500}
                    value={reasonNotes}
                    onChange={(e) => setReasonNotes(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.opportunity_reason_notes} />
                </div>
              </div>

              <FormSection title="الكمية والسعر" />

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الكمية الكلية (مطلوب):</label>
                  <input
                    type="number"
                    step="0.01"
                    value={totalQuantity}
                    onChange={(e) => setTotalQuantity(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.total_quantity} />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">وحدة العدّ (مطلوب):</label>
                  <input
                    type="text"
                    maxLength={50}
                    value={unit}
                    onChange={(e) => setUnit(e.target.value)}
                    placeholder="كرتونة / باليت / كيس"
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.unit} />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">سعر الوحدة (مطلوب):</label>
                  <input
                    type="number"
                    step="0.0001"
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold text-emerald-800 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.unit_price} />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الحد الأدنى للطلب (مطلوب):</label>
                  <input
                    type="number"
                    step="0.01"
                    value={minOrderQty}
                    onChange={(e) => setMinOrderQty(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.min_order_quantity} />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الحد الأقصى للطلب (اختياري):</label>
                  <input
                    type="number"
                    step="0.01"
                    value={maxOrderQty}
                    onChange={(e) => setMaxOrderQty(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.max_order_quantity} />
                </div>
              </div>

              <FormSection title="التسليم والتخزين" />

              <div className="space-y-1">
                <label className="font-bold text-slate-700">شروط التسليم المتاحة للمشترين:</label>
                <div className="flex flex-wrap gap-2 pt-0.5">
                  {(['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'] as const).map((value) => (
                    <button
                      key={value}
                      type="button"
                      onClick={() => setDeliveryTerms(value)}
                      className={`rounded-lg border px-3 py-1.5 text-[11px] font-bold cursor-pointer ${
                        deliveryTerms === value
                          ? 'bg-emerald-50 border-emerald-300 text-emerald-800'
                          : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                      }`}
                    >
                      {DELIVERY_TRANSLATION[value]}
                    </button>
                  ))}
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">تاريخ الإنتاج (اختياري):</label>
                  <input
                    type="date"
                    value={productionDate}
                    onChange={(e) => setProductionDate(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">تاريخ الصلاحية (اختياري):</label>
                  <input
                    type="date"
                    value={expiryDate}
                    onChange={(e) => setExpiryDate(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                  <FieldNote message={fieldErrors.expiry_date} />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">ظروف التخزين (اختياري):</label>
                  <input
                    type="text"
                    maxLength={50}
                    value={storageCondition}
                    onChange={(e) => setStorageCondition(e.target.value)}
                    placeholder="جاف / مبرّد"
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <FormSection title="وصف وخيارات النشر" />

              <div className="space-y-1">
                <label className="font-bold text-slate-700">وصف العرض (اختياري):</label>
                <textarea
                  value={descriptionAr}
                  onChange={(e) => setDescriptionAr(e.target.value)}
                  rows={2}
                  maxLength={2000}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
                <FieldNote message={fieldErrors.description_ar} />
              </div>

              <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] font-semibold text-slate-700">
                <input
                  type="checkbox"
                  checked={showIdentity}
                  onChange={(e) => setShowIdentity(e.target.checked)}
                  className="accent-emerald-600 mt-0.5 shrink-0"
                />
                <span>
                  إظهار اسم منشأتك في هذا العرض على السوق.
                  <span className="block text-[10px] font-normal text-slate-500 leading-relaxed pt-0.5">
                    عند الإخفاء لا يظهر اسم المنشأة ولا رقم السجل التجاري ولا الرقم الضريبي ولا اسم المستودع وعنوانه
                    في بطاقات السوق وصفحة العرض، ويظهر بدلها «بائع لا يُظهر اسمه». يبقى الاسم مرئيًا لمراجِع المنصة،
                    ويصل اسمك الحقيقي إلى المشتري عند وصول طلب الشراء إليكما معًا — لأن من يوقّع صفقة يجب أن يُعرف.
                  </span>
                </span>
              </label>

              <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] font-semibold text-slate-700">
                <input
                  type="checkbox"
                  checked={asDraft}
                  onChange={(e) => setAsDraft(e.target.checked)}
                  className="accent-emerald-600 mt-0.5 shrink-0"
                />
                <span>احفظه مسودةً ولا تطرحه الآن، ثم ارفعه للمراجعة لاحقاً.</span>
              </label>

              <div className="flex items-center gap-3 pt-1">
                <button
                  type="button"
                  onClick={() => setShowNewOfferModal(false)}
                  className="flex-1 rounded-xl bg-slate-100 py-2.5 font-semibold text-slate-700 hover:bg-slate-200 cursor-pointer"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={busy}
                  className="flex flex-[2] items-center justify-center gap-2 rounded-xl bg-emerald-600 py-2.5 font-bold text-white shadow-md hover:bg-emerald-700 disabled:opacity-50 cursor-pointer"
                >
                  {busy ? 'جاري الحفظ...' : asDraft ? 'حفظ المسودة' : 'حفظ العرض وطرحه'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* New warehouse */}
      {showNewWarehouseModal && (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 backdrop-blur-[2px] sm:items-center sm:p-4"
          role="dialog"
          aria-modal="true"
          onClick={(event) => {
            if (event.target !== event.currentTarget) return;
            setShowNewWarehouseModal(false);
            setWarehouseError(null);
          }}
        >
          <div className="max-h-[92dvh] w-full space-y-4 overflow-y-auto rounded-t-2xl border border-slate-200 bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-md sm:rounded-2xl sm:p-6">
            <div className="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
              <div className="min-w-0">
                <h3 className="text-base font-bold text-slate-900">
                  {whEditingId === null ? 'تسجيل مستودع' : 'تصحيح بيانات مستودع'}
                </h3>
                <p className="pt-0.5 text-[10px] leading-relaxed text-slate-500">
                  {whEditingId === null
                    ? 'يُحفظ المستودع بموقعه المسجَّل؛ عليه يعتمد ترتيب «الأقرب إليّ».'
                    : `تعديل المستودع رقم ${whEditingId} كما تحفظه المنصة، والعروض القائمة تبقى منسوبة إليه.`}
                </p>
              </div>
              <button
                onClick={() => setShowNewWarehouseModal(false)}
                aria-label="إغلاق"
                className="-m-1 shrink-0 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 cursor-pointer"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            {warehouseError && (
              <div className="p-3 bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-lg flex items-start gap-2">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>{warehouseError}</span>
              </div>
            )}

            <form onSubmit={handleSaveWarehouse} className="space-y-3 text-xs">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">اسم المستودع (مطلوب):</label>
                <input
                  type="text"
                  value={whName}
                  onChange={(e) => setWhName(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">رمز المستودع (اختياري):</label>
                  <input
                    type="text"
                    maxLength={50}
                    value={whCode}
                    onChange={(e) => setWhCode(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المدينة (مطلوبة):</label>
                  <input
                    type="text"
                    value={whCity}
                    onChange={(e) => setWhCity(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الحي (اختياري):</label>
                  <input
                    type="text"
                    value={whDistrict}
                    onChange={(e) => setWhDistrict(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">العنوان التفصيلي (مطلوب):</label>
                  <input
                    type="text"
                    value={whAddress}
                    onChange={(e) => setWhAddress(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <FormSection title="الموقع والإحداثيات" />

              <div className="space-y-1.5">
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={readBrowserLocation}
                    disabled={geoBusy || busy}
                    className="flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-2 text-[11px] font-bold text-emerald-900 hover:bg-emerald-100 disabled:opacity-50 cursor-pointer"
                  >
                    <Crosshair className="w-3.5 h-3.5" />
                    <span>{geoBusy ? 'يقرأ الموقع…' : 'قراءة موقعي من المتصفح'}</span>
                  </button>
                  {geoNote ? (
                    <span className="basis-full text-[10px] leading-relaxed text-slate-500">{geoNote}</span>
                  ) : null}
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <div className="space-y-1">
                    <label className="font-bold text-slate-700">خط العرض (مطلوب):</label>
                    <input
                      type="text"
                      inputMode="decimal"
                      dir="ltr"
                      value={whLat}
                      onChange={(e) => setWhLat(e.target.value)}
                      className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-left focus:bg-white focus:outline-none"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="font-bold text-slate-700">خط الطول (مطلوب):</label>
                    <input
                      type="text"
                      inputMode="decimal"
                      dir="ltr"
                      value={whLon}
                      onChange={(e) => setWhLon(e.target.value)}
                      className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-left focus:bg-white focus:outline-none"
                    />
                  </div>
                </div>
              </div>
              <p className="text-[10px] text-slate-400 leading-relaxed">
                قراءة المتصفح تقترح رقمين فقط يمكن تصحيحهما قبل الحفظ؛ ما تُخزِّنه المنصة هو ما يرسله هذا النموذج.
                أدخل الإحداثيات من الخريطة أو جهاز الملاحة إن تعذّرت القراءة، فترتيب «الأقرب إليّ» يعتمد على هذين
                الرقمين وحدهما.
              </p>

              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">مسؤول المستودع (اختياري):</label>
                  <input
                    type="text"
                    value={whContact}
                    onChange={(e) => setWhContact(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">رقم الهاتف (اختياري):</label>
                  <input
                    type="text"
                    dir="ltr"
                    value={whPhone}
                    onChange={(e) => setWhPhone(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-left focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="flex items-center gap-3 pt-1">
                <button
                  type="button"
                  onClick={() => setShowNewWarehouseModal(false)}
                  className="flex-1 rounded-xl bg-slate-100 py-2.5 font-semibold text-slate-700 hover:bg-slate-200 cursor-pointer"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={busy}
                  className="flex-[2] rounded-xl bg-emerald-600 py-2.5 font-bold text-white shadow-md hover:bg-emerald-700 disabled:opacity-50 cursor-pointer"
                >
                  {busy
                    ? 'جاري الحفظ...'
                    : whEditingId === null
                      ? 'حفظ المستودع'
                      : 'حفظ التصحيح'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
      {editingOffer && (
        <EditOfferModal
          offer={editingOffer}
          reasons={reasonOptions}
          onClose={() => setEditingOffer(null)}
          onSaved={async (message) => {
            setEditingOffer(null);
            setActionMessage(message);
            await loadOffers(page);
          }}
        />
      )}
    </div>
  );
};

/**
 * What moved on this business's stock, as the platform recorded it.
 *
 * HASSAL keeps quantity in one place — an offer's `available_quantity` — so a
 * movement is always written against an offer, and the two numbers beside it are
 * that offer's available stock before and after. The panel does not compute a
 * per-warehouse balance the schema does not hold.
 */
const MOVEMENT_LABELS: Record<InventoryMovement['type'], string> = {
  IN: 'توريد داخل',
  RESERVED: 'حجز لمشتري',
  RELEASED: 'إطلاق حجز',
  SOLD: 'بيع',
  ADJUSTED: 'تسوية جرد',
  RETURNED: 'مرتجع',
};

const StockMovementsPanel: React.FC<{ offers: Offer[]; reloadKey: string }> = ({ offers, reloadKey }) => {
  const locale = getLocale();
  const [movements, setMovements] = useState<InventoryMovement[]>([]);
  const [state, setState] = useState<'loading' | 'ready' | 'refused'>('loading');
  const [refusal, setRefusal] = useState<string | null>(null);
  const [typeFilter, setTypeFilter] = useState('');

  const [offerId, setOfferId] = useState<number | ''>('');
  const [movementType, setMovementType] = useState<'IN' | 'ADJUSTED' | 'RETURNED'>('IN');
  const [quantity, setQuantity] = useState('');
  const [reason, setReason] = useState('');
  const [formProblems, setFormProblems] = useState<Record<string, string>>({});
  const [formFailure, setFormFailure] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [recordedMessage, setRecordedMessage] = useState<string | null>(null);

  const load = async () => {
    setState('loading');
    const res = await api.getInventoryMovements({ ...(typeFilter ? { type: typeFilter } : {}), per_page: 25 });
    if (res.success && Array.isArray(res.data)) {
      setMovements(res.data as InventoryMovement[]);
      setState('ready');
      setRefusal(null);
    } else {
      setMovements([]);
      setState('refused');
      setRefusal(refusalOf(res));
    }
  };

  useEffect(() => {
    load();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reloadKey, typeFilter]);

  const signed = Number(quantity);
  const canSubmit = offerId !== '' && quantity.trim() !== '' && signed !== 0 && reason.trim().length >= 3;

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFormFailure(null);
    const local: Record<string, string> = {};

    if (offerId === '') local.offer_id = 'اختر العرض الذي تُصحَّح كميته.';
    if (!quantity.trim() || Number.isNaN(signed)) local.quantity = 'اكتب الكمّية رقمًا.';
    else if (signed === 0) local.quantity = 'الحركة بلا كمية لا تُسجَّل: اكتب رقمًا أكبر من صفر.';
    else if (signed < 0 && movementType !== 'ADJUSTED') {
      local.quantity = 'الوارد والمرتجع كمّية موجبة؛ الإنقاص يكون في «تسوية جرد».';
    }
    if (reason.trim().length < 3) local.reason = 'السبب مطلوب ويُحفظ في السجل.';

    setFormProblems(local);
    if (Object.keys(local).length > 0) return;

    setSaving(true);
    const res = await api.adjustInventory({
      offer_id: Number(offerId),
      type: movementType,
      quantity: signed,
      reason: reason.trim(),
    });
    setSaving(false);

    if (!res.success) {
      setFormProblems({
        ...(fieldError(res, 'quantity') ? { quantity: fieldError(res, 'quantity') as string } : {}),
        ...(fieldError(res, 'reason') ? { reason: fieldError(res, 'reason') as string } : {}),
      });
      setFormFailure(refusalOf(res));
      return;
    }

    setRecordedMessage(res.message ?? 'سُجِّلت الحركة وحُدِّثت كمية العرض.');
    setQuantity('');
    setReason('');
    await load();
  };

  const cell = 'w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-xs focus:bg-white focus:outline-none';

  return (
    <div className="min-w-0 space-y-3">
      <h3 className="flex items-center gap-1.5 text-xs font-bold text-slate-700">
        <History className="w-4 h-4 text-emerald-700" />
        <span>حركة المخزون المسجَّلة لمنشأتك</span>
      </h3>

      <div className="flex flex-wrap items-center gap-2">
        <label className="text-[11px] font-bold text-slate-600" htmlFor="movement-type-filter">
          نوع الحركة:
        </label>
        <select
          id="movement-type-filter"
          value={typeFilter}
          onChange={(e) => setTypeFilter(e.target.value)}
          className="min-w-0 flex-1 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs sm:flex-none"
        >
          <option value="">الكل</option>
          {Object.entries(MOVEMENT_LABELS).map(([code, label]) => (
            <option key={code} value={code}>
              {label}
            </option>
          ))}
        </select>
      </div>

      {state === 'loading' ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500">جاري جلب السجل...</div>
      ) : state === 'refused' ? (
        <div className="rounded-xl border border-rose-200 bg-rose-50 p-4 text-xs text-rose-800 leading-relaxed">
          سجل حركة المخزون غير متاح لهذه الجلسة: {refusal}
        </div>
      ) : movements.length === 0 ? (
        <div className="rounded-xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500 leading-relaxed">
          لا حركات مسجَّلة. تظهر هنا التوريدات والتسويات، كما تُسجَّل عن الحجز والبيع.
        </div>
      ) : (
        <div className="min-w-0 overflow-x-auto rounded-xl border border-slate-200 bg-white shadow-2xs">
          <table className="w-full min-w-[48rem] text-right text-xs">
            <thead className="border-b border-slate-200 bg-slate-50 text-slate-600 font-semibold">
              <tr>
                <th className="p-3">الوقت</th>
                <th className="p-3">الصنف</th>
                <th className="p-3">النوع</th>
                <th className="p-3">الكمّية</th>
                <th className="p-3">المتاح قبل ← بعد</th>
                <th className="p-3">السبب</th>
                <th className="p-3">سجّلها</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {movements.map((m) => (
                <tr key={m.id}>
                  <td className="p-3 text-slate-500 whitespace-nowrap">{formatDateTime(m.created_at, locale)}</td>
                  <td className="p-3">
                    <div className="font-bold text-slate-900">{m.product_variant?.name_ar || '—'}</div>
                    <div className="text-[10px] text-slate-400">
                      {[m.product_variant?.sku, m.warehouse?.name].filter(Boolean).join(' · ')}
                    </div>
                  </td>
                  <td className="p-3">
                    <span
                      className={`rounded-full px-2 py-0.5 text-[10px] font-bold ${
                        m.type === 'SOLD' || m.type === 'RESERVED'
                          ? 'bg-amber-100 text-amber-800'
                          : m.type === 'IN' || m.type === 'RETURNED'
                          ? 'bg-emerald-50 text-emerald-800'
                          : 'bg-slate-100 text-slate-700'
                      }`}
                    >
                      {MOVEMENT_LABELS[m.type] ?? m.type}
                    </span>
                  </td>
                  <td className={`p-3 font-bold ${Number(m.quantity) < 0 ? 'text-rose-700' : 'text-emerald-800'}`}>
                    {Number(m.quantity) > 0 ? '+' : ''}
                    {m.quantity}
                  </td>
                  <td className="p-3 text-slate-600">
                    {m.previous_quantity} ← {m.new_quantity}
                  </td>
                  <td className="max-w-[18rem] p-3 text-slate-500" title={m.reason || undefined}>
                    {m.reason || '—'}
                  </td>
                  <td className="p-3 text-slate-500">{m.user?.name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-3 rounded-xl border border-slate-200 bg-white p-4 shadow-2xs">
        <div>
          <h4 className="text-xs font-black text-slate-900">تسجيل حركة على عرض من عروض منشأتك</h4>
          <p className="text-[11px] leading-relaxed text-slate-500">
            الحركة تُصحِّح كمية العرض نفسها، لا رقمًا بجانبها: الوارد والمرتجع يزيدان المتاح، وتسوية الجرد تزيد أو تنقص،
            ولا يُقبل إنقاصٌ يترك المتاح سالبًا أو يقلّ عن المحجوز والمبيع.
          </p>
        </div>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-4">
          <div className="space-y-1 sm:col-span-2">
            <span className="font-bold text-slate-700 block text-xs">العرض:</span>
            <select value={offerId} onChange={(e) => setOfferId(e.target.value ? Number(e.target.value) : '')} className={cell}>
              <option value="">— اختر عرضًا من قوائم منشأتك —</option>
              {offers.map((o) => (
                <option key={o.id} value={o.id}>
                  {o.title_ar} — متاح {o.available_quantity} {o.unit} ({STATUS_LABELS[o.status] ?? o.status})
                </option>
              ))}
            </select>
            <FieldNote message={formProblems.offer_id} />
          </div>
          <div className="space-y-1">
            <span className="font-bold text-slate-700 block text-xs">النوع:</span>
            <select
              value={movementType}
              onChange={(e) => setMovementType(e.target.value as 'IN' | 'ADJUSTED' | 'RETURNED')}
              className={cell}
            >
              <option value="IN">توريد داخل</option>
              <option value="ADJUSTED">تسوية جرد (بالزيادة أو النقص)</option>
              <option value="RETURNED">مرتجع من مشتري</option>
            </select>
          </div>
          <div className="space-y-1">
            <span className="font-bold text-slate-700 block text-xs">الكمّية:</span>
            <input
              type="number"
              step="any"
              value={quantity}
              onChange={(e) => setQuantity(e.target.value)}
              placeholder={movementType === 'ADJUSTED' ? 'سالبة للإنقاص' : 'موجبة'}
              className={cell}
            />
            <FieldNote message={formProblems.quantity} />
          </div>
        </div>

        <div className="space-y-1">
          <span className="font-bold text-slate-700 block text-xs">السبب (مطلوب ويُبقى في السجل):</span>
          <input value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} className={cell} />
          <FieldNote message={formProblems.reason} />
        </div>

        {formFailure && (
          <div className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
            <AlertCircle className="w-4 h-4 shrink-0" />
            <span>{formFailure}</span>
          </div>
        )}

        {recordedMessage && (
          <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-xs text-emerald-900">{recordedMessage}</div>
        )}

        <div className="flex flex-wrap items-center justify-between gap-3">
          <span className="min-w-0 flex-1 basis-48 text-[10px] leading-relaxed text-slate-400">
            تُرسل الحركة باسم العرض الذي تصحّحه، والمستودع والصنف يُقرآن من عرضه المسجَّل.
            {canSubmit ? '' : ' زر التسجيل ينتظر اختيار العرض وكتابة الكمّية والسبب.'}
          </span>
          <button
            type="submit"
            disabled={saving || !canSubmit}
            className="shrink-0 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-bold text-white shadow-xs hover:bg-emerald-700 disabled:opacity-40 cursor-pointer"
          >
            {saving ? 'جاري التسجيل...' : 'تسجيل الحركة'}
          </button>
        </div>
      </form>
    </div>
  );
};

/**
 * Editing a listing that is still the seller's to change.
 *
 * `OfferController::update()` accepts an edit while the offer is a DRAFT or is
 * PAUSED, and refuses it in the market — so this sheet is opened from those two
 * rows only. The fields below are exactly the ones that route validates: the
 * warehouse, the variant and the currency are not editable here because the
 * route does not move them, and saying so is better than posting a value the
 * server will ignore.
 */
const EditOfferModal: React.FC<{
  offer: Offer;
  reasons: { code: string; label_ar: string; label_en: string }[];
  onClose: () => void;
  onSaved: (message: string) => void;
}> = ({ offer, reasons, onClose, onSaved }) => {
  const locale = getLocale();

  // Reserved or sold stock is already promised to somebody; the total on the
  // listing may not be rewritten underneath those holds.
  const committed = Number(offer.reserved_quantity ?? 0) + Number(offer.sold_quantity ?? 0);
  const quantityLocked = committed > 0;

  const [titleAr, setTitleAr] = useState(offer.title_ar ?? '');
  const [descriptionAr, setDescriptionAr] = useState(offer.description_ar ?? '');
  const [reason, setReason] = useState(String(offer.opportunity_reason ?? ''));
  const [reasonNotes, setReasonNotes] = useState(offer.opportunity_reason_notes ?? '');
  const [unit, setUnit] = useState(offer.unit ?? '');
  const [unitPrice, setUnitPrice] = useState(String(offer.unit_price ?? ''));
  const [minOrder, setMinOrder] = useState(String(offer.min_order_quantity ?? ''));
  const [maxOrder, setMaxOrder] = useState(
    offer.max_order_quantity === null || offer.max_order_quantity === undefined ? '' : String(offer.max_order_quantity)
  );
  const [totalQuantity, setTotalQuantity] = useState(String(offer.total_quantity ?? ''));
  const [productionDate, setProductionDate] = useState(offer.production_date ?? '');
  const [expiryDate, setExpiryDate] = useState(offer.expiry_date ?? '');
  const [listingExpiresAt, setListingExpiresAt] = useState(
    offer.expires_at ? String(offer.expires_at).slice(0, 10) : ''
  );
  const [storageCondition, setStorageCondition] = useState(offer.storage_condition ?? '');
  const [inspectionHours, setInspectionHours] = useState(
    offer.inspection_window_hours === null || offer.inspection_window_hours === undefined
      ? ''
      : String(offer.inspection_window_hours)
  );
  const [deliveryTerms, setDeliveryTerms] = useState<'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH'>(
    (['BUYER_PICKUP', 'SELLER_DELIVERY', 'BOTH'].includes(String(offer.delivery_terms))
      ? String(offer.delivery_terms)
      : 'BOTH') as 'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH'
  );
  const [showIdentity, setShowIdentity] = useState(offer.show_seller_identity !== false);

  const [problems, setProblems] = useState<Record<string, string>>({});
  const [failure, setFailure] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const cell = 'w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none';
  const label = 'font-bold text-slate-700 block';

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setFailure(null);
    const local: Record<string, string> = {};

    if (!titleAr.trim()) local.title_ar = 'عنوان العرض مطلوب.';
    if (!reason) local.opportunity_reason = 'اختر سبب طرح المخزون من قائمة المنصة.';
    if (!unit.trim()) local.unit = 'حدِّد وحدة العدّ.';

    const price = Number(unitPrice);
    if (!unitPrice.trim() || !(price >= 0.0001)) local.unit_price = 'سعر الوحدة رقم أكبر من صفر.';
    const min = Number(minOrder);
    if (!minOrder.trim() || !(min >= 1)) local.min_order_quantity = 'الحد الأدنى للطلب رقم لا يقل عن 1.';
    if (maxOrder.trim()) {
      const max = Number(maxOrder);
      if (!(max >= 1)) local.max_order_quantity = 'الحد الأقصى إن ذُكر لا يقل عن 1.';
      else if (max < min) local.max_order_quantity = 'الحد الأقصى أقل من الحد الأدنى.';
    }
    if (!quantityLocked) {
      const total = Number(totalQuantity);
      if (!totalQuantity.trim() || !(total >= 1)) local.total_quantity = 'الكمية الكلية رقم لا يقل عن 1.';
      else if (total < min) local.total_quantity = 'الكمية الكلية أقل من الحد الأدنى للطلب.';
    }
    if (inspectionHours.trim() && !(Number(inspectionHours) >= 1 && Number(inspectionHours) <= 168)) {
      local.inspection_window_hours = 'مهلة الفحص بين ساعة واحدة و168 ساعة.';
    }

    setProblems(local);
    if (Object.keys(local).length > 0) return;

    const payload: Record<string, unknown> = {
      title_ar: titleAr.trim(),
      description_ar: descriptionAr.trim() || null,
      opportunity_reason: reason,
      opportunity_reason_notes: reasonNotes.trim() || null,
      min_order_quantity: min,
      unit: unit.trim(),
      unit_price: price,
      production_date: productionDate || null,
      expiry_date: expiryDate || null,
      storage_condition: storageCondition.trim() || null,
      delivery_terms: deliveryTerms,
      inspection_window_hours: inspectionHours.trim() ? Number(inspectionHours) : null,
      expires_at: listingExpiresAt || null,
      show_seller_identity: showIdentity,
    };
    // `sometimes` on the server: an absent maximum keeps the stored one, and a
    // blank box here means "no ceiling", which must be sent as null explicitly.
    payload.max_order_quantity = maxOrder.trim() ? Number(maxOrder) : null;
    if (!quantityLocked) payload.total_quantity = Number(totalQuantity);

    setSaving(true);
    const res = await api.updateOffer(offer.id, payload);
    setSaving(false);

    if (!res.success) {
      setProblems({
        ...(fieldError(res, 'title_ar') ? { title_ar: fieldError(res, 'title_ar') as string } : {}),
        ...(fieldError(res, 'unit_price') ? { unit_price: fieldError(res, 'unit_price') as string } : {}),
        ...(fieldError(res, 'min_order_quantity') ? { min_order_quantity: fieldError(res, 'min_order_quantity') as string } : {}),
        ...(fieldError(res, 'max_order_quantity') ? { max_order_quantity: fieldError(res, 'max_order_quantity') as string } : {}),
        ...(fieldError(res, 'total_quantity') ? { total_quantity: fieldError(res, 'total_quantity') as string } : {}),
        ...(fieldError(res, 'opportunity_reason') ? { opportunity_reason: fieldError(res, 'opportunity_reason') as string } : {}),
        ...(fieldError(res, 'expires_at') ? { expires_at: fieldError(res, 'expires_at') as string } : {}),
      });
      setFailure(refusalOf(res));
      return;
    }

    onSaved(res.message ?? 'حُدِّث العرض لدى المنصة.');
  };

  return (
    <div
      className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 backdrop-blur-[2px] sm:items-center sm:p-4"
      role="dialog"
      aria-modal="true"
      onClick={(event) => {
        if (event.target === event.currentTarget) onClose();
      }}
    >
      <form
        onSubmit={handleSubmit}
        className="max-h-[92dvh] w-full space-y-4 overflow-y-auto rounded-t-2xl bg-white shadow-2xl sm:max-w-2xl sm:rounded-2xl"
      >
        <div className="sticky top-0 flex items-start justify-between gap-3 border-b border-slate-200 bg-white px-4 py-4 sm:px-5">
          <div className="min-w-0">
            <h3 className="text-sm font-black text-slate-900">تعديل العرض</h3>
            <p className="truncate text-[11px] text-slate-500" title={offer.title_ar}>
              {offer.title_ar} — حالته لدى المنصة: {STATUS_LABELS[offer.status] ?? offer.status}
            </p>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="إغلاق"
            className="-m-1 shrink-0 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700 cursor-pointer"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        <div className="space-y-4 px-4 pb-[calc(1.25rem+env(safe-area-inset-bottom))] sm:px-5">
          <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] leading-relaxed text-slate-600">
            المستودع ({offer.warehouse_name || 'غير مسمّى'}) والصنف والعملة ({currencyLabel(offer.currency_code)})
            لا تتغيّر من هذه الشاشة: مسار العرض ومصدره ماليّته، وتعديلها يعني عرضاً جديداً لا تصحيحاً لهذا.
          </div>

          <FormSection title="بيانات العرض" />

          <div className="space-y-1">
            <span className={label}>عنوان العرض (مطلوب):</span>
            <input value={titleAr} onChange={(e) => setTitleAr(e.target.value)} className={cell} />
            <FieldNote message={problems.title_ar} />
          </div>

          <div className="space-y-1">
            <span className={label}>الوصف:</span>
            <textarea rows={3} value={descriptionAr} onChange={(e) => setDescriptionAr(e.target.value)} className={cell} />
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <span className={label}>سبب طرح المخزون (مطلوب):</span>
              <select value={reason} onChange={(e) => setReason(e.target.value)} className={cell}>
                <option value="">— اختر من قائمة المنصة —</option>
                {reasons.map((r) => (
                  <option key={r.code} value={r.code}>
                    {locale === 'en' ? r.label_en : r.label_ar}
                  </option>
                ))}
              </select>
              <FieldNote message={problems.opportunity_reason} />
            </div>
            <div className="space-y-1">
              <span className={label}>تفصيل السبب (اختياري):</span>
              <input value={reasonNotes} onChange={(e) => setReasonNotes(e.target.value)} className={cell} />
            </div>
          </div>

          <FormSection title="الكمية والسعر" />

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1">
              <span className={label}>وحدة العدّ (مطلوب):</span>
              <input value={unit} onChange={(e) => setUnit(e.target.value)} className={cell} />
              <FieldNote message={problems.unit} />
            </div>
            <div className="space-y-1">
              <span className={label}>سعر الوحدة (مطلوب، {offer.currency_code}):</span>
              <input type="number" step="0.0001" min="0.0001" value={unitPrice} onChange={(e) => setUnitPrice(e.target.value)} className={cell} />
              <FieldNote message={problems.unit_price} />
            </div>
            <div className="space-y-1">
              <span className={label}>الكمية الكلية (مطلوب):</span>
              <input
                type="number"
                min="1"
                value={totalQuantity}
                disabled={quantityLocked}
                onChange={(e) => setTotalQuantity(e.target.value)}
                className={`${cell} disabled:opacity-50`}
              />
              {quantityLocked ? (
                <p className="text-[10px] text-amber-700 leading-relaxed">
                  {committed} {offer.unit} محجوزة أو مبيعة: لا تُعدَّل الكمية المعلن عنها، والخادم يرفض ذلك.
                </p>
              ) : (
                <FieldNote message={problems.total_quantity} />
              )}
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1">
              <span className={label}>أدنى كمية للطلب:</span>
              <input type="number" min="1" value={minOrder} onChange={(e) => setMinOrder(e.target.value)} className={cell} />
              <FieldNote message={problems.min_order_quantity} />
            </div>
            <div className="space-y-1">
              <span className={label}>أقصى كمية للطلب (فارغ = بلا سقف):</span>
              <input type="number" min="1" value={maxOrder} onChange={(e) => setMaxOrder(e.target.value)} className={cell} />
              <FieldNote message={problems.max_order_quantity} />
            </div>
            <div className="space-y-1">
              <span className={label}>مهلة الفحص بالساعات:</span>
              <input
                type="number"
                min="1"
                max="168"
                value={inspectionHours}
                onChange={(e) => setInspectionHours(e.target.value)}
                placeholder="فارغ = بلا مهلة مسجَّلة"
                className={cell}
              />
              <FieldNote message={problems.inspection_window_hours} />
            </div>
          </div>

          <FormSection title="التواريخ والتسليم" />

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div className="space-y-1">
              <span className={label}>تاريخ الإنتاج:</span>
              <input type="date" value={(productionDate || '').slice(0, 10)} onChange={(e) => setProductionDate(e.target.value)} className={cell} />
            </div>
            <div className="space-y-1">
              <span className={label}>تاريخ الصلاحية:</span>
              <input type="date" value={(expiryDate || '').slice(0, 10)} onChange={(e) => setExpiryDate(e.target.value)} className={cell} />
            </div>
            <div className="space-y-1">
              <span className={label}>نهاية صلاحية العرض في السوق:</span>
              <input type="date" value={listingExpiresAt} onChange={(e) => setListingExpiresAt(e.target.value)} className={cell} />
              <FieldNote message={problems.expires_at} />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1">
              <span className={label}>ظروف التخزين:</span>
              <input value={storageCondition} onChange={(e) => setStorageCondition(e.target.value)} className={cell} />
            </div>
            <div className="space-y-1">
              <span className={label}>شروط التسليم:</span>
              <select
                value={deliveryTerms}
                onChange={(e) => setDeliveryTerms(e.target.value as 'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH')}
                className={cell}
              >
                <option value="BOTH">استلام وتوصيل</option>
                <option value="BUYER_PICKUP">استلام من المستودع فقط</option>
                <option value="SELLER_DELIVERY">توصيل البائع فقط</option>
              </select>
            </div>
          </div>

          <label className="flex items-start gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-[11px] font-semibold text-slate-700">
            <input
              type="checkbox"
              checked={showIdentity}
              onChange={(e) => setShowIdentity(e.target.checked)}
              className="accent-emerald-600 mt-0.5"
            />
            <span>
              إظهار اسم منشأتك في هذا العرض على السوق.
              <span className="block text-[10px] font-normal text-slate-500 leading-relaxed pt-0.5">
                الإخفاء يسري على بطاقات السوق وصفحة العرض: لا اسم للمنشأة ولا رقم سجلها ولا رقمها الضريبي ولا اسم
                المستودع وعنوانه. يظل الاسم معروفًا لمراجِع المنصة، ويصل إلى المشتري مع طلب الشراء الموجه إليكما —
                لأن من يوقّع صفقة يجب أن يُعرف.
              </span>
            </span>
          </label>

          {failure && (
            <div className="flex items-start gap-2 rounded-lg border border-rose-200 bg-rose-50 p-3 text-xs text-rose-800">
              <AlertCircle className="w-4 h-4 shrink-0" />
              <span>{failure}</span>
            </div>
          )}

          <div className="flex items-center gap-3 pt-1">
            <button
              type="button"
              onClick={onClose}
              className="flex-1 rounded-xl bg-slate-100 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-200 cursor-pointer"
            >
              إلغاء
            </button>
            <button
              type="submit"
              disabled={saving}
              className="flex-[2] rounded-xl bg-emerald-600 py-2.5 text-xs font-bold text-white shadow-md hover:bg-emerald-700 disabled:opacity-50 cursor-pointer"
            >
              {saving ? 'جاري الحفظ...' : 'حفظ التعديلات'}
            </button>
          </div>
        </div>
      </form>
    </div>
  );
};

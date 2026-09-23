import React, { useState, useEffect, useMemo } from 'react';
import { 
  MapPin, 
  Clock, 
  Package, 
  Shield, 
  CheckCircle2, 
  AlertCircle, 
  Compass, 
  Truck, 
  FileText,
  Search,
  Filter,
  ArrowUpDown,
  X,
  Building2,
  SlidersHorizontal,
  RotateCcw,
  MessageCircle,
  Heart,
  Sparkles
} from 'lucide-react';
import { api, verificationGate } from '../lib/api';
import type { OfferSortIntent } from '../lib/api';
import type { Offer, Category, Brand, PurchaseRequest } from '../types';
import { formatMoney, isFeatureEnabled, reservationWindowMinutes, taxSummary, usePlatformConfig } from '../lib/config';
import { formatDate, localizedField, type Locale } from '../lib/i18n';
import BrandMark from './BrandMark';
import { OfferLikeButton, OfferThread, useOfferCommunity } from './OfferCommunity';

interface MarketplaceViewProps {
  onOrderSuccess: (purchaseRequest: PurchaseRequest) => void;
  locale?: Locale;
  /**
   * Interest belongs to the business in context, not to the person, so switching
   * organizations must reload the set that fills the buttons in.
   */
  currentPersonaId?: string;
  /**
   * Open the sign-in modal. A guest clicking a like is not an error to report —
   * it is intent to act — so the card asks them to sign in rather than sending a
   * request the server would refuse.
   */
  onRequireSignIn?: () => void;
}

/** The badge style only; the reason codes and their labels come from the server. */
const REASON_CHIP_STYLE = 'bg-emerald-50 text-emerald-800 border-emerald-200';

const SORT_LABELS: Record<OfferSortIntent, string> = {
  newest: 'الأحدث وصولاً بالمستودعات',
  cheapest: 'الأقل سعراً للوحدة',
  priciest: 'الأعلى سعراً للوحدة',
  expiring: 'الأبعد انتهاءً',
  quantity: 'الأكبر كمية',
  nearest_to_expiry: 'الأقرب انتهاءً',
};

/** `organizations.status` as the platform records it — a review state, not a government claim. */
const SELLER_STATUS_LABELS: Record<string, string> = {
  pending_kyc: 'بيانات المؤسسة قيد مراجعة المنصة',
  verified: 'وثّقتها إدارة المنصة',
  suspended: 'موقوفة من المنصة',
  rejected: 'مرفوضة من المنصة',
};

const SELLER_STATUS_STYLES: Record<string, string> = {
  pending_kyc: 'bg-amber-50 text-amber-800 border-amber-200',
  verified: 'bg-emerald-50 text-emerald-800 border-emerald-200',
  suspended: 'bg-rose-50 text-rose-800 border-rose-200',
  rejected: 'bg-slate-100 text-slate-700 border-slate-200',
};

/**
 * Who is selling, in exactly as many words as the seller allowed.
 *
 * A blank here has two entirely different causes and the buyer must not be left
 * to guess between them: the business chose to hide its name on this listing, or
 * the platform never recorded one. Only the first is a decision somebody made,
 * so only the first is described as one.
 */
function sellerLabel(offer: Offer, locale: Locale): { text: string; hidden: boolean } {
  const name = (locale === 'en'
    ? offer.seller_name_en || offer.seller_name_ar
    : offer.seller_name_ar || offer.seller_name_en)?.trim();

  if (name) return { text: name, hidden: false };
  if (offer.show_seller_identity === false) {
    return { text: locale === 'en' ? 'Seller who withholds their name' : 'بائع لا يُظهر اسمه', hidden: true };
  }
  return { text: locale === 'en' ? 'Not recorded' : 'لم يُسجَّل', hidden: false };
}

/**
 * What a listing is called, in the language the visitor is reading.
 *
 * Sellers write in whichever language they work in, and the API keeps both name
 * columns with either one null — an offer can have an Arabic product name and no
 * English one. Reading only `product_name_ar` shows an English reader a card
 * whose title they may not be able to check, and reading only `_en` shows them
 * nothing at all where the row does hold a name. So: prefer the language on
 * screen, fall back to the other, and fall back to the offer's own title.
 */
function offerName(offer: Offer, locale: Locale): string {
  return localizedField(offer, 'product_name', locale) || localizedField(offer, 'title', locale);
}

const normalizeOffer = (offer: Offer): Offer => {
  const numericFields = [
    'available_quantity', 'reserved_quantity', 'sold_quantity', 'unit_price',
    'min_order_quantity', 'max_order_quantity',
    'seller_rating', 'category_id', 'brand_id', 'warehouse_lat', 'warehouse_lon', 'distance_km',
    'likes_count', 'comments_count',
  ] as const;

  const normalized = { ...offer } as Offer & Record<string, unknown>;
  numericFields.forEach((field) => {
    const value = normalized[field];
    if (value !== null && value !== undefined) {
      normalized[field] = Number(value);
    }
  });

  return normalized;
};

/** A coordinate pair the buyer either granted or typed; never a guessed default. */
interface GeoPoint {
  lat: number | null;
  lon: number | null;
  label: string;
}

export const MarketplaceView: React.FC<MarketplaceViewProps> = ({ onOrderSuccess, locale = 'ar', currentPersonaId = '', onRequireSignIn }) => {
  const config = usePlatformConfig();
  // The like and comment routes are `auth:sanctum` and behind the `community`
  // capability; offering their buttons to a guest, or in a deployment that
  // switched the capability off, would only produce refusals.
  const signedIn = Boolean(api.getToken());
  // No flags at all means the bootstrap response has not arrived; that is not
  // the operator switching anything off. The server gates the action either
  // way, so an unread flag must not hide a door that is open.
  const flagsRead = Object.keys(config.featureFlags ?? {}).length > 0;
  const communityOn = !flagsRead || isFeatureEnabled('community');
  const { likedIds, busyId, notice: communityNotice, toggleLike, clearNotice } = useOfferCommunity(signedIn && communityOn, currentPersonaId);
  const [openThreadFor, setOpenThreadFor] = useState<number | null>(null);
  /** Narrow the shelf to the listings this organization liked (`GET /marketplace/liked-offers`). */
  const [onlyLiked, setOnlyLiked] = useState(false);
  const categoryLabel = (category: Category) => locale === 'en' ? category.name_en : category.name_ar;
  const reasonLabel = (code: string) =>
    config.opportunityReasons.find((reason) => reason.code === code)
      ? (locale === 'en'
          ? config.opportunityReasons.find((reason) => reason.code === code)!.label_en
          : config.opportunityReasons.find((reason) => reason.code === code)!.label_ar)
      : code;
  const [offers, setOffers] = useState<Offer[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [loading, setLoading] = useState(true);

  // Advanced Search & Filter States
  const [searchQuery, setSearchQuery] = useState('');
  const [searchTarget, setSearchTarget] = useState<'all' | 'name' | 'category' | 'supplier'>('all');
  const [selectedCategory, setSelectedCategory] = useState<number | null>(null);
  const [selectedSupplier, setSelectedSupplier] = useState<string>('');
  const [selectedReason, setSelectedReason] = useState<string>('');
  const [maxPrice, setMaxPrice] = useState<number | ''>('');
  const [sortBy, setSortBy] = useState<OfferSortIntent>('newest');
  const [geoMode, setGeoMode] = useState<boolean>(false);
  const [geoPoint, setGeoPoint] = useState<GeoPoint>({ lat: null, lon: null, label: '' });
  const [radiusKm, setRadiusKm] = useState<number | ''>('');
  const [geoError, setGeoError] = useState<string | null>(null);

  // Modal State
  const [activeOfferForOrder, setActiveOfferForOrder] = useState<Offer | null>(null);
  const [orderQuantity, setOrderQuantity] = useState<number>(20);
  const [deliveryPreference, setDeliveryPreference] = useState<'pickup' | 'delivery'>('pickup');
  const [orderNotes, setOrderNotes] = useState('');
  const [submittingOrder, setSubmittingOrder] = useState(false);
  const [orderError, setOrderError] = useState<string | null>(null);

  const loadData = async () => {
    setLoading(true);
    setGeoError(null);
    try {
      const [catRes, brandRes] = await Promise.all([
        api.getCategories(),
        api.getBrands(),
      ]);
      if (catRes.success && catRes.data) setCategories(catRes.data);
      if (brandRes.success && brandRes.data) setBrands(brandRes.data);

      if (geoMode) {
        if (geoPoint.lat === null || geoPoint.lon === null || !radiusKm) {
          setOffers([]);
          setGeoError('البحث بالمسافة يحتاج إحداثيات لديك ونصف قطر. حدّدهما أو اطلب من المتصفح تحديد موقعك.');
          return;
        }
        const nearRes = await api.getNearbyOffers(geoPoint.lat, geoPoint.lon, { radius_km: Number(radiusKm) });
        if (nearRes.success && nearRes.data) {
          setOffers(nearRes.data.offers.map(normalizeOffer));
          if (nearRes.data.total_found === 0) {
            setGeoError(`لم يجد الخادم عروضاً داخل ${nearRes.data.radius_km} كم من النقطة المحددة.`);
          }
        } else {
          setOffers([]);
          setGeoError(nearRes.error?.message || 'رفض الخادم طلب الأقرب؛ تحقق من الإحداثيات ونصف القطر.');
        }
        return;
      }

      const offRes = await api.getOffers({ sort_by: sortBy });
      if (offRes.success && offRes.data) setOffers(offRes.data.map(normalizeOffer));
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [sortBy, geoMode, geoPoint, radiusKm]);

  /** The browser's own location, used only if the buyer asks for it. */
  const useMyLocation = () => {
    if (!('geolocation' in navigator)) {
      setGeoError('متصفحك لا يوفر تحديد الموقع؛ أدخل الإحداثيات يدوياً.');
      return;
    }
    setGeoError(null);
    navigator.geolocation.getCurrentPosition(
      (position) => {
        setGeoPoint({
          lat: Number(position.coords.latitude.toFixed(6)),
          lon: Number(position.coords.longitude.toFixed(6)),
          label: 'موقعي الحالي (من المتصفح)',
        });
      },
      () => setGeoError('رُفض إذن الموقع أو تعذر تحديده. أدخل الإحداثيات يدوياً.'),
      { enableHighAccuracy: false, timeout: 8000 }
    );
  };

  // Extract unique supplier names from loaded offers
  const suppliers = useMemo(() => {
    const map = new Map<string, string>();
    offers.forEach((o) => {
      if (o.seller_name_ar) {
        map.set(o.seller_name_ar, o.seller_name_ar);
      }
    });
    return Array.from(map.values());
  }, [offers]);

  // Check if any filter is actively applied
  const hasActiveFilters = Boolean(
    searchQuery.trim() ||
    searchTarget !== 'all' ||
    selectedCategory !== null ||
    selectedSupplier !== '' ||
    selectedReason !== '' ||
    onlyLiked ||
    maxPrice !== ''
  );

  const handleResetFilters = () => {
    setSearchQuery('');
    setSearchTarget('all');
    setSelectedCategory(null);
    setSelectedSupplier('');
    setSelectedReason('');
    setOnlyLiked(false);
    setMaxPrice('');
  };

  /**
   * The count comes back from the server rather than being incremented here:
   * two buyers clicking at the same moment must converge on one number, and a
   * refusal must leave the card exactly as it was.
   */
  const handleToggleLike = async (offer: Offer) => {
    if (!signedIn) {
      onRequireSignIn?.();
      return;
    }
    const count = await toggleLike(offer);
    if (count === null) return;
    setOffers((previous) => previous.map((row) => (row.id === offer.id ? { ...row, likes_count: count } : row)));
  };

  const handleOpenOrderModal = (offer: Offer) => {
    setActiveOfferForOrder(offer);
    const floor = Number(offer.min_order_quantity);
    setOrderQuantity(Number.isFinite(floor) && floor > 0 ? floor : 1);
    const terms = String(offer.delivery_terms || 'BOTH').toUpperCase();
    setDeliveryPreference(terms === 'SELLER_DELIVERY' ? 'delivery' : 'pickup');
    setOrderNotes('');
    setOrderError(null);
  };

  const handleSubmitOrder = async () => {
    if (!activeOfferForOrder) return;
    setSubmittingOrder(true);
    setOrderError(null);

    const res = await api.createPurchaseRequestFromOffer({
      offer_id: activeOfferForOrder.id,
      requested_quantity: orderQuantity,
      delivery_preference: deliveryPreference === 'pickup' ? 'PICKUP' : 'DELIVERY',
      ...(orderNotes.trim() ? { notes: orderNotes.trim() } : {}),
    });

    setSubmittingOrder(false);
    if (res.success && res.data) {
      setActiveOfferForOrder(null);
      onOrderSuccess(res.data);
      loadData(); // The listing itself may have moved while this ask was raised
    } else {
      // The verification refusal arrives with the checklist the platform
      // actually requires, so it is shown instead of a bare "not allowed".
      const gate = verificationGate(res);
      const outstanding = (gate?.checklist ?? []).filter((item) => !item.done).map((item) => item.label_ar);
      setOrderError(
        (res.error?.message || res.message || 'لم يُسجَّل طلب الشراء لدى الخادم')
        + (outstanding.length ? ` — المتبقي: ${outstanding.join('، ')}` : '')
      );
    }
  };

  // Automatic real-time filtering across Name, Category, Supplier, and Specifications
  const filteredOffers = useMemo(() => {
    return offers.filter((o) => {
      // 1. Filter by category
      if (selectedCategory !== null) {
        const cat = categories.find((c) => c.id === selectedCategory);
        const categoryName = locale === 'en' ? cat?.name_en : cat?.name_ar;
        if (o.category_id !== selectedCategory && o.category_name_ar !== categoryName && o.category_name_en !== cat?.name_en) {
          return false;
        }
      }

      // 2. Filter by supplier
      if (selectedSupplier) {
        if (o.seller_name_ar !== selectedSupplier) {
          return false;
        }
      }

      // 3. Filter by opportunity reason
      if (selectedReason && o.opportunity_reason !== selectedReason) {
        return false;
      }

      // 3b. Only what this organization liked — the ids come from the server,
      // never from what a card happened to render.
      if (onlyLiked && !likedIds.has(o.id)) {
        return false;
      }

      // 4. Filter by max unit price
      if (maxPrice !== '' && Number(o.unit_price) > Number(maxPrice)) {
        return false;
      }

      // 5. Automatic text search with chosen search scope
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();

        /*
         * A buyer types in whichever language they think in and a seller records
         * in whichever language they work in; the API keeps both columns of every
         * name and leaves either one null. Matching only the Arabic column would
         * answer "no offers" to someone who typed the Latin spelling that is
         * sitting in the row — and the reverse. So every scope tests both.
         */
        const says = (...values: Array<string | null | undefined>) =>
          values.some((value) => typeof value === 'string' && value.toLowerCase().includes(q));

        if (searchTarget === 'name') {
          if (
            !says(
              o.product_name_ar,
              o.product_name_en,
              o.variant_name_ar,
              o.variant_name_en,
              o.brand_name_ar,
              o.brand_name_en,
              o.variant_sku
            )
          )
            return false;
        } else if (searchTarget === 'category') {
          if (!says(o.category_name_ar, o.category_name_en)) return false;
        } else if (searchTarget === 'supplier') {
          if (!says(o.seller_name_ar, o.seller_name_en, o.seller_cr)) return false;
        } else if (
          // 'all': product, variant, category, supplier, brand, SKU or warehouse city
          !says(
            o.product_name_ar,
            o.product_name_en,
            o.variant_name_ar,
            o.variant_name_en,
            o.category_name_ar,
            o.category_name_en,
            o.seller_name_ar,
            o.seller_name_en,
            o.brand_name_ar,
            o.brand_name_en,
            o.variant_sku,
            o.warehouse_city
          )
        )
          return false;
      }

      return true;
    });
  }, [offers, selectedCategory, selectedSupplier, selectedReason, onlyLiked, likedIds, maxPrice, searchQuery, searchTarget, categories, locale]);

  // Numbers for the reservation modal: the API answers quantities as decimal
  // strings, and a string silently breaks both the clamping and the submit guard.
  const activeFloor = activeOfferForOrder ? Number(activeOfferForOrder.min_order_quantity) || 0 : 0;
  const activeCeiling = activeOfferForOrder ? Number(activeOfferForOrder.available_quantity) || 0 : 0;
  const activeUnitPrice = activeOfferForOrder ? Number(activeOfferForOrder.unit_price) || 0 : 0;
  const stepSize = activeFloor > 0 && activeFloor < 10 ? Math.max(1, Math.round(activeFloor)) : 10;

  /* The ask dialog restates the listing so a person can confirm what they are
     signing for. It reads the same two name columns the cards do, for the same
     reason: the seller may have filled in only one of them. */
  const orderName = activeOfferForOrder ? offerName(activeOfferForOrder, locale) : '';
  const orderVariant = activeOfferForOrder ? localizedField(activeOfferForOrder, 'variant_name', locale) : '';
  const orderImage = activeOfferForOrder
    ? (activeOfferForOrder.product_image || activeOfferForOrder.variant_image) as string | undefined
    : undefined;

  /**
   * A phone offers no gesture to dismiss a sheet other than the thumb, so the
   * ask dialog closes on Escape and on its backdrop exactly as its ✕ does.
   */
  useEffect(() => {
    if (!activeOfferForOrder) return;
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setActiveOfferForOrder(null);
    };
    window.addEventListener('keydown', onKeyDown);
    return () => window.removeEventListener('keydown', onKeyDown);
  }, [activeOfferForOrder]);

  const stepQuantity = (delta: number) => {
    setOrderQuantity((previous) => {
      const next = previous + delta;
      if (next < activeFloor) return activeFloor;
      if (activeCeiling > 0 && next > activeCeiling) return activeCeiling;
      return next;
    });
  };

  return (
    <div className="mx-auto w-full max-w-7xl px-3 sm:px-6 lg:px-8 py-6 space-y-5 sm:space-y-6">
      {/* 1. Advanced Search & Auto-Filter Bar at the Top */}
      <div className="bg-white border-2 border-emerald-600/30 rounded-2xl p-3 sm:p-5 shadow-xs space-y-3.5 sm:space-y-4">
        {/* Header with Title and Real-time Status */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2.5 border-b border-slate-100 pb-3">
          <div className="flex items-start gap-2.5 min-w-0">
            <div className="w-8 h-8 shrink-0 rounded-lg bg-emerald-100 text-emerald-800 flex items-center justify-center">
              <Search className="w-4 h-4" />
            </div>
            <div className="min-w-0">
              <h2 className="text-sm font-black text-slate-900 font-serif">البحث في العروض والتصفية الفورية</h2>
              <p className="text-[11px] text-slate-500 pt-0.5">
                ابحث عن المواد الغذائية بالاسم أو التصنيف أو المورد، وتظهر النتائج مباشرة.
              </p>
            </div>
          </div>

          <div className="flex items-center justify-between sm:justify-end gap-2 w-full sm:w-auto">
            <span className="text-xs font-bold px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700 min-w-0">
              النتائج: <strong className="text-emerald-700">{filteredOffers.length}</strong> من {offers.length} عرض
            </span>
            {hasActiveFilters && (
              <button
                onClick={handleResetFilters}
                className="shrink-0 text-xs text-rose-600 hover:text-rose-800 font-bold flex items-center justify-center gap-1 hover:underline cursor-pointer bg-rose-50 px-2.5 rounded-lg border border-rose-200"
              >
                <RotateCcw className="w-3.5 h-3.5" />
                <span>مسح الفلاتر</span>
              </button>
            )}
          </div>
        </div>

        {/* Search Scope Tabs & Main Input Field */}
        <div className="flex flex-col md:flex-row items-stretch gap-2.5">
          {/* Scope Selector: All, Name, Category, Supplier — two rows on a phone, one strip from sm up */}
          <div className="grid grid-cols-2 gap-1 sm:flex sm:items-center sm:gap-0 items-center bg-slate-100 p-1 rounded-xl text-xs font-medium sm:shrink-0 sm:overflow-x-auto scrollbar-none">
            <button
              type="button"
              onClick={() => setSearchTarget('all')}
              className={`px-3 py-1.5 text-center rounded-lg transition-all whitespace-nowrap cursor-pointer ${
                searchTarget === 'all'
                  ? 'bg-white text-emerald-900 font-bold shadow-2xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              بحث شامل
            </button>
            <button
              type="button"
              onClick={() => setSearchTarget('name')}
              className={`px-3 py-1.5 text-center rounded-lg transition-all whitespace-nowrap cursor-pointer ${
                searchTarget === 'name'
                  ? 'bg-white text-emerald-900 font-bold shadow-2xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              اسم المادة
            </button>
            <button
              type="button"
              onClick={() => setSearchTarget('category')}
              className={`px-3 py-1.5 text-center rounded-lg transition-all whitespace-nowrap cursor-pointer ${
                searchTarget === 'category'
                  ? 'bg-white text-emerald-900 font-bold shadow-2xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              التصنيف
            </button>
            <button
              type="button"
              onClick={() => setSearchTarget('supplier')}
              className={`px-3 py-1.5 text-center rounded-lg transition-all whitespace-nowrap cursor-pointer ${
                searchTarget === 'supplier'
                  ? 'bg-white text-emerald-900 font-bold shadow-2xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              المورد / البائع
            </button>
          </div>

          {/* Search Input Field */}
          <div className="relative flex-1 min-w-0">
            <Search className="w-4 h-4 text-emerald-600 absolute right-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              placeholder={
                searchTarget === 'name'
                  ? 'اسم الصنف أو الماركة أو رمز SKU'
                  : searchTarget === 'category'
                  ? 'اسم التصنيف كما هو مسجَّل'
                  : searchTarget === 'supplier'
                  ? 'اسم المؤسسة البائعة أو رقم سجلها'
                  : 'ابحث بالاسم أو التصنيف أو المورد أو الرمز'
              }
              value={searchQuery}
              onChange={(e) => setSearchQuery(e.target.value)}
              className="w-full bg-slate-50 border border-slate-300 rounded-xl pr-10 pl-9 py-2.5 text-xs font-medium focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-600 transition-all"
            />
            {searchQuery && (
              <button
                type="button"
                onClick={() => setSearchQuery('')}
                className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700 font-bold text-xs cursor-pointer p-1"
                title="مسح نص البحث"
              >
                <X className="w-4 h-4" />
              </button>
            )}
          </div>
        </div>

        {/* Multi-Dimensional Filter Selectors */}
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 pt-1 text-xs">
          {/* Category Filter Dropdown */}
          <div className="space-y-1">
            <label className="text-[11px] font-bold text-slate-600 flex items-center gap-1">
              <Package className="w-3.5 h-3.5 text-emerald-600" />
              <span>التصنيف الغذائي:</span>
            </label>
            <select
              value={selectedCategory === null ? '' : selectedCategory}
              onChange={(e) => setSelectedCategory(e.target.value ? Number(e.target.value) : null)}
              className="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-2 text-xs text-slate-800 font-medium focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              <option value="">جميع التصنيفات الغذائية</option>
              {categories.map((c) => (
                <option key={c.id} value={c.id}>
                  {categoryLabel(c)}
                </option>
              ))}
            </select>
          </div>

          {/* Supplier Filter Dropdown */}
          <div className="space-y-1">
            <label className="text-[11px] font-bold text-slate-600 flex items-center gap-1">
              <Building2 className="w-3.5 h-3.5 text-emerald-600" />
              <span>المورد / الشركة البائعة:</span>
            </label>
            <select
              value={selectedSupplier}
              onChange={(e) => setSelectedSupplier(e.target.value)}
              className="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-2 text-xs text-slate-800 font-medium focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              <option value="">جميع الموردين المعتمدين</option>
              {suppliers.map((name) => (
                <option key={name} value={name}>
                  {name}
                </option>
              ))}
            </select>
          </div>

          {/* Opportunity Reason Filter */}
          <div className="space-y-1">
            <label className="text-[11px] font-bold text-slate-600 flex items-center gap-1">
              <SlidersHorizontal className="w-3.5 h-3.5 text-emerald-600" />
              <span>نوع الفرصة التجارية:</span>
            </label>
            <select
              value={selectedReason}
              onChange={(e) => setSelectedReason(e.target.value)}
              className="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-2 text-xs text-slate-800 font-medium focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              <option value="">كافة أسباب التصفية والفرص</option>
              {config.opportunityReasons.map((reason) => (
                <option key={reason.code} value={reason.code}>
                  {locale === 'en' ? reason.label_en : reason.label_ar}
                </option>
              ))}
            </select>
          </div>

          {/* Sorting */}
          <div className="space-y-1">
            <label className="text-[11px] font-bold text-slate-600 flex items-center gap-1">
              <ArrowUpDown className="w-3.5 h-3.5 text-emerald-600" />
              <span>ترتيب العروض:</span>
            </label>
            <select
              value={sortBy}
              onChange={(e) => setSortBy(e.target.value as OfferSortIntent)}
              className="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-2 text-xs text-slate-800 font-medium focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              {(Object.keys(SORT_LABELS) as OfferSortIntent[]).map((intent) => (
                <option key={intent} value={intent}>
                  {SORT_LABELS[intent]}
                </option>
              ))}
            </select>
          </div>
          {/* Listings this organization marked, as the server recorded them */}
          {signedIn && communityOn && (
            <div className="space-y-1">
              <label className="text-[11px] font-bold text-slate-600 flex items-center gap-1">
                <Heart className="w-3.5 h-3.5 text-emerald-600" />
                <span>اهتمام منشأتي:</span>
              </label>
              <label className="flex items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-2 text-xs font-medium text-slate-800 cursor-pointer">
                <input
                  type="checkbox"
                  checked={onlyLiked}
                  onChange={(e) => setOnlyLiked(e.target.checked)}
                  className="cursor-pointer"
                />
                <span>ما أعجب منشأتي فقط ({likedIds.size})</span>
              </label>
              <p className="text-[10px] text-slate-400 leading-relaxed">
                القائمة تُقرأ من الخادم لمنشأتك وحدها؛ صفر يعني لم تُسجّل منشأتك إعجابًا بعد، لا أن القدرة معطّلة.
              </p>
            </div>
          )}
        </div>

        {/* Active Filter Chips Bar */}
        {hasActiveFilters && (
          <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100 text-xs">
            <span className="text-slate-400 font-medium text-[11px] shrink-0">الفلاتر المفعلة:</span>
            {searchQuery && (
              <span className="inline-flex max-w-full items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-900 border border-emerald-200 text-[11px] font-bold">
                <span className="min-w-0 truncate" title={`${searchTarget === 'name' ? 'اسم المادة' : searchTarget === 'category' ? 'التصنيف' : searchTarget === 'supplier' ? 'المورد' : 'بحث'}: ${searchQuery}`}>
                  {searchTarget === 'name'
                    ? 'اسم المادة'
                    : searchTarget === 'category'
                    ? 'التصنيف'
                    : searchTarget === 'supplier'
                    ? 'المورد'
                    : 'بحث'}
                  : "{searchQuery}"
                </span>
                <button
                  type="button"
                  onClick={() => setSearchQuery('')}
                  className="shrink-0 text-emerald-700 hover:text-rose-600 cursor-pointer"
                  title="إلغاء هذا الفلتر"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </span>
            )}
            {selectedCategory !== null && (
              <span className="inline-flex max-w-full items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span className="min-w-0 truncate" title={`التصنيف: ${categories.find((c) => c.id === selectedCategory)?.name_ar}`}>
                  التصنيف: {categories.find((c) => c.id === selectedCategory)?.name_ar}
                </span>
                <button
                  type="button"
                  onClick={() => setSelectedCategory(null)}
                  className="shrink-0 text-slate-500 hover:text-rose-600 cursor-pointer"
                  title="إلغاء هذا الفلتر"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </span>
            )}
            {selectedSupplier && (
              <span className="inline-flex max-w-full items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span className="min-w-0 truncate" title={`المورد: ${selectedSupplier}`}>المورد: {selectedSupplier}</span>
                <button
                  type="button"
                  onClick={() => setSelectedSupplier('')}
                  className="shrink-0 text-slate-500 hover:text-rose-600 cursor-pointer"
                  title="إلغاء هذا الفلتر"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </span>
            )}
            {selectedReason && (
              <span className="inline-flex max-w-full items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span className="min-w-0 truncate" title={`الفرصة: ${reasonLabel(selectedReason)}`}>الفرصة: {reasonLabel(selectedReason)}</span>
                <button
                  type="button"
                  onClick={() => setSelectedReason('')}
                  className="shrink-0 text-slate-500 hover:text-rose-600 cursor-pointer"
                  title="إلغاء هذا الفلتر"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </span>
            )}
            {onlyLiked && (
              <span className="inline-flex max-w-full items-center gap-1 px-2.5 py-1 rounded-md bg-rose-50 text-rose-900 border border-rose-200 text-[11px] font-medium">
                <span className="truncate">ما أعجب منشأتي فقط</span>
                <button
                  type="button"
                  onClick={() => setOnlyLiked(false)}
                  className="shrink-0 text-rose-700 hover:text-rose-900 cursor-pointer"
                  title="إلغاء هذا الفلتر"
                >
                  <X className="w-3.5 h-3.5" />
                </button>
              </span>
            )}
          </div>
        )}
      </div>

      {/* 2. Top Banner / Marketplace Hero */}
      <div className="bg-gradient-to-r from-emerald-900 via-emerald-800 to-slate-900 rounded-2xl p-4 sm:p-8 text-white shadow-lg relative overflow-hidden">
        <div className="relative z-10 max-w-2xl space-y-3">
          <div className="inline-flex max-w-full items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
            <Compass className="w-3.5 h-3.5 shrink-0" />
            <span className="min-w-0">عروض مخزونات نشرة من مؤسسات على المنصة</span>
          </div>
          <h1 className="text-xl sm:text-3xl font-black leading-tight tracking-tight text-white font-serif">
            ابحث في العروض المتاحة، ثم أرسل طلب شراء إلى البائع
          </h1>
          <p className="text-slate-300 text-sm leading-relaxed">
            الطلب هنا لا يوقف أي كمية: يصل إلى البائع للمراجعة، والكمية تُحجز من المخزون المتاح فقط عندما يقبله البائع،
            ولمدة الحجز المعتمدة لدى المنصة. التسعير والضريبة والعمولة تُحسب في الصفقة وفق الإعدادات المقررة، ولا تعرض
            هذه الشاشة ما يثبت توثيقاً حكومياً لأي مؤسسة.
          </p>

          {/* Quick Metrics — one idea per line on a phone, a strip from sm up */}
          <div className="pt-2 flex flex-col sm:flex-row sm:flex-wrap sm:items-center gap-2 text-xs">
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Shield className="w-4 h-4 shrink-0 text-emerald-400" />
              <span className="min-w-0">فحص البضاعة ومهلها من إعدادات المنصة</span>
            </div>
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Clock className="w-4 h-4 shrink-0 text-amber-400" />
              <span className="min-w-0">
                {reservationWindowMinutes()
                  ? `مدة الحجز بعد قبول البائع: ${reservationWindowMinutes()} دقيقة`
                  : 'مدة الحجز: لم تُقرأ من إعدادات المنصة بعد'}
              </span>
            </div>
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Truck className="w-4 h-4 shrink-0 text-sky-400" />
              <span className="min-w-0">شروط التسليم كما حدّدها البائع لكل عرض</span>
            </div>
          </div>
        </div>

        {/* Ambient background decoration */}
        <div className="absolute -left-12 -bottom-12 w-80 h-80 bg-emerald-600/20 rounded-full blur-3xl pointer-events-none"></div>
      </div>

      {/* 3. Category Quick Pills & Geo Discovery Bar */}
      <div className="bg-white border border-slate-200 rounded-xl p-3 sm:p-4 shadow-2xs space-y-3">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
          {/* Category Quick Pills */}
          <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none text-xs w-full">
            <button
              onClick={() => setSelectedCategory(null)}
              className={`px-3 py-1.5 rounded-full font-medium transition-colors whitespace-nowrap cursor-pointer ${
                selectedCategory === null
                  ? 'bg-slate-900 text-white'
                  : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
              }`}
            >
              جميع التصنيفات
            </button>
            {categories.map((c) => (
              <button
                key={c.id}
                onClick={() => setSelectedCategory(c.id)}
                className={`px-3 py-1.5 rounded-full font-medium transition-colors whitespace-nowrap cursor-pointer ${
                  selectedCategory === c.id
                    ? 'bg-emerald-700 text-white'
                    : 'bg-slate-100 text-slate-600 hover:bg-slate-200'
                }`}
              >
                {categoryLabel(c)}
              </button>
            ))}
          </div>

          {/* Geo Distance Discovery Toggle */}
          <div className="flex flex-col items-stretch gap-2 w-full sm:w-72 lg:w-80 sm:shrink-0">
            <button
              onClick={() => setGeoMode(!geoMode)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center justify-center gap-1.5 border transition-all cursor-pointer ${
                geoMode
                  ? 'bg-emerald-600 text-white border-emerald-600 shadow-xs'
                  : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'
              }`}
            >
              <MapPin className="w-3.5 h-3.5 shrink-0" />
              <span>{geoMode ? 'مفعل: الأقرب جغرافياً' : 'تفعيل البحث بالأقرب (كم)'}</span>
            </button>

            {geoMode && (
              <div className="bg-emerald-50/60 border border-emerald-200 rounded-lg p-2.5 space-y-2 text-xs">
                <div className="flex flex-wrap items-center gap-2">
                  <button
                    type="button"
                    onClick={useMyLocation}
                    className="flex-1 sm:flex-none px-2.5 py-1.5 rounded-md bg-emerald-700 text-white font-bold cursor-pointer text-center"
                  >
                    استخدم موقعي من المتصفح
                  </button>
                  <span className="text-[10px] text-slate-500">أو أدخل الإحداثيات يدوياً</span>
                </div>

                <div className="grid grid-cols-2 gap-2">
                  <label className="flex flex-col gap-1 text-[11px] text-slate-600 min-w-0">
                    <span>خط العرض</span>
                    <input
                      type="number"
                      step="0.000001"
                      inputMode="decimal"
                      value={geoPoint.lat ?? ''}
                      onChange={(e) =>
                        setGeoPoint({ lat: e.target.value === '' ? null : Number(e.target.value), lon: geoPoint.lon, label: 'إحداثيات مُدخلة' })
                      }
                      className="w-full min-w-0 bg-white border border-emerald-200 rounded-md px-2 py-1 font-mono text-[11px] focus:outline-none"
                    />
                  </label>
                  <label className="flex flex-col gap-1 text-[11px] text-slate-600 min-w-0">
                    <span>خط الطول</span>
                    <input
                      type="number"
                      step="0.000001"
                      inputMode="decimal"
                      value={geoPoint.lon ?? ''}
                      onChange={(e) =>
                        setGeoPoint({ lat: geoPoint.lat, lon: e.target.value === '' ? null : Number(e.target.value), label: 'إحداثيات مُدخلة' })
                      }
                      className="w-full min-w-0 bg-white border border-emerald-200 rounded-md px-2 py-1 font-mono text-[11px] focus:outline-none"
                    />
                  </label>
                  <label className="col-span-2 flex flex-col gap-1 text-[11px] text-slate-600 min-w-0">
                    <span>نصف القطر (كم)</span>
                    <input
                      type="number"
                      min="1"
                      max={config.maxNearbyRadiusKm ?? undefined}
                      inputMode="numeric"
                      value={radiusKm}
                      onChange={(e) => setRadiusKm(e.target.value === '' ? '' : Number(e.target.value))}
                      className="w-full min-w-0 bg-white border border-emerald-200 rounded-md px-2 py-1 font-mono text-[11px] focus:outline-none"
                    />
                  </label>
                </div>

                <p className="text-[10px] text-slate-500">
                  {config.maxNearbyRadiusKm
                    ? `أقصى نصف قطر يقبله الخادم: ${config.maxNearbyRadiusKm} كم`
                    : 'سقف نصف القطر لم يُقرأ من إعدادات المنصة بعد.'}
                  {geoPoint.label && geoPoint.lat !== null && geoPoint.lon !== null
                    ? ` • القياس من ${geoPoint.label}: ${geoPoint.lat}, ${geoPoint.lon}`
                    : ' • لا نقطة مرجعية بعد، والنتائج لن تُجلب.'}
                </p>
              </div>
            )}
          </div>
        </div>

        {geoError && (
          <div className="text-xs rounded-lg px-3 py-2 border bg-amber-50 border-amber-200 text-amber-900">
            {geoError}
          </div>
        )}
      </div>

      {/* What the server refused about interest or questions — its own wording,
          dismissible, and above the cards the reader might blame for it. */}
      {communityNotice && (
        <div className="mb-4 flex items-start justify-between gap-3 text-xs rounded-lg px-3 py-2.5 border bg-amber-50 border-amber-200 text-amber-900">
          <span className="leading-relaxed">{communityNotice}</span>
          <button
            type="button"
            onClick={clearNotice}
            className="shrink-0 font-bold text-amber-800 hover:text-amber-950 underline cursor-pointer"
          >
            إخفاء
          </button>
        </div>
      )}

      {/* Offers Grid */}
      {loading ? (
        <div className="py-20 text-center space-y-3">
          {/* The mark breathing, rather than a detached spinner: this is the
              screen a buyer judges the whole platform on, and it is the one
              place they wait. */}
          <BrandMark size={44} label="حصّال" className="mx-auto animate-pulse" />
          <p className="text-xs text-slate-500 font-medium">جارٍ فحص وتحديث أسعار وعروض المخزون...</p>
        </div>
      ) : filteredOffers.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-xl p-8 sm:p-12 text-center space-y-3">
          <Package className="w-12 h-12 text-slate-300 mx-auto" />
          <h3 className="text-sm font-bold text-slate-800">لا توجد عروض مطابقة للبحث حالياً</h3>
          <p className="text-xs text-slate-500">جرب تغيير التصنيف أو خيارات البحث لاستعراض باقي الفرص المتاحة.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 sm:gap-5">
          {filteredOffers.map((offer) => {
            const seller = sellerLabel(offer, locale);
            /*
             * Read a name in the language the visitor is using, and fall back to
             * the other column when the seller only recorded one — an English
             * screen that printed nothing at all, or an empty `alt`, is a screen
             * that lost information the row holds.
             */
            const productName = offerName(offer, locale);
            const variantName = localizedField(offer, 'variant_name', locale);
            const brandName = localizedField(offer, 'brand_name', locale);
            const coverImage = offer.product_cover_image || offer.product_image || offer.variant_image;
            const warehouse = [offer.warehouse_city, offer.warehouse_name].filter(Boolean).join(' — ');
            const cover = typeof coverImage === 'string' ? coverImage : '';
            const where = warehouse || (locale === 'en' ? 'Warehouse not recorded' : 'مستودع غير محدد');
            const totalStock =
              Number(offer.total_quantity) > 0
                ? Number(offer.total_quantity)
                : Number(offer.available_quantity) + Number(offer.reserved_quantity) + Number(offer.sold_quantity);
            const availablePct = totalStock > 0 ? (Number(offer.available_quantity) / totalStock) * 100 : 0;

            return (
              <div
                key={offer.id}
                className="bg-white border border-slate-200 rounded-xl overflow-hidden hover:border-emerald-400 hover:shadow-md transition-all flex flex-col justify-between group"
              >
                <div>
                  {/* Top Image Container with Badges */}
                  <div className="relative h-48 bg-slate-100 overflow-hidden">
                    {cover ? (
                      <img
                        src={cover}
                        alt={productName}
                        className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                      />
                    ) : (
                      <div className="w-full h-full flex flex-col items-center justify-center gap-1 bg-slate-50 text-slate-400">
                        <Package className="w-8 h-8" />
                        <span className="text-[10px] font-medium">لا صورة مرفقة بهذا الصنف</span>
                      </div>
                    )}

                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent"></div>

                    {/* Top Left: Reason Badge */}
                    <div className="absolute top-3 right-3 left-3 flex flex-wrap items-start justify-between gap-1.5">
                      <span className={`max-w-full text-[10px] font-bold px-2 py-0.5 rounded-md border shadow-2xs ${REASON_CHIP_STYLE}`}>
                        {reasonLabel(offer.opportunity_reason)}
                      </span>
                      {brandName && (
                        <span className="max-w-full text-[10px] font-medium bg-black/60 text-white px-2 py-0.5 rounded backdrop-blur-xs truncate">
                          ماركة {brandName}
                        </span>
                      )}
                    </div>

                    {/* Bottom overlay: Location & Distance */}
                    <div className="absolute bottom-2.5 right-3 left-3 flex items-center justify-between gap-2 text-white text-xs">
                      <div className="flex min-w-0 items-center gap-1 font-medium drop-shadow-sm">
                        <MapPin className="w-3.5 h-3.5 shrink-0 text-emerald-400" />
                        <span className="truncate" title={where}>
                          {where}
                        </span>
                      </div>

                      {offer.distance_km !== undefined && offer.distance_km !== null && (
                        <span className="shrink-0 bg-emerald-500/90 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">
                          يبعد {offer.distance_km} كم
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Body Content */}
                  <div className="p-4 space-y-3">
                    <div className="min-w-0">
                      <h3 className="text-sm font-bold text-slate-900 leading-snug line-clamp-2" title={productName}>
                        {productName}
                      </h3>
                      {/* A seller with a single variant usually names it after the
                          product itself, and then this line only echoes the heading
                          above it. The code is what tells two listings apart, so
                          when the names match the card keeps the code and drops the
                          echo. */}
                      <p className="text-xs text-slate-500 font-medium pt-0.5 break-words">
                        {variantName && variantName !== productName ? `${variantName} • ` : ''}كود: {offer.variant_sku}
                      </p>
                    </div>

                    {/* Seller record */}
                    <div className="space-y-1.5 bg-slate-50 border border-slate-100 rounded-lg p-2 text-[11px]">
                      <div className="flex items-center gap-1.5 text-slate-700 min-w-0">
                        <Shield
                          className={`w-3.5 h-3.5 shrink-0 ${
                            offer.seller_status === 'verified' ? 'text-emerald-600' : 'text-slate-400'
                          }`}
                        />
                        <span
                          className={`font-semibold min-w-0 truncate ${seller.hidden ? 'text-slate-500 italic' : ''}`}
                          title={seller.text}
                        >
                          {seller.text}
                        </span>
                      </div>
                      <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                        <span
                          className={`shrink-0 text-[10px] font-bold px-2 py-0.5 rounded-full border ${
                            SELLER_STATUS_STYLES[offer.seller_status ?? ''] || 'bg-slate-100 text-slate-700 border-slate-200'
                          }`}
                        >
                          {SELLER_STATUS_LABELS[offer.seller_status ?? ''] || offer.seller_status || 'حالة غير معروفة'}
                        </span>
                        <span className="text-slate-500 shrink-0">
                          {offer.seller_rating === null || offer.seller_rating === undefined
                            ? 'لا تقييم'
                            : `تقييم ${offer.seller_rating}`}
                        </span>
                      </div>
                    </div>

                    {/* Stock & Expiry Information */}
                    <div className="space-y-1.5 text-xs text-slate-600">
                      <div className="flex items-center justify-between gap-2">
                        <span className="text-slate-500 min-w-0">المخزون المتاح حالياً:</span>
                        <span className="font-bold text-slate-900 shrink-0">
                          {offer.available_quantity} {offer.unit}
                        </span>
                      </div>

                      {/* Stock Progress Bar */}
                      <div className="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden" title={`${availablePct.toFixed(1)}% من الكمية الأصلية متاح`}>
                        <div
                          className="bg-emerald-600 h-full rounded-full transition-all"
                          style={{ width: `${availablePct}%` }}
                        ></div>
                      </div>

                      <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-[11px] pt-1 text-slate-500">
                        <span>الحد الأدنى للطلب (MOQ): {offer.min_order_quantity}</span>
                        {offer.expiry_date && formatDate(offer.expiry_date, locale) && (
                          <span className="flex items-center gap-1 text-slate-600">
                            <Clock className="w-3 h-3 shrink-0 text-amber-500" />
                            انتهاء: {formatDate(offer.expiry_date, locale)}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Card Footer: Pricing & Action — the ask is the one thumb-width
                    button on a phone; interest and questions sit under it. */}
                <div className="p-4 pt-3 border-t border-slate-100 bg-slate-50/50 space-y-2.5">
                  <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <div className="min-w-0">
                      <div className="text-[11px] text-slate-400">سعر الوحدة كما نشره البائع</div>
                      <div className="flex items-baseline gap-1.5">
                        <span className="text-lg font-black text-emerald-700 font-serif">
                          {formatMoney(offer.unit_price, offer.currency_code)}
                        </span>
                      </div>
                    </div>

                    <button
                      onClick={() => handleOpenOrderModal(offer)}
                      className="w-full sm:w-auto bg-emerald-600 hover:bg-emerald-700 active:scale-98 text-white px-4 py-2.5 sm:py-2 rounded-lg text-xs font-bold transition-all shadow-xs flex items-center justify-center gap-1.5 cursor-pointer"
                    >
                      <span>طلب شراء من المتاح</span>
                    </button>
                  </div>

                  {communityOn && (
                    <div className="flex flex-wrap items-center gap-2 border-t border-slate-100 pt-2.5">
                      <OfferLikeButton
                        offer={offer}
                        liked={likedIds.has(offer.id)}
                        busy={busyId === offer.id}
                        count={offer.likes_count}
                        signedIn={signedIn}
                        onToggle={() => { void handleToggleLike(offer); }}
                      />

                      <button
                        onClick={() => setOpenThreadFor(openThreadFor === offer.id ? null : offer.id)}
                        className="bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 px-3 py-2 rounded-lg text-[11px] font-bold transition-all flex items-center gap-1.5 cursor-pointer"
                      >
                        <MessageCircle className="w-3.5 h-3.5" />
                        <span>أسئلة{offer.comments_count !== undefined ? ` (${offer.comments_count})` : ''}</span>
                      </button>
                    </div>
                  )}
                </div>

                {communityOn && openThreadFor === offer.id && (
                  <div className="border-t border-slate-100 bg-slate-50/60 p-3 sm:p-4 space-y-2">
                    <div className="flex items-center justify-between gap-2">
                      <h4 className="text-[11px] font-bold text-slate-800 min-w-0">أسئلة منشورة على هذا العرض</h4>
                      <button
                        type="button"
                        onClick={() => { setOpenThreadFor(null); clearNotice(); }}
                        className="shrink-0 text-slate-400 hover:text-slate-700 text-xs font-bold cursor-pointer"
                        title="إغلاق"
                      >
                        ✕
                      </button>
                    </div>
                    <p className="text-[10px] text-slate-500">
                      التعليقات تُنسب إلى منشؤها، وردّ الشركة البائعة يعلَّم «من البائع». لا تُخزَّن المنصة تقييمات ولا نجومًا.
                    </p>
                    <OfferThread
                      offer={offer}
                      signedIn={signedIn}
                      onCountChange={(count) => setOffers((previous) => previous.map((row) => (
                        row.id === offer.id ? { ...row, comments_count: count } : row
                      )))}
                    />
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* The ask modal: what a buyer can raise against a listing they found */}
      {activeOfferForOrder && (
        <div
          className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-end sm:items-center justify-center p-0 sm:p-4"
          onClick={(event) => {
            if (event.target === event.currentTarget) setActiveOfferForOrder(null);
          }}
        >
          <div
            role="dialog"
            aria-modal="true"
            className="bg-white rounded-t-2xl w-full sm:max-w-lg sm:rounded-2xl max-h-[92dvh] overflow-y-auto overscroll-contain pb-[env(safe-area-inset-bottom)] shadow-2xl border border-slate-200 animate-in fade-in duration-200 sm:pb-0"
          >
            {/* Modal Header — sticky, so the way out never scrolls away */}
            <div className="sticky top-0 z-10 bg-emerald-900 text-white p-4 sm:p-5 flex items-start justify-between gap-3 shadow-sm">
              <div className="min-w-0">
                <span className="text-[11px] text-emerald-300 font-medium">البائع يراجع الطلب، والحجز يحدث عندها لا هنا</span>
                <h2 className="text-base font-bold pt-0.5">طلب شراء على مخزون العرض</h2>
              </div>
              <button
                onClick={() => setActiveOfferForOrder(null)}
                className="shrink-0 text-emerald-300 hover:text-white text-lg font-bold px-2 rounded cursor-pointer"
                title="إغلاق"
              >
                ✕
              </button>
            </div>

            {/* Modal Body */}
            <div className="p-4 sm:p-6 space-y-4 sm:space-y-5">
              {/* Product Brief */}
              <div className="flex items-center gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
                <div className="w-14 h-14 rounded-lg border border-slate-200 overflow-hidden bg-white shrink-0 flex items-center justify-center">
                  {orderImage ? (
                    <img src={orderImage} alt={orderName} className="w-full h-full object-cover" />
                  ) : (
                    <Package className="w-6 h-6 text-slate-300" />
                  )}
                </div>
                {/* The same reading rules the card uses, applied to the dialog that
                    asks a person to commit: the name in their language, and the
                    variant only when it adds something to the name. */}
                <div className="min-w-0">
                  <h4 className="text-xs font-bold text-slate-900">{orderName}</h4>
                  {orderVariant && orderVariant !== orderName && (
                    <p className="text-[11px] text-slate-500">{orderVariant}</p>
                  )}
                  <p className="text-[11px] text-emerald-700 font-semibold pt-0.5">
                    سعر الوحدة: {formatMoney(activeUnitPrice, activeOfferForOrder.currency_code)} / {activeOfferForOrder.unit}
                  </p>
                </div>
              </div>

              {/* Quantity Selector */}
              <div className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-x-2 gap-y-1 text-xs">
                  <label className="font-bold text-slate-700">
                    الكمية المطلوبة ({activeOfferForOrder.unit}):
                  </label>
                  <span className="text-slate-500">
                    الحد الأدنى: {activeOfferForOrder.min_order_quantity} • المتاح: {activeOfferForOrder.available_quantity}
                  </span>
                </div>

                <div className="flex items-center gap-2 sm:gap-3">
                  <button
                    type="button"
                    onClick={() => stepQuantity(-stepSize)}
                    className="w-11 h-11 shrink-0 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-lg flex items-center justify-center cursor-pointer"
                    title="إنقاص كمية"
                  >
                    -
                  </button>

                  <input
                    type="number"
                    min={activeFloor}
                    max={activeCeiling || undefined}
                    step={stepSize}
                    inputMode="decimal"
                    value={orderQuantity}
                    onChange={(e) => {
                      const val = parseFloat(e.target.value) || 0;
                      setOrderQuantity(val);
                    }}
                    className="flex-1 min-w-0 text-center font-bold text-base bg-slate-50 border border-slate-300 rounded-lg py-2.5 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                  />

                  <button
                    type="button"
                    onClick={() => stepQuantity(stepSize)}
                    className="w-11 h-11 shrink-0 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-lg flex items-center justify-center cursor-pointer"
                    title="زيادة كمية"
                  >
                    +
                  </button>
                </div>
              </div>

              {/* Delivery Preference — only what this seller offered */}
              {(() => {
                const terms = String(activeOfferForOrder.delivery_terms || 'BOTH').toUpperCase();
                const pickupAllowed = terms === 'BUYER_PICKUP' || terms === 'BOTH';
                const deliveryAllowed = terms === 'SELLER_DELIVERY' || terms === 'BOTH';

                return (
                  <div className="space-y-2">
                    <label className="text-xs font-bold text-slate-700 block">طريقة التسليم (كما حددها البائع):</label>
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                      <button
                        type="button"
                        disabled={!pickupAllowed}
                        onClick={() => setDeliveryPreference('pickup')}
                        className={`p-3 rounded-xl border text-right transition-all ${
                          deliveryPreference === 'pickup' && pickupAllowed
                            ? 'bg-emerald-50 border-emerald-500 text-emerald-950 font-bold ring-1 ring-emerald-500 cursor-pointer'
                            : pickupAllowed
                            ? 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 cursor-pointer'
                            : 'bg-slate-50 border-slate-100 text-slate-300 cursor-not-allowed'
                        }`}
                      >
                        <div className="flex items-center gap-2 text-xs">
                          <MapPin className="w-4 h-4 shrink-0 text-emerald-600" />
                          <span className="min-w-0">استلام من مستودع البائع</span>
                        </div>
                        <div className="text-[10px] pt-1 font-normal break-words">
                          {pickupAllowed
                            ? [activeOfferForOrder.warehouse_city, activeOfferForOrder.warehouse_name].filter(Boolean).join(' — ') ||
                              'مستودع غير محدد'
                            : 'البائع لم يفتح الاستلام المباشر لهذا العرض'}
                        </div>
                      </button>

                      <button
                        type="button"
                        disabled={!deliveryAllowed}
                        onClick={() => setDeliveryPreference('delivery')}
                        className={`p-3 rounded-xl border text-right transition-all ${
                          deliveryPreference === 'delivery' && deliveryAllowed
                            ? 'bg-emerald-50 border-emerald-500 text-emerald-950 font-bold ring-1 ring-emerald-500 cursor-pointer'
                            : deliveryAllowed
                            ? 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50 cursor-pointer'
                            : 'bg-slate-50 border-slate-100 text-slate-300 cursor-not-allowed'
                        }`}
                      >
                        <div className="flex items-center gap-2 text-xs">
                          <Truck className="w-4 h-4 shrink-0 text-emerald-600" />
                          <span className="min-w-0">توصيل بطلب من البائع</span>
                        </div>
                        <div className="text-[10px] pt-1 font-normal">
                          {deliveryAllowed
                            ? 'يتفق الطرفان على الشحن عند تأكيد الصفقة'
                            : 'البائع لا يوفر التوصيل لهذا العرض'}
                        </div>
                      </button>
                    </div>
                  </div>
                );
              })()}

              {/* Order Notes */}
              <div className="space-y-1">
                <label className="text-xs font-medium text-slate-600 block">ملاحظات للبائع (تُحفظ مع طلب الشراء):</label>
                <textarea
                  rows={2}
                  maxLength={500}
                  value={orderNotes}
                  onChange={(e) => setOrderNotes(e.target.value)}
                  placeholder="موعد الاستلام المناسب، متطلبات التجهيز..."
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-xs focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                ></textarea>
              </div>

              {/* What the requested amount comes to — only the arithmetic this step performs */}
              <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-1.5 text-xs">
                <div className="flex items-start justify-between gap-3 text-slate-600">
                  <span className="min-w-0">
                    قيمة الكمية المطلوبة ({orderQuantity} × {formatMoney(activeUnitPrice, activeOfferForOrder.currency_code)}):
                  </span>
                  <span className="shrink-0 font-semibold">
                    {formatMoney(orderQuantity * activeUnitPrice, activeOfferForOrder.currency_code)}
                  </span>
                </div>

                {(() => {
                  const tax = taxSummary();
                  const commissionPercent = config.commissionRate ? (config.commissionRate * 100).toFixed(2).replace(/\.?0+$/, '') : null;
                  return (
                    <>
                      <div className="flex items-start justify-between gap-3 text-slate-500">
                        <span className="min-w-0 shrink-0">الضريبة</span>
                        <span className="text-left">
                          {tax
                            ? `${tax.label || 'ضريبة'} ${tax.ratePercent}%${tax.inclusive ? ' (متضمنة)' : ''} — تُطبَّق في الصفقة`
                            : 'لم تُفعَّل في إعدادات المنصة'}
                        </span>
                      </div>
                      <div className="flex items-start justify-between gap-3 text-slate-500">
                        <span className="min-w-0 shrink-0">عمولة المنصة</span>
                        <span>{commissionPercent ? `${commissionPercent}% — تُحتسب في الصفقة` : 'غير مقررة في الإعدادات'}</span>
                      </div>
                    </>
                  );
                })()}

                <p className="border-t border-slate-200 pt-2 text-[11px] text-slate-500 leading-relaxed">
                  هذا الطلب لا يوقف أي كمية: يُسجَّل لدى البائع للمراجعة، والمبلغ النهائي وأسراره يحسبها الخادم عند إنشاء
                  الصفقة، ولا تلغي هذه الشاشة أياً منها ولا تعرض ضماناً مالياً.
                </p>
              </div>

              {orderError && (
                <div className="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{orderError}</span>
                </div>
              )}

              {/* Submit Button — the ask is the thumb target; on a phone it sits
                  above the way out, never beside it. */}
              <div className="flex flex-col-reverse sm:flex-row items-stretch gap-2.5 pt-1">
                <button
                  type="button"
                  onClick={() => setActiveOfferForOrder(null)}
                  className="w-full sm:flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-3 sm:py-2.5 rounded-xl text-xs font-semibold cursor-pointer"
                >
                  إلغاء
                </button>
                <button
                  type="button"
                  disabled={
                    submittingOrder ||
                    orderQuantity < activeFloor ||
                    (activeCeiling > 0 && orderQuantity > activeCeiling)
                  }
                  onClick={handleSubmitOrder}
                  className="w-full sm:flex-[2] bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-3 sm:py-2.5 rounded-xl text-xs font-bold transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer"
                >
                  {submittingOrder ? (
                    <span className="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
                  ) : (
                    <>
                      <CheckCircle2 className="w-4 h-4" />
                      <span>إرسال طلب الشراء للبائع</span>
                    </>
                  )}
                </button>
              </div>

              {(orderQuantity < activeFloor || (activeCeiling > 0 && orderQuantity > activeCeiling)) && (
                <p className="text-[11px] text-rose-700 leading-relaxed">
                  الكمية خارج ما سجله العرض: الحد الأدنى {activeFloor} والمتاح {activeCeiling} {activeOfferForOrder.unit}.
                </p>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

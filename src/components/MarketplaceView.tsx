import React, { useState, useEffect, useMemo } from 'react';
import { 
  MapPin, 
  Clock, 
  Package, 
  Shield, 
  TrendingDown, 
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
  Sparkles
} from 'lucide-react';
import { api } from '../lib/api';
import type { Offer, Category, Brand } from '../types';
import type { Locale } from '../lib/i18n';

interface MarketplaceViewProps {
  onOrderSuccess: (purchaseRequest: any) => void;
  userCity?: string;
  locale?: Locale;
}

const OPPORTUNITY_REASONS_MAP: Record<string, { label: string; color: string }> = {
  surplus_stock: { label: 'فائض مخزون تعاقدي', color: 'bg-blue-50 text-blue-800 border-blue-200' },
  slow_moving: { label: 'تصريف مخزون بطيء', color: 'bg-amber-50 text-amber-800 border-amber-200' },
  overproduction: { label: 'وفرة إنتاج مصنعي', color: 'bg-emerald-50 text-emerald-800 border-emerald-200' },
  assortment_change: { label: 'تغيير تشكيلة وتغليف', color: 'bg-purple-50 text-purple-800 border-purple-200' },
  seasonal_inventory: { label: 'مخزون ما قبل الموسم', color: 'bg-indigo-50 text-indigo-800 border-indigo-200' },
  commercial_liquidation: { label: 'تصفية خط تجاري فوري', color: 'bg-rose-50 text-rose-800 border-rose-200' },
};

const normalizeOffer = (offer: Offer): Offer => {
  const numericFields = [
    'available_quantity', 'reserved_quantity', 'sold_quantity', 'unit_price',
    'market_reference_price', 'min_order_quantity', 'max_order_quantity',
    'seller_rating', 'category_id', 'brand_id', 'warehouse_lat', 'warehouse_lon', 'distance_km',
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

export const MarketplaceView: React.FC<MarketplaceViewProps> = ({ onOrderSuccess, userCity = 'الرياض', locale = 'ar' }) => {
  const categoryLabel = (category: Category) => locale === 'en' ? category.name_en : category.name_ar;
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
  const [sortBy, setSortBy] = useState<'newest' | 'cheapest' | 'expiring_soon'>('newest');
  const [geoMode, setGeoMode] = useState<boolean>(false);
  const [selectedCityCoord, setSelectedCityCoord] = useState<{ lat: number; lon: number; name: string }>({
    lat: 24.7136,
    lon: 46.6753,
    name: 'الرياض (حي العليا)',
  });

  // Modal State
  const [activeOfferForOrder, setActiveOfferForOrder] = useState<Offer | null>(null);
  const [orderQuantity, setOrderQuantity] = useState<number>(20);
  const [deliveryPreference, setDeliveryPreference] = useState<'pickup' | 'delivery'>('pickup');
  const [orderNotes, setOrderNotes] = useState('');
  const [submittingOrder, setSubmittingOrder] = useState(false);
  const [orderError, setOrderError] = useState<string | null>(null);

  const loadData = async () => {
    setLoading(true);
    try {
      const [catRes, brandRes] = await Promise.all([
        api.getCategories(),
        api.getBrands(),
      ]);
      if (catRes.success && catRes.data) setCategories(catRes.data);
      if (brandRes.success && brandRes.data) setBrands(brandRes.data);

      if (geoMode) {
        const nearRes = await api.getNearbyOffers(selectedCityCoord.lat, selectedCityCoord.lon, 350);
        if (nearRes.success && nearRes.data) setOffers(nearRes.data.map(normalizeOffer));
      } else {
        const offRes = await api.getOffers({
          sort_by: sortBy,
        });
        if (offRes.success && offRes.data) setOffers(offRes.data.map(normalizeOffer));
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [sortBy, geoMode, selectedCityCoord]);

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
    maxPrice !== ''
  );

  const handleResetFilters = () => {
    setSearchQuery('');
    setSearchTarget('all');
    setSelectedCategory(null);
    setSelectedSupplier('');
    setSelectedReason('');
    setMaxPrice('');
  };

  const handleOpenOrderModal = (offer: Offer) => {
    setActiveOfferForOrder(offer);
    setOrderQuantity(offer.min_order_quantity || 10);
    setOrderNotes('');
    setOrderError(null);
  };

  const handleSubmitOrder = async () => {
    if (!activeOfferForOrder) return;
    setSubmittingOrder(true);
    setOrderError(null);

    const res = await api.createPurchaseRequest({
      offer_id: activeOfferForOrder.id,
      quantity: orderQuantity,
      delivery_preference: deliveryPreference,
      notes: orderNotes,
    });

    setSubmittingOrder(false);
    if (res.success && res.data) {
      setActiveOfferForOrder(null);
      onOrderSuccess(res.data);
      loadData(); // Refresh inventory counts
    } else {
      setOrderError(res.message || 'تعذر حجز الكمية');
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

      // 4. Filter by max unit price
      if (maxPrice !== '' && o.unit_price > Number(maxPrice)) {
        return false;
      }

      // 5. Automatic text search with chosen search scope
      if (searchQuery.trim()) {
        const q = searchQuery.toLowerCase().trim();

        if (searchTarget === 'name') {
          const matchName =
            (o.product_name_ar && o.product_name_ar.toLowerCase().includes(q)) ||
            (o.variant_name_ar && o.variant_name_ar.toLowerCase().includes(q)) ||
            (o.sku && o.sku.toLowerCase().includes(q)) ||
            (o.brand_name_ar && o.brand_name_ar.toLowerCase().includes(q));
          if (!matchName) return false;
        } else if (searchTarget === 'category') {
          const matchCat = o.category_name_ar && o.category_name_ar.toLowerCase().includes(q);
          if (!matchCat) return false;
        } else if (searchTarget === 'supplier') {
          const matchSupplier =
            (o.seller_name_ar && o.seller_name_ar.toLowerCase().includes(q)) ||
            (o.seller_cr && o.seller_cr.toLowerCase().includes(q));
          if (!matchSupplier) return false;
        } else {
          // 'all': matches either product name, category, supplier, SKU, brand, or warehouse city
          const matchAll =
            (o.product_name_ar && o.product_name_ar.toLowerCase().includes(q)) ||
            (o.variant_name_ar && o.variant_name_ar.toLowerCase().includes(q)) ||
            (o.category_name_ar && o.category_name_ar.toLowerCase().includes(q)) ||
            (o.seller_name_ar && o.seller_name_ar.toLowerCase().includes(q)) ||
            (o.brand_name_ar && o.brand_name_ar.toLowerCase().includes(q)) ||
            (o.sku && o.sku.toLowerCase().includes(q)) ||
            (o.warehouse_city && o.warehouse_city.toLowerCase().includes(q));
          if (!matchAll) return false;
        }
      }

      return true;
    });
  }, [offers, selectedCategory, selectedSupplier, selectedReason, maxPrice, searchQuery, searchTarget, categories, locale]);

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* 1. Advanced Search & Auto-Filter Bar at the Top */}
      <div className="bg-white border-2 border-emerald-600/30 rounded-2xl p-5 shadow-xs space-y-4">
        {/* Header with Title and Real-time Status */}
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-emerald-100 text-emerald-800 flex items-center justify-center">
              <Search className="w-4 h-4" />
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-sm font-black text-slate-900 font-serif">شريط البحث المتقدم والتصفية التلقائية</h2>
                <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">
                  تصفية فورية
                </span>
              </div>
              <p className="text-[11px] text-slate-500 pt-0.5">
                ابحث عن المواد الغذائية بالاسم أو التصنيف أو المورد مع تحديث فوري مباشر للنتائج
              </p>
            </div>
          </div>

          <div className="flex items-center gap-2 w-full sm:w-auto justify-between sm:justify-end">
            <span className="text-xs font-bold px-2.5 py-1 rounded-lg bg-slate-100 text-slate-700">
              النتائج: <strong className="text-emerald-700">{filteredOffers.length}</strong> من {offers.length} عرض
            </span>
            {hasActiveFilters && (
              <button
                onClick={handleResetFilters}
                className="text-xs text-rose-600 hover:text-rose-800 font-bold flex items-center gap-1 hover:underline cursor-pointer bg-rose-50 px-2 py-1 rounded-lg border border-rose-200"
              >
                <RotateCcw className="w-3 h-3" />
                <span>مسح الفلاتر</span>
              </button>
            )}
          </div>
        </div>

        {/* Search Scope Tabs & Main Input Field */}
        <div className="flex flex-col md:flex-row items-stretch gap-2.5">
          {/* Scope Selector: All, Name, Category, Supplier */}
          <div className="flex items-center bg-slate-100 p-1 rounded-xl text-xs font-medium shrink-0 overflow-x-auto">
            <button
              type="button"
              onClick={() => setSearchTarget('all')}
              className={`px-3 py-1.5 rounded-lg transition-all whitespace-nowrap cursor-pointer ${
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
              className={`px-3 py-1.5 rounded-lg transition-all whitespace-nowrap cursor-pointer ${
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
              className={`px-3 py-1.5 rounded-lg transition-all whitespace-nowrap cursor-pointer ${
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
              className={`px-3 py-1.5 rounded-lg transition-all whitespace-nowrap cursor-pointer ${
                searchTarget === 'supplier'
                  ? 'bg-white text-emerald-900 font-bold shadow-2xs'
                  : 'text-slate-600 hover:text-slate-900'
              }`}
            >
              المورد / البائع
            </button>
          </div>

          {/* Search Input Field */}
          <div className="relative flex-1">
            <Search className="w-4 h-4 text-emerald-600 absolute right-3.5 top-1/2 -translate-y-1/2 pointer-events-none" />
            <input
              type="text"
              placeholder={
                searchTarget === 'name'
                  ? 'اكتب اسم المادة الغذائية (مثل: زيت، أرز، معكرونة، حليب، دقيق، سكر...)'
                  : searchTarget === 'category'
                  ? 'اكتب اسم التصنيف (مثل: زيوت ودهون، أرز وحبوب، معلبات وصلصات...)'
                  : searchTarget === 'supplier'
                  ? 'اكتب اسم المورد أو الشركة (مثل: الأغذية الوطنية، تموين الخليج، مصنع الشرق...)'
                  : 'ابحث عن أي مادة غذائية بالاسم أو التصنيف أو المورد التجاري...'
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
              {Object.entries(OPPORTUNITY_REASONS_MAP).map(([key, val]) => (
                <option key={key} value={key}>
                  {val.label}
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
              onChange={(e: any) => setSortBy(e.target.value)}
              className="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-2 text-xs text-slate-800 font-medium focus:outline-none focus:ring-1 focus:ring-emerald-500"
            >
              <option value="newest">الأحدث وصولاً بالمستودعات</option>
              <option value="cheapest">الأقل سعراً للوحدة (ر.س)</option>
              <option value="expiring_soon">الأقرب انتهاءً (أعلى خصم)</option>
            </select>
          </div>
        </div>

        {/* Active Filter Chips Bar */}
        {hasActiveFilters && (
          <div className="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-100 text-xs">
            <span className="text-slate-400 font-medium text-[11px]">الفلاتر المفعلة:</span>
            {searchQuery && (
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-emerald-50 text-emerald-900 border border-emerald-200 text-[11px] font-bold">
                <span>
                  {searchTarget === 'name'
                    ? 'اسم المادة'
                    : searchTarget === 'category'
                    ? 'التصنيف'
                    : searchTarget === 'supplier'
                    ? 'المورد'
                    : 'بحث'}
                  : "{searchQuery}"
                </span>
                <button onClick={() => setSearchQuery('')} className="hover:text-rose-600 cursor-pointer">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
            {selectedCategory !== null && (
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span>التصنيف: {categories.find((c) => c.id === selectedCategory)?.name_ar}</span>
                <button onClick={() => setSelectedCategory(null)} className="hover:text-rose-600 cursor-pointer">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
            {selectedSupplier && (
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span>المورد: {selectedSupplier}</span>
                <button onClick={() => setSelectedSupplier('')} className="hover:text-rose-600 cursor-pointer">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
            {selectedReason && (
              <span className="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-slate-100 text-slate-800 border border-slate-200 text-[11px] font-medium">
                <span>الفرصة: {OPPORTUNITY_REASONS_MAP[selectedReason]?.label}</span>
                <button onClick={() => setSelectedReason('')} className="hover:text-rose-600 cursor-pointer">
                  <X className="w-3 h-3" />
                </button>
              </span>
            )}
          </div>
        )}
      </div>

      {/* 2. Top Banner / Marketplace Hero */}
      <div className="bg-gradient-to-r from-emerald-900 via-emerald-800 to-slate-900 rounded-2xl p-6 sm:p-8 text-white shadow-lg relative overflow-hidden">
        <div className="relative z-10 max-w-2xl space-y-3">
          <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-300 border border-emerald-400/30">
            <Compass className="w-3.5 h-3.5" />
            <span>سوق الجملة المباشر • أسعار المصنع وتصفيات المخزون</span>
          </div>
          <h1 className="text-2xl sm:text-3xl font-black tracking-tight text-white font-serif">
            فرص استثنائية للمخزون الغذائي والسلع الأساسية
          </h1>
          <p className="text-slate-300 text-sm leading-relaxed">
            اشترِ كميات تجارية من كبار الموردين المعتمدين مع حجز فوري للمخزون، وضمان مالي كامل عبر حساب الأمانات حتى استلام وفحص البضاعة.
          </p>

          {/* Quick Metrics */}
          <div className="pt-2 flex flex-wrap items-center gap-4 text-xs">
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Shield className="w-4 h-4 text-emerald-400" />
              <span>فحص جودة وامتثال قابل للتهيئة</span>
            </div>
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Clock className="w-4 h-4 text-amber-400" />
              <span>حجز ذري لمدة 30 دقيقة</span>
            </div>
            <div className="bg-white/10 backdrop-blur-xs px-3 py-1.5 rounded-lg flex items-center gap-2">
              <Truck className="w-4 h-4 text-sky-400" />
              <span>استلام مباشر أو شحن مبرد</span>
            </div>
          </div>
        </div>

        {/* Ambient background decoration */}
        <div className="absolute -left-12 -bottom-12 w-80 h-80 bg-emerald-600/20 rounded-full blur-3xl pointer-events-none"></div>
      </div>

      {/* 3. Category Quick Pills & Geo Discovery Bar */}
      <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-3">
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3">
          {/* Category Quick Pills */}
          <div className="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none text-xs w-full sm:w-auto">
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
          <div className="flex items-center gap-2 shrink-0">
            <button
              onClick={() => setGeoMode(!geoMode)}
              className={`px-3 py-1.5 rounded-lg text-xs font-semibold flex items-center gap-1.5 border transition-all cursor-pointer ${
                geoMode
                  ? 'bg-emerald-600 text-white border-emerald-600 shadow-xs'
                  : 'bg-slate-50 text-slate-700 border-slate-200 hover:bg-slate-100'
              }`}
            >
              <MapPin className="w-3.5 h-3.5" />
              <span>{geoMode ? 'مفعل: الأقرب جغرافياً' : 'تفعيل البحث بالأقرب (KM)'}</span>
            </button>

            {geoMode && (
              <select
                value={selectedCityCoord.name}
                onChange={(e) => {
                  const val = e.target.value;
                  if (val === 'Sanaa') setSelectedCityCoord({ lat: 15.3694, lon: 44.1910, name: 'صنعاء' });
                  else if (val === 'Aden') setSelectedCityCoord({ lat: 12.7855, lon: 45.0187, name: 'عدن' });
                  else setSelectedCityCoord({ lat: 26.4207, lon: 50.0888, name: 'الدمام والخبر' });
                }}
                className="bg-emerald-50 text-emerald-900 border border-emerald-200 rounded-lg px-2.5 py-1.5 text-xs font-semibold focus:outline-none"
              >
                <option value="Sanaa">صنعاء</option>
                <option value="Aden">عدن</option>
                <option value="Taiz">تعز</option>
              </select>
            )}
          </div>
        </div>
      </div>

      {/* Offers Grid */}
      {loading ? (
        <div className="py-20 text-center space-y-3">
          <div className="w-10 h-10 border-3 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
          <p className="text-xs text-slate-500 font-medium">جاري فحص وتحديث أسعار وعروض المخزون...</p>
        </div>
      ) : filteredOffers.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-xl p-12 text-center space-y-3">
          <Package className="w-12 h-12 text-slate-300 mx-auto" />
          <h3 className="text-sm font-bold text-slate-800">لا توجد عروض مطابقة للبحث حالياً</h3>
          <p className="text-xs text-slate-500">جرب تغيير التصنيف أو خيارات البحث لاستعراض باقي الفرص المتاحة.</p>
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
          {filteredOffers.map((offer) => {
            const reasonConfig = OPPORTUNITY_REASONS_MAP[offer.opportunity_reason] || {
              label: offer.opportunity_reason,
              color: 'bg-slate-100 text-slate-800 border-slate-200',
            };

            const discount =
              offer.market_reference_price && offer.market_reference_price > offer.unit_price
                ? Math.round(((offer.market_reference_price - offer.unit_price) / offer.market_reference_price) * 100)
                : null;

            const totalStock = offer.available_quantity + offer.reserved_quantity + offer.sold_quantity;
            const availablePct = totalStock > 0 ? (offer.available_quantity / totalStock) * 100 : 0;

            return (
              <div
                key={offer.id}
                className="bg-white border border-slate-200 rounded-xl overflow-hidden hover:border-emerald-400 hover:shadow-md transition-all flex flex-col justify-between group"
              >
                <div>
                  {/* Top Image Container with Badges */}
                  <div className="relative h-48 bg-slate-100 overflow-hidden">
                    <img
                      src={offer.product_image || offer.variant_image || 'https://images.unsplash.com/photo-1586201375761-83865001e31c?w=600&auto=format&fit=crop&q=80'}
                      alt={offer.product_name_ar}
                      className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                    />

                    <div className="absolute inset-0 bg-gradient-to-t from-slate-950/60 via-transparent to-transparent"></div>

                    {/* Top Left: Reason Badge */}
                    <div className="absolute top-3 right-3 flex flex-col gap-1.5">
                      <span className={`text-[10px] font-bold px-2 py-0.5 rounded-md border shadow-2xs ${reasonConfig.color}`}>
                        {reasonConfig.label}
                      </span>
                      {offer.brand_name_ar && (
                        <span className="text-[10px] font-medium bg-black/60 text-white px-2 py-0.5 rounded backdrop-blur-xs w-fit">
                          ماركة {offer.brand_name_ar}
                        </span>
                      )}
                    </div>

                    {/* Top Right: Discount Pill */}
                    {discount && (
                      <div className="absolute top-3 left-3 bg-rose-600 text-white text-[11px] font-black px-2 py-0.5 rounded-md shadow-xs flex items-center gap-1">
                        <TrendingDown className="w-3 h-3" />
                        <span>وفر {discount}%</span>
                      </div>
                    )}

                    {/* Bottom overlay: Location & Distance */}
                    <div className="absolute bottom-2.5 right-3 left-3 flex items-center justify-between text-white text-xs">
                      <div className="flex items-center gap-1 font-medium drop-shadow-sm">
                        <MapPin className="w-3.5 h-3.5 text-emerald-400" />
                        <span>{offer.warehouse_city} — {offer.warehouse_name?.split(' ')[1]}</span>
                      </div>

                      {offer.distance_km !== undefined && (
                        <span className="bg-emerald-500/90 text-white text-[10px] font-bold px-2 py-0.5 rounded-full">
                          يبعد {offer.distance_km} كم
                        </span>
                      )}
                    </div>
                  </div>

                  {/* Body Content */}
                  <div className="p-4 space-y-3">
                    <div>
                      <h3 className="text-sm font-bold text-slate-900 leading-snug">
                        {offer.product_name_ar}
                      </h3>
                      <p className="text-xs text-slate-500 font-medium pt-0.5">
                        {offer.variant_name_ar} • كود: {offer.sku}
                      </p>
                    </div>

                    {/* Seller Trust Badge */}
                    <div className="flex items-center justify-between text-[11px] bg-slate-50 border border-slate-100 rounded-lg p-2">
                      <div className="flex items-center gap-1.5 text-slate-700">
                        <Shield className="w-3.5 h-3.5 text-emerald-600" />
                        <span className="font-semibold">{offer.seller_name_ar}</span>
                      </div>
                      <span className="text-emerald-700 font-bold">★ {offer.seller_rating || '5.0'}</span>
                    </div>

                    {/* Stock & Expiry Information */}
                    <div className="space-y-1.5 text-xs text-slate-600">
                      <div className="flex items-center justify-between">
                        <span className="text-slate-500">المخزون المتاح حالياً:</span>
                        <span className="font-bold text-slate-900">
                          {offer.available_quantity} {offer.unit_name_ar}
                        </span>
                      </div>

                      {/* Stock Progress Bar */}
                      <div className="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden">
                        <div
                          className="bg-emerald-600 h-full rounded-full transition-all"
                          style={{ width: `${Math.max(10, availablePct)}%` }}
                        ></div>
                      </div>

                      <div className="flex items-center justify-between text-[11px] pt-1 text-slate-500">
                        <span>الحد الأدنى للطلب (MOQ): {offer.min_order_quantity}</span>
                        {offer.expiry_date && (
                          <span className="flex items-center gap-1 text-slate-600">
                            <Clock className="w-3 h-3 text-amber-500" />
                            انتهاء: {offer.expiry_date}
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>

                {/* Card Footer: Pricing & Action */}
                <div className="p-4 pt-2 border-t border-slate-100 flex items-center justify-between gap-3 bg-slate-50/50">
                  <div>
                    <div className="text-[11px] text-slate-400">سعر الوحدة بالجملة</div>
                    <div className="flex items-baseline gap-1.5">
                      <span className="text-lg font-black text-emerald-700 font-serif">
                        {offer.unit_price.toFixed(2)}
                      </span>
                      <span className="text-xs font-bold text-emerald-800">ر.س</span>

                      {offer.market_reference_price && (
                        <span className="text-xs text-slate-400 line-through">
                          {offer.market_reference_price.toFixed(2)}
                        </span>
                      )}
                    </div>
                  </div>

                  <button
                    onClick={() => handleOpenOrderModal(offer)}
                    className="bg-emerald-600 hover:bg-emerald-700 active:scale-98 text-white px-3.5 py-2 rounded-lg text-xs font-bold transition-all shadow-xs flex items-center gap-1.5 cursor-pointer"
                  >
                    <span>احجز واطلب كمية</span>
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Atomic Reservation & Purchase Request Modal */}
      {activeOfferForOrder && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-lg w-full shadow-2xl border border-slate-200 overflow-hidden animate-in fade-in zoom-in-95 duration-200">
            {/* Modal Header */}
            <div className="bg-emerald-900 text-white p-5 flex items-center justify-between">
              <div>
                <span className="text-[11px] text-emerald-300 font-medium">حجز المخزون الذري الفوري (Pessimistic Lock)</span>
                <h2 className="text-base font-bold pt-0.5">طلب شراء وحجز كمية من العرض</h2>
              </div>
              <button
                onClick={() => setActiveOfferForOrder(null)}
                className="text-emerald-300 hover:text-white text-lg font-bold p-1 rounded"
              >
                ✕
              </button>
            </div>

            {/* Modal Body */}
            <div className="p-6 space-y-5">
              {/* Product Brief */}
              <div className="flex items-center gap-3 bg-slate-50 p-3 rounded-xl border border-slate-200">
                <img
                  src={activeOfferForOrder.product_image || activeOfferForOrder.variant_image}
                  alt={activeOfferForOrder.product_name_ar}
                  className="w-14 h-14 object-cover rounded-lg border border-slate-200"
                />
                <div>
                  <h4 className="text-xs font-bold text-slate-900">{activeOfferForOrder.product_name_ar}</h4>
                  <p className="text-[11px] text-slate-500">{activeOfferForOrder.variant_name_ar}</p>
                  <p className="text-[11px] text-emerald-700 font-semibold pt-0.5">
                    سعر الوحدة: {activeOfferForOrder.unit_price} ر.س / {activeOfferForOrder.unit_name_ar}
                  </p>
                </div>
              </div>

              {/* Quantity Selector */}
              <div className="space-y-2">
                <div className="flex items-center justify-between text-xs">
                  <label className="font-bold text-slate-700">
                    الكمية المطلوبة ({activeOfferForOrder.unit_name_ar}):
                  </label>
                  <span className="text-slate-500">
                    الحد الأدنى: {activeOfferForOrder.min_order_quantity} • المتاح: {activeOfferForOrder.available_quantity}
                  </span>
                </div>

                <div className="flex items-center gap-3">
                  <button
                    type="button"
                    onClick={() => setOrderQuantity((prev) => Math.max(activeOfferForOrder.min_order_quantity, prev - 10))}
                    className="w-10 h-10 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-base flex items-center justify-center cursor-pointer"
                  >
                    -
                  </button>

                  <input
                    type="number"
                    min={activeOfferForOrder.min_order_quantity}
                    max={activeOfferForOrder.available_quantity}
                    value={orderQuantity}
                    onChange={(e) => {
                      const val = parseFloat(e.target.value) || 0;
                      setOrderQuantity(val);
                    }}
                    className="flex-1 text-center font-bold text-base bg-slate-50 border border-slate-300 rounded-lg py-2 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                  />

                  <button
                    type="button"
                    onClick={() => setOrderQuantity((prev) => Math.min(activeOfferForOrder.available_quantity, prev + 10))}
                    className="w-10 h-10 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-base flex items-center justify-center cursor-pointer"
                  >
                    +
                  </button>
                </div>
              </div>

              {/* Delivery Preference */}
              <div className="space-y-2">
                <label className="text-xs font-bold text-slate-700 block">طريقة التسليم:</label>
                <div className="grid grid-cols-2 gap-3">
                  <button
                    type="button"
                    onClick={() => setDeliveryPreference('pickup')}
                    className={`p-3 rounded-xl border text-right transition-all cursor-pointer ${
                      deliveryPreference === 'pickup'
                        ? 'bg-emerald-50 border-emerald-500 text-emerald-950 font-bold ring-1 ring-emerald-500'
                        : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    <div className="flex items-center gap-2 text-xs">
                      <MapPin className="w-4 h-4 text-emerald-600" />
                      <span>استلام من مستودع البائع</span>
                    </div>
                    <div className="text-[10px] text-slate-500 pt-1 font-normal">
                      {activeOfferForOrder.warehouse_city} — {activeOfferForOrder.warehouse_name}
                    </div>
                  </button>

                  <button
                    type="button"
                    onClick={() => setDeliveryPreference('delivery')}
                    className={`p-3 rounded-xl border text-right transition-all cursor-pointer ${
                      deliveryPreference === 'delivery'
                        ? 'bg-emerald-50 border-emerald-500 text-emerald-950 font-bold ring-1 ring-emerald-500'
                        : 'bg-white border-slate-200 text-slate-600 hover:bg-slate-50'
                    }`}
                  >
                    <div className="flex items-center gap-2 text-xs">
                      <Truck className="w-4 h-4 text-emerald-600" />
                      <span>شحن وتوصيل لمستودعي</span>
                    </div>
                    <div className="text-[10px] text-slate-500 pt-1 font-normal">
                      يتم ترتيب بوليصة الشحن مع المورد
                    </div>
                  </button>
                </div>
              </div>

              {/* Order Notes */}
              <div className="space-y-1">
                <label className="text-xs font-medium text-slate-600 block">ملاحظات ومواعيد الاستلام المقترحة:</label>
                <textarea
                  rows={2}
                  value={orderNotes}
                  onChange={(e) => setOrderNotes(e.target.value)}
                  placeholder="مثال: نحتاج الاستلام صباح يوم الأحد، أرجو تجهيز الباليتات مغلفة..."
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 text-xs focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
                ></textarea>
              </div>

              {/* Price Calculation Breakdown */}
              {(() => {
                const subtotal = orderQuantity * activeOfferForOrder.unit_price;
                const vat = Math.round(subtotal * 0.15 * 100) / 100;
                const grandTotal = Math.round((subtotal + vat) * 100) / 100;

                return (
                  <div className="bg-slate-50 border border-slate-200 rounded-xl p-3.5 space-y-1.5 text-xs">
                    <div className="flex justify-between text-slate-600">
                      <span>المبلغ الأساسي ({orderQuantity} × {activeOfferForOrder.unit_price} ر.س):</span>
                      <span className="font-semibold">{subtotal.toFixed(2)} ر.س</span>
                    </div>
                    <div className="flex justify-between text-slate-600">
                      <span>الضريبة بحسب إعدادات المنصة:</span>
                      <span className="font-semibold">{vat.toFixed(2)} ر.س</span>
                    </div>
                    <div className="flex justify-between text-slate-600">
                      <span>عمولة منصة حصّال (2.5%):</span>
                      <span className="text-emerald-700 font-medium">مشمولة في الصفقة</span>
                    </div>
                    <div className="border-t border-slate-200 pt-2 flex justify-between items-baseline font-bold text-slate-900 text-sm">
                      <span>الإجمالي المتوقع:</span>
                      <span className="text-emerald-700 text-base font-black font-serif">
                        {grandTotal.toFixed(2)} ر.س
                      </span>
                    </div>
                  </div>
                );
              })()}

              {orderError && (
                <div className="p-3 rounded-lg bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center gap-2">
                  <AlertCircle className="w-4 h-4 shrink-0" />
                  <span>{orderError}</span>
                </div>
              )}

              {/* Submit Button */}
              <div className="flex items-center gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setActiveOfferForOrder(null)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl text-xs font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="button"
                  disabled={submittingOrder || orderQuantity < activeOfferForOrder.min_order_quantity || orderQuantity > activeOfferForOrder.available_quantity}
                  onClick={handleSubmitOrder}
                  className="flex-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl text-xs font-bold transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer"
                >
                  {submittingOrder ? (
                    <span className="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
                  ) : (
                    <>
                      <CheckCircle2 className="w-4 h-4" />
                      <span>تأكيد الحجز الذري وإرسال الطلب</span>
                    </>
                  )}
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

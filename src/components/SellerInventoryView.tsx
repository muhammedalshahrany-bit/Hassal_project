import React, { useState, useEffect } from 'react';
import { 
  PlusCircle, 
  Warehouse as WarehouseIcon, 
  Package, 
  Play, 
  Pause, 
  CheckCircle2, 
  AlertCircle,
  MapPin,
  Phone,
  Layers,
  Calendar
} from 'lucide-react';
import { api } from '../lib/api';
import type { Offer, Warehouse, Product, ProductVariant } from '../types';

interface SellerInventoryViewProps {
  currentPersonaId: string;
}

const normalizeOffer = (offer: Offer): Offer => ({
  ...offer,
  status: String(offer.status ?? '').toLowerCase() as Offer['status'],
  opportunity_reason: String(offer.opportunity_reason ?? '').toLowerCase() as Offer['opportunity_reason'],
  available_quantity: Number(offer.available_quantity ?? 0),
  reserved_quantity: Number(offer.reserved_quantity ?? 0),
  sold_quantity: Number(offer.sold_quantity ?? 0),
  unit_price: Number(offer.unit_price ?? 0),
  market_reference_price: offer.market_reference_price == null ? undefined : Number(offer.market_reference_price),
  min_order_quantity: Number(offer.min_order_quantity ?? 0),
  max_order_quantity: offer.max_order_quantity == null ? undefined : Number(offer.max_order_quantity),
});

export const SellerInventoryView: React.FC<SellerInventoryViewProps> = ({ currentPersonaId }) => {
  const [offers, setOffers] = useState<Offer[]>([]);
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [products, setProducts] = useState<Product[]>([]);
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);

  // New Offer Modal
  const [showNewOfferModal, setShowNewOfferModal] = useState(false);
  const [selectedProductId, setSelectedProductId] = useState<number | ''>('');
  const [variants, setVariants] = useState<ProductVariant[]>([]);
  const [selectedVariantId, setSelectedVariantId] = useState<number | ''>('');
  const [selectedWarehouseId, setSelectedWarehouseId] = useState<number | ''>('');
  const [availableQuantity, setAvailableQuantity] = useState<number>(100);
  const [unitPrice, setUnitPrice] = useState<number>(50);
  const [marketRefPrice, setMarketRefPrice] = useState<number>(75);
  const [minOrderQty, setMinOrderQty] = useState<number>(10);
  const [opportunityReason, setOpportunityReason] = useState<string>('surplus_stock');
  const [reasonNotes, setReasonNotes] = useState<string>('');
  const [expiryDate, setExpiryDate] = useState<string>('2026-12-31');
  const [offerError, setOfferError] = useState<string | null>(null);

  // New Warehouse Modal
  const [showNewWarehouseModal, setShowNewWarehouseModal] = useState(false);
  const [whName, setWhName] = useState('');
  const [whCity, setWhCity] = useState('الرياض');
  const [whDistrict, setWhDistrict] = useState('حي السلي الصناعي');
  const [whAddress, setWhAddress] = useState('طريق هارون الرشيد - مخرج 18');
  const [whLat, setWhLat] = useState(24.6438);
  const [whLon, setWhLon] = useState(46.8245);
  const [whContact, setWhContact] = useState('فهد العتيبي');
  const [whPhone, setWhPhone] = useState('+966501112233');

  const loadData = async () => {
    setLoading(true);
    try {
      const [offRes, whRes, prodRes] = await Promise.all([
        api.getOffers(),
        api.getWarehouses(),
        api.getProducts(),
      ]);
      if (offRes.success && offRes.data) setOffers(offRes.data.map(normalizeOffer));
      if (whRes.success && whRes.data) setWarehouses(whRes.data);
      if (prodRes.success && prodRes.data) setProducts(prodRes.data);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [currentPersonaId]);

  // When product is chosen in modal, load variants
  useEffect(() => {
    if (selectedProductId) {
      api.getProductVariants(Number(selectedProductId)).then((res) => {
        if (res.success && res.data) {
          setVariants(res.data);
          if (res.data.length > 0) setSelectedVariantId(res.data[0].id);
        }
      });
    } else {
      setVariants([]);
      setSelectedVariantId('');
    }
  }, [selectedProductId]);

  const handleToggleOfferStatus = async (offer: Offer) => {
    if (offer.status === 'active') {
      await api.pauseOffer(offer.id);
    } else {
      await api.publishOffer(offer.id);
    }
    loadData();
  };

  const handleCreateOffer = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedVariantId || !selectedWarehouseId) {
      setOfferError('يرجى تحديد الصنف والمستودع أولاً');
      return;
    }
    setSubmitting(true);
    setOfferError(null);

    const res = await api.createOffer({
      product_variant_id: Number(selectedVariantId),
      warehouse_id: Number(selectedWarehouseId),
      available_quantity: availableQuantity,
      unit_price: unitPrice,
      market_reference_price: marketRefPrice || null,
      min_order_quantity: minOrderQty,
      opportunity_reason: opportunityReason,
      reason_notes: reasonNotes,
      expiry_date: expiryDate,
      delivery_terms: 'both',
    });

    setSubmitting(false);
    if (res.success) {
      setShowNewOfferModal(false);
      loadData();
    } else {
      setOfferError(res.message || 'فشل إضافة العرض');
    }
  };

  const handleCreateWarehouse = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!whName) return;
    setSubmitting(true);

    const res = await api.createWarehouse({
      name: whName,
      city: whCity,
      district: whDistrict,
      address_line: whAddress,
      latitude: whLat,
      longitude: whLon,
      contact_name: whContact,
      contact_phone: whPhone,
    });

    setSubmitting(false);
    if (res.success) {
      setShowNewWarehouseModal(false);
      loadData();
    } else {
      alert(res.message || 'فشل إضافة المستودع');
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* Top Banner */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-4 shadow-2xs">
        <div>
          <h2 className="text-lg font-black text-slate-900 font-serif">إدارة عروض المخزون والمستودعات</h2>
          <p className="text-xs text-slate-500">
            أدر عروضك التجارية، الكميات المحجوزة ذرياً، وحالة المستودعات ومواقع الاستلام الجغرافية.
          </p>
        </div>

        <div className="flex items-center gap-2">
          <button
            onClick={() => setShowNewWarehouseModal(true)}
            className="bg-slate-100 hover:bg-slate-200 text-slate-800 px-3 py-2 rounded-lg text-xs font-semibold flex items-center gap-1.5 cursor-pointer"
          >
            <WarehouseIcon className="w-4 h-4 text-slate-600" />
            <span>إضافة مستودع جديد</span>
          </button>

          <button
            onClick={() => {
              setShowNewOfferModal(true);
              if (warehouses.length > 0 && !selectedWarehouseId) setSelectedWarehouseId(warehouses[0].id);
              if (products.length > 0 && !selectedProductId) setSelectedProductId(products[0].id);
            }}
            className="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-xs cursor-pointer"
          >
            <PlusCircle className="w-4 h-4" />
            <span>طرح عرض تجاري جديد</span>
          </button>
        </div>
      </div>

      {/* Warehouses Section */}
      <div className="space-y-3">
        <h3 className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
          <WarehouseIcon className="w-4 h-4 text-emerald-700" />
          <span>المستودعات ومراكز التوزيع المسجلة ({warehouses.length})</span>
        </h3>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
          {warehouses.map((w) => (
            <div key={w.id} className="bg-white border border-slate-200 rounded-xl p-3.5 space-y-2 text-xs shadow-2xs">
              <div className="flex items-center justify-between">
                <span className="font-bold text-slate-900">{w.name}</span>
                <span className="bg-slate-100 text-slate-600 px-2 py-0.5 rounded text-[10px]">{w.city}</span>
              </div>
              <div className="flex items-center gap-1 text-slate-500 text-[11px]">
                <MapPin className="w-3 h-3 text-emerald-600 shrink-0" />
                <span className="truncate">{w.address_line}</span>
              </div>
              <div className="flex items-center justify-between pt-1 border-t border-slate-100 text-[11px] text-slate-600">
                <span>المسؤول: {w.contact_name}</span>
                <span className="font-mono dir-ltr">{w.contact_phone}</span>
              </div>
            </div>
          ))}
        </div>
      </div>

      {/* Active Commercial Offers */}
      <div className="space-y-3">
        <h3 className="text-xs font-bold text-slate-700 flex items-center gap-1.5">
          <Package className="w-4 h-4 text-emerald-700" />
          <span>قائمة العروض التجارية بالمخزون ({offers.length})</span>
        </h3>

        {loading ? (
          <div className="py-12 text-center text-slate-500 text-xs">جاري جلب بيانات العروض...</div>
        ) : offers.length === 0 ? (
          <div className="bg-white border border-slate-200 rounded-xl p-8 text-center text-slate-500 text-xs">
            لا توجد عروض مضافة بعد. اضغط "طرح عرض تجاري جديد" للبدء.
          </div>
        ) : (
          <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs">
            <table className="w-full text-xs text-right">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
                <tr>
                  <th className="p-3">المنتج والصنف</th>
                  <th className="p-3">المستودع</th>
                  <th className="p-3">المتاح</th>
                  <th className="p-3">المحجوز (قيد الصفقة)</th>
                  <th className="p-3">سعر الوحدة</th>
                  <th className="p-3">نوع الفرصة</th>
                  <th className="p-3">الحالة</th>
                  <th className="p-3 text-center">إجراءات</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {offers.map((o) => (
                  <tr key={o.id} className="hover:bg-slate-50/70 transition-colors">
                    <td className="p-3">
                      <div className="font-bold text-slate-900">{o.product_name_ar}</div>
                      <div className="text-[11px] text-slate-500">{o.variant_name_ar}</div>
                    </td>
                    <td className="p-3 text-slate-600">
                      <div>{o.warehouse_name}</div>
                      <div className="text-[10px] text-slate-400">{o.warehouse_city}</div>
                    </td>
                    <td className="p-3 font-bold text-emerald-800">
                      {o.available_quantity} {o.unit_name_ar}
                    </td>
                    <td className="p-3 font-semibold text-amber-700">
                      {o.reserved_quantity} {o.unit_name_ar}
                    </td>
                    <td className="p-3 font-black font-serif text-slate-900">
                      {o.unit_price.toFixed(2)} ر.س
                    </td>
                    <td className="p-3">
                      <span className="text-[10px] font-semibold bg-slate-100 text-slate-700 px-2 py-0.5 rounded">
                        {o.opportunity_reason}
                      </span>
                    </td>
                    <td className="p-3">
                      <span
                        className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                          o.status === 'active'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-slate-200 text-slate-700'
                        }`}
                      >
                        {o.status === 'active' ? 'نشط بالسوق' : o.status}
                      </span>
                    </td>
                    <td className="p-3 text-center">
                      <button
                        onClick={() => handleToggleOfferStatus(o)}
                        className={`px-2.5 py-1 rounded-md text-[11px] font-bold inline-flex items-center gap-1 cursor-pointer ${
                          o.status === 'active'
                            ? 'bg-amber-50 text-amber-700 hover:bg-amber-100'
                            : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100'
                        }`}
                      >
                        {o.status === 'active' ? (
                          <>
                            <Pause className="w-3 h-3" />
                            <span>إيقاف مؤقت</span>
                          </>
                        ) : (
                          <>
                            <Play className="w-3 h-3" />
                            <span>نشر بالسوق</span>
                          </>
                        )}
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      {/* New Offer Modal */}
      {showNewOfferModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-xl w-full p-6 space-y-4 shadow-2xl border border-slate-200 animate-in fade-in">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">طرح عرض تجاري جديد للمخزون</h3>
              <button onClick={() => setShowNewOfferModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            {offerError && (
              <div className="p-3 bg-rose-50 border border-rose-200 text-rose-800 text-xs rounded-lg flex items-center gap-2">
                <AlertCircle className="w-4 h-4 shrink-0" />
                <span>{offerError}</span>
              </div>
            )}

            <form onSubmit={handleCreateOffer} className="space-y-4 text-xs">
              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">اختر المنتج العام:</label>
                  <select
                    value={selectedProductId}
                    onChange={(e) => setSelectedProductId(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    {products.map((p) => (
                      <option key={p.id} value={p.id}>
                        {p.name_ar}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الصنف والعبوة (Variant):</label>
                  <select
                    value={selectedVariantId}
                    onChange={(e) => setSelectedVariantId(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    {variants.map((v) => (
                      <option key={v.id} value={v.id}>
                        {v.variant_name_ar} (SKU: {v.sku})
                      </option>
                    ))}
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المستودع وموقع التخزين:</label>
                  <select
                    value={selectedWarehouseId}
                    onChange={(e) => setSelectedWarehouseId(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    {warehouses.map((w) => (
                      <option key={w.id} value={w.id}>
                        {w.name} ({w.city})
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">نوع الفرصة التجارية:</label>
                  <select
                    value={opportunityReason}
                    onChange={(e) => setOpportunityReason(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    <option value="surplus_stock">فائض مخزون تعاقدي</option>
                    <option value="slow_moving">تصريف مخزون بطيء</option>
                    <option value="overproduction">وفرة إنتاج مصنعي</option>
                    <option value="assortment_change">تغيير تشكيلة وتغليف</option>
                    <option value="seasonal_inventory">مخزون ما قبل الموسم</option>
                    <option value="commercial_liquidation">تصفية خط تجاري فوري</option>
                  </select>
                </div>
              </div>

              <div className="grid grid-cols-3 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الكمية المعروضة:</label>
                  <input
                    type="number"
                    value={availableQuantity}
                    onChange={(e) => setAvailableQuantity(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold focus:bg-white focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">سعر الوحدة بالجملة (ر.س):</label>
                  <input
                    type="number"
                    step="0.1"
                    value={unitPrice}
                    onChange={(e) => setUnitPrice(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold text-emerald-800 focus:bg-white focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">السعر المرجعي بالسوق:</label>
                  <input
                    type="number"
                    step="0.1"
                    value={marketRefPrice}
                    onChange={(e) => setMarketRefPrice(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الحد الأدنى للطلب (MOQ):</label>
                  <input
                    type="number"
                    value={minOrderQty}
                    onChange={(e) => setMinOrderQty(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">تاريخ انتهاء الصلاحية:</label>
                  <input
                    type="date"
                    value={expiryDate}
                    onChange={(e) => setExpiryDate(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="flex items-center gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setShowNewOfferModal(false)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold shadow-md flex items-center justify-center gap-2 cursor-pointer"
                >
                  {submitting ? 'جاري الحفظ...' : 'نشر العرض في سوق حصّال'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* New Warehouse Modal */}
      {showNewWarehouseModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">تسجيل مستودع أو مركز تخزين جديد</h3>
              <button onClick={() => setShowNewWarehouseModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            <form onSubmit={handleCreateWarehouse} className="space-y-3 text-xs">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">اسم المستودع:</label>
                <input
                  type="text"
                  required
                  placeholder="مثال: مستودع الأغذية المركزي - مخرج 18"
                  value={whName}
                  onChange={(e) => setWhName(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المدينة:</label>
                  <input
                    type="text"
                    value={whCity}
                    onChange={(e) => setWhCity(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الحي:</label>
                  <input
                    type="text"
                    value={whDistrict}
                    onChange={(e) => setWhDistrict(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">العنوان التفصيلي:</label>
                <input
                  type="text"
                  value={whAddress}
                  onChange={(e) => setWhAddress(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">مسؤول المستودع:</label>
                  <input
                    type="text"
                    value={whContact}
                    onChange={(e) => setWhContact(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">رقم الهاتف:</label>
                  <input
                    type="text"
                    value={whPhone}
                    onChange={(e) => setWhPhone(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="flex items-center gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setShowNewWarehouseModal(false)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold shadow-md cursor-pointer"
                >
                  حفظ المستودع
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

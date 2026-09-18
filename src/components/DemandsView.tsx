import React, { useState, useEffect } from 'react';
import { 
  Megaphone, 
  PlusCircle, 
  MapPin, 
  Calendar, 
  Building2, 
  CheckCircle2, 
  DollarSign, 
  Package, 
  ShieldCheck 
} from 'lucide-react';
import { api } from '../lib/api';
import type { Demand, Category } from '../types';

interface DemandsViewProps {
  currentPersonaId: string;
}

export const DemandsView: React.FC<DemandsViewProps> = ({ currentPersonaId }) => {
  const [demands, setDemands] = useState<Demand[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [loading, setLoading] = useState(true);
  const [showModal, setShowModal] = useState(false);
  const [submitting, setSubmitting] = useState(false);

  // Form
  const [title, setTitle] = useState('');
  const [description, setDescription] = useState('');
  const [quantity, setQuantity] = useState(100);
  const [targetPrice, setTargetPrice] = useState<number | ''>(80);
  const [targetCity, setTargetCity] = useState('الرياض');
  const [requiredDate, setRequiredDate] = useState('2026-10-15');
  const [categoryId, setCategoryId] = useState<number | ''>('');

  const loadData = async () => {
    setLoading(true);
    try {
      const [dRes, cRes] = await Promise.all([api.getDemands(), api.getCategories()]);
      if (dRes.success && dRes.data) setDemands(dRes.data);
      if (cRes.success && cRes.data) {
        setCategories(cRes.data);
        if (cRes.data.length > 0) setCategoryId(cRes.data[0].id);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [currentPersonaId]);

  const handleCreateDemand = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!title || !description) return;
    setSubmitting(true);

    const res = await api.createDemand({
      title_ar: title,
      title_en: title,
      description,
      target_quantity: Number(quantity) || 1,
      unit: 'unit',
      target_unit_price: targetPrice ? Number(targetPrice) : null,
      currency_code: 'SAR',
      delivery_city: targetCity,
      target_city: targetCity,
      target_delivery_date: requiredDate,
      required_by_date: requiredDate,
      category_id: categoryId ? Number(categoryId) : null,
    });

    setSubmitting(false);
    if (res.success) {
      setShowModal(false);
      setTitle('');
      setDescription('');
      loadData();
    } else {
      alert(res.message);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* Top Banner */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-4 shadow-2xs">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-lg font-black text-slate-900 font-serif">لوحة طلبات الاحتياج والمناقصات المباشرة (RFQ)</h2>
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">
              B2B Demand Board
            </span>
          </div>
          <p className="text-xs text-slate-500 pt-0.5">
            إذا لم تجد الكميات أو الأصناف المناسبة في السوق، انشر طلب احتياج تجاري مباشر لكبار الموردين والمصانع.
          </p>
        </div>

        <button
          onClick={() => setShowModal(true)}
          className="bg-emerald-600 hover:bg-emerald-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-xs cursor-pointer"
        >
          <PlusCircle className="w-4 h-4" />
          <span>نشر طلب احتياج جديد</span>
        </button>
      </div>

      {/* Demands List */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs">جاري تحميل طلبات الاحتياج...</div>
      ) : demands.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500 text-xs">
          لا توجد طلبات احتياج منشورة حالياً.
        </div>
      ) : (
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          {demands.map((d) => (
            <div
              key={d.id}
              className="bg-white border border-slate-200 rounded-xl p-5 space-y-3.5 shadow-2xs hover:border-emerald-300 transition-all flex flex-col justify-between"
            >
              <div className="space-y-2">
                <div className="flex items-start justify-between gap-2">
                  <div className="space-y-1">
                    <span className="text-[10px] font-bold px-2 py-0.5 rounded-md bg-slate-100 text-slate-700">
                      {d.category_name_ar || 'تموين غذائي'}
                    </span>
                    <h3 className="text-sm font-bold text-slate-900 leading-snug">{d.title}</h3>
                  </div>

                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-800 border border-emerald-200">
                    مفتوح للتوريد
                  </span>
                </div>

                <p className="text-xs text-slate-600 leading-relaxed bg-slate-50 p-3 rounded-lg border border-slate-100">
                  {d.description}
                </p>
              </div>

              <div className="space-y-2 pt-2 border-t border-slate-100 text-xs">
                <div className="grid grid-cols-2 gap-2 text-slate-600">
                  <div className="flex items-center gap-1.5">
                    <Package className="w-3.5 h-3.5 text-emerald-600" />
                    <span>الكمية: <strong>{d.quantity} وحدة</strong></span>
                  </div>

                  {d.target_unit_price && (
                    <div className="flex items-center gap-1.5">
                      <DollarSign className="w-3.5 h-3.5 text-emerald-600" />
                      <span>السعر المستهدف: <strong>{d.target_unit_price} ر.س</strong></span>
                    </div>
                  )}

                  <div className="flex items-center gap-1.5">
                    <MapPin className="w-3.5 h-3.5 text-slate-400" />
                    <span>المدينة: {d.target_city}</span>
                  </div>

                  <div className="flex items-center gap-1.5">
                    <Calendar className="w-3.5 h-3.5 text-amber-500" />
                    <span>المطلوب قبل: {d.required_by_date}</span>
                  </div>
                </div>

                <div className="pt-2 flex items-center justify-between text-[11px] bg-slate-50 p-2 rounded-lg">
                  <div className="flex items-center gap-1.5 text-slate-700">
                    <Building2 className="w-3.5 h-3.5 text-slate-500" />
                    <span className="font-semibold">{d.buyer_name_ar || 'مشتري معتمد'}</span>
                  </div>
                  <span className="text-emerald-700 font-bold">★ {d.buyer_rating || '5.0'}</span>
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      {/* New Demand Modal */}
      {showModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">نشر طلب احتياج تجاري بالجملة (RFQ)</h3>
              <button onClick={() => setShowModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            <form onSubmit={handleCreateDemand} className="space-y-3.5 text-xs">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">عنوان الطلب التجاري:</label>
                <input
                  type="text"
                  required
                  placeholder="مثال: مطلوب توريد 500 كرتون أرز بسمتي هندي لمطابخ إعاشة"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">التصنيف:</label>
                  <select
                    value={categoryId}
                    onChange={(e) => setCategoryId(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  >
                    {categories.map((c) => (
                      <option key={c.id} value={c.id}>
                        {c.name_ar}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">الكمية المطلوبة:</label>
                  <input
                    type="number"
                    value={quantity}
                    onChange={(e) => setQuantity(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="grid grid-cols-2 gap-3">
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">السعر المستهدف للوحدة (اختياري):</label>
                  <input
                    type="number"
                    step="0.5"
                    value={targetPrice}
                    onChange={(e) => setTargetPrice(e.target.value === '' ? '' : Number(e.target.value))}
                    placeholder="ر.س"
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>

                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المدينة المطلوبة للتسليم:</label>
                  <input
                    type="text"
                    value={targetCity}
                    onChange={(e) => setTargetCity(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                  />
                </div>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">تاريخ التوريد المطلوب قبل:</label>
                <input
                  type="date"
                  value={requiredDate}
                  onChange={(e) => setRequiredDate(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                />
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">المواصفات والشروط الفنية المطلوبة:</label>
                <textarea
                  rows={3}
                  required
                  placeholder="المواصفات، درجات التعبئة، صلاحية لا تقل عن 6 أشهر، شرط التسليم بمستودع الرياض..."
                  value={description}
                  onChange={(e) => setDescription(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                ></textarea>
              </div>

              <div className="flex items-center gap-3 pt-3">
                <button
                  type="button"
                  onClick={() => setShowModal(false)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold shadow-md cursor-pointer"
                >
                  {submitting ? 'جاري النشر...' : 'نشر طلب الاحتياج'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

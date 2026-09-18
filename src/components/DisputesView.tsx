import React, { useState, useEffect } from 'react';
import { 
  Scale, 
  AlertTriangle, 
  CheckCircle2, 
  Clock, 
  FileText, 
  ShieldAlert, 
  HelpCircle,
  XCircle
} from 'lucide-react';
import { api } from '../lib/api';
import type { Dispute, Deal } from '../types';

interface DisputesViewProps {
  currentPersonaId: string;
  initialDealId?: number | null;
}

export const DisputesView: React.FC<DisputesViewProps> = ({ currentPersonaId, initialDealId }) => {
  const [disputes, setDisputes] = useState<Dispute[]>([]);
  const [deals, setDeals] = useState<Deal[]>([]);
  const [loading, setLoading] = useState(true);

  // Open Dispute Modal
  const [showOpenModal, setShowOpenModal] = useState(false);
  const [selectedDealId, setSelectedDealId] = useState<number | ''>(initialDealId || '');
  const [disputeReason, setDisputeReason] = useState('damaged_packaging');
  const [claimedAmount, setClaimedAmount] = useState<number>(1000);
  const [disputeNotes, setDisputeNotes] = useState('');
  const [submitting, setSubmitting] = useState(false);

  // Arbitrate Modal (Admin only)
  const [arbitrateDisputeItem, setArbitrateDisputeItem] = useState<Dispute | null>(null);
  const [arbitrateDecision, setArbitrateDecision] = useState<'full_refund' | 'partial_refund' | 'dismiss_dispute'>('full_refund');
  const [arbitrateRefundAmount, setArbitrateRefundAmount] = useState<number>(0);
  const [arbitrateNotes, setArbitrateNotes] = useState('');

  const loadData = async () => {
    setLoading(true);
    try {
      const dRes = await api.getDeals();
      if (dRes.success && dRes.data) {
        setDeals(dRes.data);
        const disps: Dispute[] = [];
        dRes.data.forEach((d) => {
          if (d.dispute) disps.push(d.dispute);
        });
        setDisputes(disps);
      }
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
    if (initialDealId) {
      setSelectedDealId(initialDealId);
      setShowOpenModal(true);
    }
  }, [currentPersonaId, initialDealId]);

  const handleOpenDispute = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!selectedDealId) return;
    setSubmitting(true);

    const res = await api.openDispute(Number(selectedDealId), {
      reason: disputeReason,
      claimed_amount: claimedAmount,
      notes: disputeNotes,
    });

    setSubmitting(false);
    if (res.success) {
      setShowOpenModal(false);
      loadData();
    } else {
      alert(res.message);
    }
  };

  const handleArbitrate = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!arbitrateDisputeItem) return;
    setSubmitting(true);

    const res = await api.arbitrateDispute(arbitrateDisputeItem.id, {
      decision: arbitrateDecision,
      refunded_amount: arbitrateDecision === 'dismiss_dispute' ? 0 : arbitrateRefundAmount,
      resolution_notes: arbitrateNotes,
    });

    setSubmitting(false);
    if (res.success) {
      setArbitrateDisputeItem(null);
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
            <h2 className="text-lg font-black text-slate-900 font-serif">مركز فض النزاعات والتحكيم التجاري</h2>
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-rose-50 text-rose-800 border border-rose-200">
              Arbitration Center
            </span>
          </div>
          <p className="text-xs text-slate-500 pt-0.5">
            في حال وجود تلف بالبضاعة أو عدم مطابقة للمواصفات خلال مهلة الفحص (48 ساعة)، يتم تعليق تحويل الأمانات والتحكيم العادل.
          </p>
        </div>

        <button
          onClick={() => {
            setShowOpenModal(true);
            if (deals.length > 0 && !selectedDealId) setSelectedDealId(deals[0].id);
          }}
          className="bg-rose-600 hover:bg-rose-700 text-white px-3.5 py-2 rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-xs cursor-pointer"
        >
          <AlertTriangle className="w-4 h-4" />
          <span>فتح نزاع تجاري لصفقة</span>
        </button>
      </div>

      {/* Disputes List */}
      {loading ? (
        <div className="py-20 text-center text-slate-500 text-xs">جاري تحميل سجل النزاعات...</div>
      ) : disputes.length === 0 ? (
        <div className="bg-white border border-slate-200 rounded-xl p-12 text-center space-y-3">
          <CheckCircle2 className="w-12 h-12 text-emerald-500 mx-auto" />
          <h3 className="text-sm font-bold text-slate-800">لا توجد نزاعات مفتوحة حالياً</h3>
          <p className="text-xs text-slate-500">كافة الصفقات تم تسويتها واستلامها برضا الطرفين دون مطالبات معلقة.</p>
        </div>
      ) : (
        <div className="space-y-4">
          {disputes.map((disp) => {
            const deal = deals.find((d) => d.id === disp.deal_id);

            return (
              <div
                key={disp.id}
                className="bg-white border border-slate-200 rounded-xl p-5 space-y-3 shadow-2xs hover:border-slate-300 transition-all"
              >
                <div className="flex items-start justify-between">
                  <div className="space-y-1">
                    <div className="flex items-center gap-2">
                      <span className="text-xs font-bold text-slate-900">نزاع #{disp.id}</span>
                      <span className="text-xs font-mono font-bold text-emerald-800 bg-emerald-50 px-2 py-0.5 rounded">
                        صفقة: {deal?.deal_number || `ID ${disp.deal_id}`}
                      </span>
                      <span
                        className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                          disp.status === 'opened' || disp.status === 'under_review'
                            ? 'bg-amber-100 text-amber-800'
                            : disp.status === 'resolved_refund'
                            ? 'bg-emerald-100 text-emerald-800'
                            : 'bg-slate-100 text-slate-700'
                        }`}
                      >
                        {disp.status === 'opened'
                          ? 'قيد التحكيم ودراسة الأدلة'
                          : disp.status === 'resolved_refund'
                          ? 'تم التحكيم: استرداد للمشتري'
                          : disp.status}
                      </span>
                    </div>
                    <p className="text-xs text-slate-600 pt-1">
                      السبب: <strong>{disp.reason}</strong> • المبلغ المطالب به: <strong>{disp.claimed_amount} ر.س</strong>
                    </p>
                  </div>

                  {/* Arbitration button for Admin persona */}
                  {currentPersonaId === 'admin' && (disp.status === 'opened' || disp.status === 'under_review') && (
                    <button
                      onClick={() => {
                        setArbitrateDisputeItem(disp);
                        setArbitrateRefundAmount(disp.claimed_amount);
                      }}
                      className="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-xs cursor-pointer flex items-center gap-1"
                    >
                      <Scale className="w-3.5 h-3.5" />
                      <span>إصدار قرار تحكيمي ملزم</span>
                    </button>
                  )}
                </div>

                {disp.resolution_decision && (
                  <div className="bg-emerald-50 border border-emerald-200 rounded-lg p-3 text-xs space-y-1">
                    <div className="font-bold text-emerald-900">قرار لجنة التحكيم المعتمد:</div>
                    <div className="text-emerald-800">
                      القرار: {disp.resolution_decision} • المبلغ المسترد: {disp.refunded_amount} ر.س
                    </div>
                    {disp.resolution_notes && (
                      <div className="text-[11px] text-emerald-700">ملاحظات: {disp.resolution_notes}</div>
                    )}
                  </div>
                )}
              </div>
            );
          })}
        </div>
      )}

      {/* Open Dispute Modal */}
      {showOpenModal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">رفع طلب نزاع تجاري وتعليق الأمانات</h3>
              <button onClick={() => setShowOpenModal(false)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            <form onSubmit={handleOpenDispute} className="space-y-3.5 text-xs">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">اختر الصفقة المتنازع عليها:</label>
                <select
                  value={selectedDealId}
                  onChange={(e) => setSelectedDealId(Number(e.target.value))}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                >
                  {deals.map((d) => (
                    <option key={d.id} value={d.id}>
                      {d.deal_number} ({d.total_amount} ر.س) - {d.seller_name}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">سبب النزاع والمطالبة:</label>
                <select
                  value={disputeReason}
                  onChange={(e) => setDisputeReason(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                >
                  <option value="damaged_packaging">تلف في التغليف أو الكراتين أثناء النقل</option>
                  <option value="spec_mismatch">عدم مطابقة المواصفات أو الوزن المسجل</option>
                  <option value="near_expiry_mismatch">تاريخ انتهاء الصلاحية لا يطابق العرض</option>
                  <option value="quantity_deficit">نقص في عدد الوحدات المسلمة</option>
                  <option value="temperature_break">خلل في التبريد وسلسلة الإمداد</option>
                </select>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">المبلغ المطالب باسترداده (ر.س):</label>
                <input
                  type="number"
                  value={claimedAmount}
                  onChange={(e) => setClaimedAmount(Number(e.target.value))}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold focus:bg-white focus:outline-none"
                />
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">شرح وتفاصيل الأدلة والتقرير الفني:</label>
                <textarea
                  rows={3}
                  required
                  value={disputeNotes}
                  onChange={(e) => setDisputeNotes(e.target.value)}
                  placeholder="بيان رقم الباليت، صور الفحص عند استلام البضاعة في المستودع..."
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                ></textarea>
              </div>

              <div className="flex items-center gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setShowOpenModal(false)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-2 bg-rose-600 hover:bg-rose-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold shadow-md cursor-pointer"
                >
                  {submitting ? 'جاري الرفع...' : 'تأكيد فتح النزاع وتعليق الصرف'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Arbitrate Modal (Admin) */}
      {arbitrateDisputeItem && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-200">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <h3 className="text-base font-bold text-slate-900">إصدار حكم التحكيم في نزاع #{arbitrateDisputeItem.id}</h3>
              <button onClick={() => setArbitrateDisputeItem(null)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            <form onSubmit={handleArbitrate} className="space-y-3 text-xs">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">قرار التحكيم النهائي:</label>
                <select
                  value={arbitrateDecision}
                  onChange={(e: any) => setArbitrateDecision(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                >
                  <option value="full_refund">استرداد كامل المبلغ للمشتري (إرجاع البضاعة)</option>
                  <option value="partial_refund">تسوية جزئية وتعويض المشتري بجزء من المبلغ</option>
                  <option value="dismiss_dispute">رد النزاع وإطلاق مستحقات البائع بالكامل</option>
                </select>
              </div>

              {arbitrateDecision !== 'dismiss_dispute' && (
                <div className="space-y-1">
                  <label className="font-bold text-slate-700">المبلغ المقرر رده للمشتري (ر.س):</label>
                  <input
                    type="number"
                    value={arbitrateRefundAmount}
                    onChange={(e) => setArbitrateRefundAmount(Number(e.target.value))}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 font-bold focus:bg-white focus:outline-none"
                  />
                </div>
              )}

              <div className="space-y-1">
                <label className="font-bold text-slate-700">مسببات القرار والسند القانوني:</label>
                <textarea
                  rows={3}
                  value={arbitrateNotes}
                  onChange={(e) => setArbitrateNotes(e.target.value)}
                  placeholder="بناءً على تقرير المعاينة وصور الشحنة..."
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2 focus:bg-white focus:outline-none"
                ></textarea>
              </div>

              <div className="flex items-center gap-3 pt-2">
                <button
                  type="button"
                  onClick={() => setArbitrateDisputeItem(null)}
                  className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl font-semibold"
                >
                  إلغاء
                </button>
                <button
                  type="submit"
                  disabled={submitting}
                  className="flex-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 rounded-xl font-bold shadow-md cursor-pointer"
                >
                  اعتماد وتنفيذ التسوية
                </button>
              </div>
            </form>
          </div>
        </div>
      )}
    </div>
  );
};

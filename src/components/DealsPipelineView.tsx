import React, { useState, useEffect } from 'react';
import { 
  FileCheck2, 
  Clock, 
  CheckCircle2, 
  XCircle, 
  Truck, 
  DollarSign, 
  Receipt, 
  AlertTriangle, 
  ShieldCheck, 
  Building2, 
  Package, 
  Calendar,
  ExternalLink,
  UploadCloud,
  ChevronRight
} from 'lucide-react';
import { api } from '../lib/api';
import type { Deal, PurchaseRequest, DealStatus } from '../types';

interface DealsPipelineProps {
  currentPersonaId: string;
  onOpenDispute?: (dealId: number) => void;
}

const money = (value: unknown) => Number(value ?? 0).toFixed(2);

const dealStatus = (status: unknown): DealStatus => {
  const statusMap: Record<string, DealStatus> = {
    DRAFT: 'accepted',
    PENDING_SELLER_APPROVAL: 'accepted',
    ACCEPTED: 'accepted',
    PENDING_PAYMENT: 'payment_pending',
    ESCROW_FUNDED: 'paid',
    PROCESSING: 'preparing',
    READY_FOR_PICKUP: 'ready_for_pickup',
    DISPATCHED: 'shipped',
    DELIVERED: 'delivered',
    INSPECTION: 'delivered',
    COMPLETED: 'completed',
    SETTLED: 'completed',
    CANCELLED: 'cancelled',
    DISPUTED: 'disputed',
    REFUNDED: 'refunded',
  };
  return statusMap[String(status ?? '').toUpperCase()] || String(status ?? '').toLowerCase() as DealStatus;
};

const normalizeRequest = (request: PurchaseRequest): PurchaseRequest => ({
  ...request,
  quantity: Number(request.quantity ?? (request as PurchaseRequest & { requested_quantity?: number }).requested_quantity ?? 0),
  unit_price: Number(request.unit_price ?? (request as PurchaseRequest & { requested_price?: number }).requested_price ?? 0),
  total_price: Number(request.total_price ?? ((request as PurchaseRequest & { requested_quantity?: number; requested_price?: number }).requested_quantity ?? 0) * ((request as PurchaseRequest & { requested_price?: number }).requested_price ?? 0)),
  status: String(request.status ?? '').toLowerCase() as PurchaseRequest['status'],
});

const normalizeDeal = (deal: Deal): Deal => ({
  ...deal,
  currency: deal.currency || (deal as Deal & { currency_code?: string }).currency_code || 'YER',
  subtotal_amount: Number(deal.subtotal_amount ?? (deal as Deal & { base_amount?: number }).base_amount ?? 0),
  tax_amount: Number(deal.tax_amount ?? 0),
  commission_amount: Number(deal.commission_amount ?? (deal as Deal & { platform_fee_amount?: number }).platform_fee_amount ?? 0),
  shipping_fee: Number(deal.shipping_fee ?? (deal as Deal & { delivery_fee_amount?: number }).delivery_fee_amount ?? 0),
  total_amount: Number(deal.total_amount ?? (deal as Deal & { buyer_total_amount?: number }).buyer_total_amount ?? 0),
  status: dealStatus(deal.status),
  items: deal.items?.map((item) => ({
    ...item,
    quantity: Number(item.quantity ?? 0),
    unit_price: Number(item.unit_price ?? 0),
    subtotal: Number(item.subtotal ?? (item as Deal['items'][number] & { subtotal_amount?: number }).subtotal_amount ?? 0),
  })),
});

const DEAL_STATUS_STEPS: { status: DealStatus; label: string; icon: string }[] = [
  { status: 'accepted', label: 'مقبولة', icon: '📝' },
  { status: 'payment_pending', label: 'بانتظار السداد', icon: '⏳' },
  { status: 'paid', label: 'مسددة ومحجوزة بالضمان', icon: '🛡️' },
  { status: 'preparing', label: 'جاري التجهيز', icon: '📦' },
  { status: 'ready_for_pickup', label: 'جاهز للاستلام/الشحن', icon: '🚚' },
  { status: 'delivered', label: 'تم التسليم', icon: '📍' },
  { status: 'completed', label: 'مكتملة ومسوّاة', icon: '✅' },
];

export const DealsPipelineView: React.FC<DealsPipelineProps> = ({ currentPersonaId, onOpenDispute }) => {
  const [activeTab, setActiveTab] = useState<'deals' | 'requests'>('deals');
  const [deals, setDeals] = useState<Deal[]>([]);
  const [requests, setRequests] = useState<PurchaseRequest[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedDeal, setSelectedDeal] = useState<Deal | null>(null);

  // Modals
  const [paymentModalDeal, setPaymentModalDeal] = useState<Deal | null>(null);
  const [bankReceiptRef, setBankReceiptRef] = useState('');
  const [submittingAction, setSubmittingAction] = useState(false);
  const [actionError, setActionError] = useState<string | null>(null);

  const loadData = async () => {
    setLoading(true);
    try {
      const [dealsRes, reqsRes] = await Promise.all([
        api.getDeals(),
        api.getPurchaseRequests(),
      ]);
      if (dealsRes.success && dealsRes.data) setDeals(dealsRes.data.map(normalizeDeal));
      if (reqsRes.success && reqsRes.data) setRequests(reqsRes.data.map(normalizeRequest));
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, [currentPersonaId]);

  // Handle Accept Request (Seller action)
  const handleAcceptRequest = async (requestId: number) => {
    setSubmittingAction(true);
    setActionError(null);
    const res = await api.acceptPurchaseRequest(requestId);
    setSubmittingAction(false);
    if (res.success) {
      loadData();
      setActiveTab('deals');
    } else {
      setActionError(res.message || 'تعذر قبول الطلب');
    }
  };

  // Handle Reject Request (Seller action)
  const handleRejectRequest = async (requestId: number) => {
    const reason = window.prompt('يرجى كتابة سبب رفض الطلب لتحرير المخزون المحجوز:');
    if (reason === null) return;
    setSubmittingAction(true);
    const res = await api.rejectPurchaseRequest(requestId, reason || 'اعتذار عن تلبية الطلب');
    setSubmittingAction(false);
    if (res.success) {
      loadData();
    } else {
      alert(res.message);
    }
  };

  // Transition Deal Status
  const handleStatusTransition = async (dealId: number, targetStatus: string, notes?: string) => {
    setSubmittingAction(true);
    setActionError(null);
    const res = await api.transitionDeal(dealId, targetStatus, notes);
    setSubmittingAction(false);
    if (res.success) {
      loadData();
      if (selectedDeal && selectedDeal.id === dealId) {
        const updated = await api.getDeal(dealId);
        if (updated.success && updated.data) setSelectedDeal(updated.data);
      }
    } else {
      setActionError(res.message || 'تعذر ترقية حالة الصفقة');
    }
  };

  // Record Bank Transfer Payment
  const handleRecordPayment = async () => {
    if (!paymentModalDeal) return;
    setSubmittingAction(true);
    setActionError(null);

    const res = await api.recordPayment(paymentModalDeal.id, {
      amount: paymentModalDeal.total_amount,
      provider: 'bank_transfer',
      external_reference: bankReceiptRef || 'YER-TXN-' + Math.floor(100000 + Math.random() * 900000),
      receipt_document_url: undefined,
    });

    setSubmittingAction(false);
    if (res.success) {
      setPaymentModalDeal(null);
      setBankReceiptRef('');
      loadData();
    } else {
      setActionError(res.message || 'فشل تسجيل الدفعة');
    }
  };

  // Confirm Received & Quality Check
  const handleConfirmReceived = async (dealId: number) => {
    if (!window.confirm('هل قمت بفحص البضاعة ومطابقتها للمواصفات والفاتورة الضريبية وترغب في إغلاق الصفقة وتسوية الحساب للمورد؟')) return;
    setSubmittingAction(true);
    const res = await api.confirmReceived(dealId);
    setSubmittingAction(false);
    if (res.success) {
      loadData();
      if (selectedDeal && selectedDeal.id === dealId) {
        const updated = await api.getDeal(dealId);
        if (updated.success && updated.data) setSelectedDeal(updated.data);
      }
    } else {
      alert(res.message);
    }
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* Top Header & Tab Switching */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-4 shadow-2xs">
        <div>
          <h2 className="text-lg font-black text-slate-900 font-serif">مركز الصفقات وعقود التوريد</h2>
          <p className="text-xs text-slate-500">
            تتبع مسار الطلبات، الحوالات البنكية المحتجزة بحساب الضمان، ومراحل الفحص والاستلام.
          </p>
        </div>

        <div className="flex items-center gap-2 bg-slate-100 p-1 rounded-xl text-xs font-semibold">
          <button
            onClick={() => setActiveTab('deals')}
            className={`px-4 py-2 rounded-lg transition-all ${
              activeTab === 'deals'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            <span>الصفقات النشطة والمكتملة ({deals.length})</span>
          </button>

          <button
            onClick={() => setActiveTab('requests')}
            className={`px-4 py-2 rounded-lg transition-all flex items-center gap-1.5 ${
              activeTab === 'requests'
                ? 'bg-white text-emerald-800 shadow-2xs font-bold'
                : 'text-slate-600 hover:text-slate-900'
            }`}
          >
            <span>طلبات الشراء والحجز ({requests.length})</span>
            {requests.some((r) => r.status === 'pending') && (
              <span className="w-2 h-2 rounded-full bg-amber-500 animate-ping"></span>
            )}
          </button>
        </div>
      </div>

      {actionError && (
        <div className="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-center justify-between">
          <div className="flex items-center gap-2">
            <AlertTriangle className="w-4 h-4 text-rose-600 shrink-0" />
            <span>{actionError}</span>
          </div>
          <button onClick={() => setActionError(null)} className="text-rose-500 hover:text-rose-800 font-bold">
            ✕
          </button>
        </div>
      )}

      {/* Main Content Area */}
      {loading ? (
        <div className="py-20 text-center space-y-2">
          <div className="w-8 h-8 border-3 border-emerald-600 border-t-transparent rounded-full animate-spin mx-auto"></div>
          <p className="text-xs text-slate-500">جاري تحميل سجل الصفقات...</p>
        </div>
      ) : activeTab === 'requests' ? (
        /* Purchase Requests Sub-Tab */
        <div className="space-y-4">
          {requests.length === 0 ? (
            <div className="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500 text-xs">
              لا توجد طلبات حجز شراء معلقة حالياً.
            </div>
          ) : (
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
              {requests.map((req) => (
                <div
                  key={req.id}
                  className="bg-white border border-slate-200 rounded-xl p-5 space-y-4 shadow-2xs hover:border-emerald-300 transition-all"
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="flex items-center gap-2">
                        <span className="text-xs font-bold text-slate-900">طلب حجز #{req.id}</span>
                        <span
                          className={`text-[10px] font-bold px-2 py-0.5 rounded-full ${
                            req.status === 'pending'
                              ? 'bg-amber-100 text-amber-800'
                              : req.status === 'accepted'
                              ? 'bg-emerald-100 text-emerald-800'
                              : 'bg-slate-100 text-slate-600'
                          }`}
                        >
                          {req.status === 'pending' ? 'بانتظار موافقة المورد (محجوز ذرياً)' : req.status}
                        </span>
                      </div>
                      <p className="text-xs text-slate-500 pt-0.5">
                        {req.product_name_ar || 'زيت ذرة عافية نقي ممتاز'}
                      </p>
                    </div>

                    <div className="text-left">
                      <div className="text-sm font-black text-emerald-800 font-serif">
                        {money(req.total_price)} ر.س
                      </div>
                      <div className="text-[10px] text-slate-400">
                        {req.quantity} وحدة × {req.unit_price} ر.س
                      </div>
                    </div>
                  </div>

                  {/* Details summary */}
                  <div className="bg-slate-50 rounded-lg p-3 text-xs text-slate-600 space-y-1.5 border border-slate-100">
                    <div className="flex justify-between">
                      <span className="text-slate-400">المشتري:</span>
                      <span className="font-semibold text-slate-800">{req.buyer_name || 'مجموعة مطاعم الرياض'}</span>
                    </div>
                    <div className="flex justify-between">
                      <span className="text-slate-400">تفضيل الاستلام:</span>
                      <span className="font-semibold text-slate-800">
                        {req.delivery_preference === 'pickup' ? 'استلام من المستودع' : 'شحن وتوصيل'}
                      </span>
                    </div>
                    {req.notes && (
                      <div className="pt-1 text-[11px] text-slate-500 italic border-t border-slate-200/60">
                        "{req.notes}"
                      </div>
                    )}
                  </div>

                  {/* Reservation Expiry Alert */}
                  {req.status === 'pending' && (
                    <div className="flex items-center gap-2 text-amber-700 bg-amber-50/80 p-2.5 rounded-lg text-xs font-medium border border-amber-200/70">
                      <Clock className="w-4 h-4 text-amber-600 shrink-0" />
                      <span>مهلة الحجز تنتهي في: {req.reserved_until?.split(' ')[1] || 'خلال 30 دقيقة'}</span>
                    </div>
                  )}

                  {/* Action Buttons */}
                  {req.status === 'pending' && (
                    <div className="flex items-center gap-2 pt-1">
                      <button
                        onClick={() => handleAcceptRequest(req.id)}
                        disabled={submittingAction}
                        className="flex-1 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2 rounded-lg text-xs font-bold transition-all shadow-2xs flex items-center justify-center gap-1.5 cursor-pointer"
                      >
                        <CheckCircle2 className="w-3.5 h-3.5" />
                        <span>قبول الطلب وتوليد العقد</span>
                      </button>

                      <button
                        onClick={() => handleRejectRequest(req.id)}
                        disabled={submittingAction}
                        className="bg-slate-100 hover:bg-rose-50 hover:text-rose-700 text-slate-600 py-2 px-3 rounded-lg text-xs font-semibold transition-all cursor-pointer"
                      >
                        رفض وتحرير
                      </button>
                    </div>
                  )}

                  {req.deal_number && (
                    <div className="pt-1 flex items-center justify-between text-xs text-emerald-800 font-bold">
                      <span>رقم العقد التجاري: {req.deal_number}</span>
                      <button
                        onClick={() => {
                          setActiveTab('deals');
                          const d = deals.find((x) => x.id === req.deal_id);
                          if (d) setSelectedDeal(d);
                        }}
                        className="text-emerald-600 hover:underline flex items-center gap-1"
                      >
                        <span>عرض تفاصيل الصفقة</span>
                        <ChevronRight className="w-3.5 h-3.5" />
                      </button>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </div>
      ) : (
        /* Deals Pipeline Sub-Tab */
        <div className="space-y-4">
          {deals.length === 0 ? (
            <div className="bg-white border border-slate-200 rounded-xl p-12 text-center text-slate-500 text-xs">
              لا توجد صفقات منفذة حتى الآن. عند قبول طلب شراء سيتم إنشاء الصفقة فوراً هنا.
            </div>
          ) : (
            deals.map((deal) => {
              const currentStepIdx = DEAL_STATUS_STEPS.findIndex((s) => s.status === deal.status);

              return (
                <div
                  key={deal.id}
                  className="bg-white border border-slate-200 rounded-xl p-5 space-y-4 shadow-2xs hover:border-slate-300 transition-all"
                >
                  {/* Deal Header */}
                  <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                    <div>
                      <div className="flex items-center gap-2.5">
                        <span className="text-base font-black text-slate-900 font-mono tracking-wide">
                          {deal.deal_number}
                        </span>
                        <span className="text-xs px-2.5 py-0.5 rounded-md font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                          {DEAL_STATUS_STEPS.find((s) => s.status === deal.status)?.label || deal.status}
                        </span>
                        {deal.status === 'paid' && (
                          <span className="text-[11px] font-medium bg-blue-50 text-blue-700 border border-blue-200 px-2 py-0.5 rounded flex items-center gap-1">
                            <ShieldCheck className="w-3 h-3 text-blue-600" />
                            محمي بحساب الضمان Escrow
                          </span>
                        )}
                      </div>
                      <p className="text-xs text-slate-500 pt-1">
                        البائع: {deal.seller_name} • المشتري: {deal.buyer_name}
                      </p>
                    </div>

                    <div className="text-left">
                      <div className="text-lg font-black text-slate-900 font-serif">
                        {money(deal.total_amount)} ر.س
                      </div>
                      <div className="text-[11px] text-slate-400">
                        الضريبة بحسب إعدادات المنصة ({money(deal.tax_amount)} ر.ي)
                      </div>
                    </div>
                  </div>

                  {/* Visual Status Pipeline Stepper */}
                  <div className="py-2 overflow-x-auto scrollbar-none">
                    <div className="flex items-center min-w-[650px] justify-between relative">
                      {DEAL_STATUS_STEPS.map((step, idx) => {
                        const isDone = currentStepIdx >= idx;
                        const isCurrent = currentStepIdx === idx;

                        return (
                          <div key={step.status} className="flex-1 flex flex-col items-center relative z-10 text-center">
                            <div
                              className={`w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold transition-colors ${
                                isCurrent
                                  ? 'bg-emerald-600 text-white ring-4 ring-emerald-100'
                                  : isDone
                                  ? 'bg-emerald-500 text-white'
                                  : 'bg-slate-100 text-slate-400'
                              }`}
                            >
                              {step.icon}
                            </div>
                            <span
                              className={`text-[10px] mt-1.5 whitespace-nowrap font-medium ${
                                isCurrent ? 'text-emerald-800 font-bold' : isDone ? 'text-slate-700' : 'text-slate-400'
                              }`}
                            >
                              {step.label}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>

                  {/* Actions & Next Steps Footer */}
                  <div className="pt-3 border-t border-slate-100 flex flex-wrap items-center justify-between gap-3 bg-slate-50/60 p-3 rounded-lg">
                    <div className="flex items-center gap-2 text-xs">
                      <Receipt className="w-4 h-4 text-slate-500" />
                      <span className="text-slate-600">
                        المستودع: {deal.warehouse_city} — {deal.warehouse_name}
                      </span>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                      {/* Detailed Drawer Button */}
                      <button
                        onClick={async () => {
                          const detailed = await api.getDeal(deal.id);
                          if (detailed.success && detailed.data) setSelectedDeal(detailed.data);
                          else setSelectedDeal(deal);
                        }}
                        className="bg-white border border-slate-200 text-slate-700 px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-slate-50 cursor-pointer"
                      >
                        تفاصيل الفاتورة والعقد
                      </button>

                      {/* Payment Step Button */}
                      {(deal.status === 'accepted' || deal.status === 'payment_pending') && (
                        <button
                          onClick={() => setPaymentModalDeal(deal)}
                          className="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-xs cursor-pointer"
                        >
                          <DollarSign className="w-3.5 h-3.5" />
                          <span>سداد الحوالة البنكية وتفعيل Escrow</span>
                        </button>
                      )}

                      {/* Seller status updates */}
                      {deal.status === 'paid' && (
                        <button
                          onClick={() => handleStatusTransition(deal.id, 'preparing', 'بدء تجهيز الشحنة والباليتات')}
                          disabled={submittingAction}
                          className="bg-blue-600 hover:bg-blue-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer"
                        >
                          بدء التجهيز بالمستودع
                        </button>
                      )}

                      {deal.status === 'preparing' && (
                        <button
                          onClick={() => handleStatusTransition(deal.id, 'ready_for_pickup', 'تم تغليف البضاعة وجاهزة للاستلام')}
                          disabled={submittingAction}
                          className="bg-amber-600 hover:bg-amber-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer"
                        >
                          إشعار: جاهز للاستلام / الشحن
                        </button>
                      )}

                      {deal.status === 'ready_for_pickup' && (
                        <button
                          onClick={() => handleStatusTransition(deal.id, 'delivered', 'تم تسليم البضاعة لمندوب المشتري بنجاح')}
                          disabled={submittingAction}
                          className="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1.5 rounded-lg text-xs font-bold cursor-pointer"
                        >
                          تأكيد التسليم الميداني
                        </button>
                      )}

                      {/* Buyer Confirmation & Quality Verification */}
                      {deal.status === 'delivered' && (
                        <div className="flex items-center gap-2">
                          <button
                            onClick={() => handleConfirmReceived(deal.id)}
                            disabled={submittingAction}
                            className="bg-emerald-700 hover:bg-emerald-800 text-white px-3.5 py-1.5 rounded-lg text-xs font-bold shadow-xs cursor-pointer flex items-center gap-1"
                          >
                            <CheckCircle2 className="w-3.5 h-3.5" />
                            <span>تأكيد الفحص واستلام البضاعة (تسوية الأمانات)</span>
                          </button>

                          {onOpenDispute && (
                            <button
                              onClick={() => onOpenDispute(deal.id)}
                              className="bg-rose-50 border border-rose-200 text-rose-700 px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-rose-100 cursor-pointer"
                            >
                              فتح نزاع
                            </button>
                          )}
                        </div>
                      )}
                    </div>
                  </div>
                </div>
              );
            })
          )}
        </div>
      )}

      {/* Payment Modal */}
      {paymentModalDeal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 shadow-2xl border border-slate-200 animate-in fade-in zoom-in-95">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <span className="text-xs text-emerald-700 font-bold">بوابة سداد الصفقات التجارية</span>
                <h3 className="text-base font-bold text-slate-900">سداد صفقة {paymentModalDeal.deal_number}</h3>
              </div>
              <button onClick={() => setPaymentModalDeal(null)} className="text-slate-400 hover:text-slate-700 font-bold">
                ✕
              </button>
            </div>

            {/* Bank details info */}
            <div className="bg-slate-50 p-3.5 rounded-xl border border-slate-200 space-y-2 text-xs">
              <div className="font-bold text-slate-800">الحساب البنكي الوسيط المعتمد (Escrow Account):</div>
              <div className="flex justify-between text-slate-600">
                <span>اسم البنك:</span>
                <span className="font-semibold">مصرف الراجحي — حساب الضمان</span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>اسم المستفيد:</span>
                <span className="font-semibold">شركة حصّال للمنصات الرقمية</span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>الآيبان IBAN:</span>
                <span className="font-mono font-bold text-slate-900 dir-ltr">SA0380000000608010101010</span>
              </div>
              <div className="border-t border-slate-200 pt-2 flex justify-between font-bold text-slate-900 text-sm">
                <span>المبلغ المطلوب تحويله:</span>
                <span className="text-emerald-700 font-serif text-base">{money(paymentModalDeal.total_amount)} ر.س</span>
              </div>
            </div>

            <div className="space-y-1.5">
              <label className="text-xs font-bold text-slate-700 block">رقم مرجع الحوالة البنكية / إشعار التحويل:</label>
              <input
                type="text"
                placeholder="مثال: RJH-2026-998822"
                value={bankReceiptRef}
                onChange={(e) => setBankReceiptRef(e.target.value)}
                className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 text-xs focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500/20"
              />
            </div>

            <div className="p-3 bg-blue-50 border border-blue-200 rounded-lg text-blue-900 text-[11px] leading-relaxed">
              🛡️ <strong>ضمان حصّال:</strong> لن يتم تحويل المبلغ للبائع إلا بعد فحصك للبضاعة واستلامها خلال مهلة 48 ساعة من تاريخ التسليم.
            </div>

            <div className="flex items-center gap-3 pt-2">
              <button
                type="button"
                onClick={() => setPaymentModalDeal(null)}
                className="flex-1 bg-slate-100 hover:bg-slate-200 text-slate-700 py-2.5 rounded-xl text-xs font-semibold"
              >
                إلغاء
              </button>
              <button
                type="button"
                disabled={submittingAction}
                onClick={handleRecordPayment}
                className="flex-2 bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white py-2.5 rounded-xl text-xs font-bold transition-all shadow-md flex items-center justify-center gap-2 cursor-pointer"
              >
                {submittingAction ? (
                  <span className="animate-spin w-4 h-4 border-2 border-white border-t-transparent rounded-full"></span>
                ) : (
                  <span>تأكيد السداد وتفعيل الحساب الوسيط</span>
                )}
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Deal Invoice & Spec Details Drawer Modal */}
      {selectedDeal && (
        <div className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
          <div className="bg-white rounded-2xl max-w-2xl w-full p-6 space-y-5 shadow-2xl border border-slate-200 overflow-y-auto max-h-[90vh]">
            <div className="flex items-center justify-between border-b border-slate-100 pb-3">
              <div>
                <div className="flex items-center gap-2">
                  <span className="text-base font-black text-slate-900">{selectedDeal.deal_number}</span>
                  <span className="text-xs px-2 py-0.5 rounded font-bold bg-emerald-50 text-emerald-800 border border-emerald-200">
                    فاتورة ضريبية وعقد صفقة
                  </span>
                </div>
                <p className="text-xs text-slate-500 pt-0.5">تاريخ الإنشاء: {selectedDeal.accepted_at || selectedDeal.created_at}</p>
              </div>
              <button onClick={() => setSelectedDeal(null)} className="text-slate-400 hover:text-slate-700 text-lg font-bold">
                ✕
              </button>
            </div>

            {/* Organizations Involved */}
            <div className="grid grid-cols-2 gap-3 text-xs bg-slate-50 p-3.5 rounded-xl border border-slate-200">
              <div className="space-y-1">
                <span className="text-slate-400 font-medium">الطرف الأول (المورد / البائع):</span>
                <p className="font-bold text-slate-900">{selectedDeal.seller_name}</p>
                <p className="text-[11px] text-slate-500">الرقم الضريبي: {selectedDeal.seller_vat || '300123456789003'}</p>
              </div>
              <div className="space-y-1">
                <span className="text-slate-400 font-medium">الطرف الثاني (المشتري):</span>
                <p className="font-bold text-slate-900">{selectedDeal.buyer_name}</p>
                <p className="text-[11px] text-slate-500">الرقم الضريبي: {selectedDeal.buyer_vat || '300987654321003'}</p>
              </div>
            </div>

            {/* Items Snapshot */}
            {selectedDeal.items && selectedDeal.items.length > 0 && (
              <div className="space-y-2">
                <h4 className="text-xs font-bold text-slate-800">أصناف الصفقة (لقطة بيانات ثابتة):</h4>
                <div className="border border-slate-200 rounded-xl overflow-hidden">
                  <table className="w-full text-xs text-right">
                    <thead className="bg-slate-100 text-slate-600 font-semibold">
                      <tr>
                        <th className="p-2.5">المنتج والصنف</th>
                        <th className="p-2.5">كود SKU</th>
                        <th className="p-2.5">الكمية</th>
                        <th className="p-2.5">سعر الوحدة</th>
                        <th className="p-2.5">المجموع الجزئي</th>
                      </tr>
                    </thead>
                    <tbody className="divide-y divide-slate-100">
                      {selectedDeal.items.map((it) => (
                        <tr key={it.id}>
                          <td className="p-2.5 font-bold text-slate-900">{it.snapshot_product_name}</td>
                          <td className="p-2.5 font-mono text-slate-500">{it.snapshot_sku}</td>
                          <td className="p-2.5 font-semibold">{it.quantity} {it.snapshot_unit_name}</td>
                          <td className="p-2.5">{money(it.unit_price)} ر.س</td>
                          <td className="p-2.5 font-bold text-emerald-800">{money(it.subtotal)} ر.س</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              </div>
            )}

            {/* Financial Summary */}
            <div className="bg-slate-50 border border-slate-200 rounded-xl p-4 space-y-1.5 text-xs">
              <div className="flex justify-between text-slate-600">
                <span>المجموع الخاضع للضريبة (Subtotal):</span>
                <span className="font-semibold">{money(selectedDeal.subtotal_amount)} ر.س</span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>الضريبة بحسب إعدادات المنصة:</span>
                <span className="font-semibold">{money(selectedDeal.tax_amount)} ر.س</span>
              </div>
              <div className="flex justify-between text-slate-600">
                <span>عمولة المنصة الرقمية (2.5%):</span>
                <span className="font-semibold text-emerald-700">{money(selectedDeal.commission_amount)} ر.س</span>
              </div>
              <div className="border-t border-slate-200 pt-2 flex justify-between font-bold text-slate-900 text-sm">
                <span>الإجمالي النهائي المستحق:</span>
                <span className="text-emerald-700 text-base font-black font-serif">
                  {money(selectedDeal.total_amount)} ر.س
                </span>
              </div>
            </div>

            {/* Status History Timeline */}
            {selectedDeal.history && selectedDeal.history.length > 0 && (
              <div className="space-y-2">
                <h4 className="text-xs font-bold text-slate-800">سجل الأحداث والتدقيق الزمني:</h4>
                <div className="space-y-1.5 bg-slate-50 p-3 rounded-xl border border-slate-200 max-h-36 overflow-y-auto">
                  {selectedDeal.history.map((h) => (
                    <div key={h.id} className="text-[11px] flex items-center justify-between text-slate-600">
                      <span>{h.notes || `تغيير الحالة إلى ${h.to_status}`}</span>
                      <span className="text-slate-400 font-mono text-[10px]">{h.created_at}</span>
                    </div>
                  ))}
                </div>
              </div>
            )}

            <button
              onClick={() => setSelectedDeal(null)}
              className="w-full bg-slate-900 text-white py-2.5 rounded-xl text-xs font-bold hover:bg-slate-800"
            >
              إغلاق
            </button>
          </div>
        </div>
      )}
    </div>
  );
};

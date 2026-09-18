import React, { useState, useEffect } from 'react';
import { 
  ShieldCheck, 
  Wallet, 
  ArrowUpRight, 
  ArrowDownLeft, 
  Scale, 
  Clock, 
  Receipt, 
  Landmark,
  CheckCircle2
} from 'lucide-react';
import { api } from '../lib/api';
import type { LedgerAccount, LedgerEntry } from '../types';

interface LedgerEscrowViewProps {
  currentPersonaId: string;
}

export const LedgerEscrowView: React.FC<LedgerEscrowViewProps> = ({ currentPersonaId }) => {
  const [accounts, setAccounts] = useState<LedgerAccount[]>([]);
  const [entries, setEntries] = useState<LedgerEntry[]>([]);
  const [loading, setLoading] = useState(true);

  const loadData = async () => {
    setLoading(true);
    try {
      const res = await api.getLedgerStatement();
      if (res.success && res.data) {
        setAccounts(res.data.accounts || []);
        setEntries(res.data.journal_entries || []);
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

  const escrowBalance = accounts.find((a) => a.account_type === 'escrow')?.balance || 0;
  const availableBalance = accounts.find((a) => a.account_type === 'available_balance')?.balance || 0;
  const commissionRevenue = accounts.find((a) => a.account_type === 'commission_revenue')?.balance || 0;
  const taxLiability = accounts.find((a) => a.account_type === 'tax_liability')?.balance || 0;

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* Top Banner */}
      <div className="bg-gradient-to-r from-slate-900 via-slate-800 to-emerald-950 rounded-2xl p-6 sm:p-7 text-white shadow-lg space-y-4">
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
          <div className="space-y-1">
            <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-500/20 text-emerald-400 border border-emerald-500/30">
              <ShieldCheck className="w-3.5 h-3.5" />
              <span>نظام الأمانات والقيود المحاسبية المزدوجة (Double-Entry Ledger)</span>
            </div>
            <h2 className="text-xl sm:text-2xl font-black font-serif">
              المحفظة المالية وحساب الضمان Escrow
            </h2>
            <p className="text-xs text-slate-300 max-w-2xl">
              تضمن منصة حصّال حفظ أموال المشترين بحساب أمانات وسيط معتمد حتى مطابقة البضاعة، ثم تحويل المستحقات للبائع آلياً بعد انتهاء مهلة الفحص المقررة (48 ساعة).
            </p>
          </div>

          <div className="bg-white/10 backdrop-blur-md p-4 rounded-xl border border-white/10 text-left min-w-[200px]">
            <div className="text-[11px] text-slate-300">إجمالي الأمانات قيد الحماية:</div>
            <div className="text-2xl font-black text-emerald-400 font-serif pt-0.5">
              {escrowBalance.toLocaleString('en-US', { minimumFractionDigits: 2 })} <span className="text-xs font-sans text-white">ر.س</span>
            </div>
            <div className="text-[10px] text-emerald-300 flex items-center gap-1 pt-1">
              <CheckCircle2 className="w-3 h-3" />
              <span>مضمونة ومطابقة نظامياً</span>
            </div>
          </div>
        </div>
      </div>

      {/* Financial Account Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">حساب الأمانات (Escrow)</span>
            <div className="w-8 h-8 rounded-lg bg-blue-50 text-blue-700 flex items-center justify-center">
              <ShieldCheck className="w-4 h-4" />
            </div>
          </div>
          <div className="text-xl font-black text-slate-900 font-serif">
            {escrowBalance.toFixed(2)} <span className="text-xs font-sans font-bold text-slate-500">ر.س</span>
          </div>
          <p className="text-[11px] text-slate-400">مبالغ الصفقات الجاري شحنها وفحصها</p>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">الرصيد المتاح للسحب</span>
            <div className="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-700 flex items-center justify-center">
              <Wallet className="w-4 h-4" />
            </div>
          </div>
          <div className="text-xl font-black text-emerald-700 font-serif">
            {availableBalance.toFixed(2)} <span className="text-xs font-sans font-bold text-slate-500">ر.س</span>
          </div>
          <p className="text-[11px] text-slate-400">جاهز للتحويل الفوري للحساب البنكي</p>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">عمولات المنصة (2.5%)</span>
            <div className="w-8 h-8 rounded-lg bg-purple-50 text-purple-700 flex items-center justify-center">
              <Receipt className="w-4 h-4" />
            </div>
          </div>
          <div className="text-xl font-black text-purple-900 font-serif">
            {commissionRevenue.toFixed(2)} <span className="text-xs font-sans font-bold text-slate-500">ر.س</span>
          </div>
          <p className="text-[11px] text-slate-400">رسوم الوساطة والربط الرقمي</p>
        </div>

        <div className="bg-white border border-slate-200 rounded-xl p-4 shadow-2xs space-y-2">
          <div className="flex items-center justify-between">
            <span className="text-xs font-semibold text-slate-500">الضريبة بحسب إعدادات المنصة</span>
            <div className="w-8 h-8 rounded-lg bg-amber-50 text-amber-700 flex items-center justify-center">
              <Landmark className="w-4 h-4" />
            </div>
          </div>
          <div className="text-xl font-black text-amber-900 font-serif">
            {taxLiability.toFixed(2)} <span className="text-xs font-sans font-bold text-slate-500">ر.س</span>
          </div>
          <p className="text-[11px] text-slate-400">مخصصة لهيئة الزكاة والضريبة والجمارك</p>
        </div>
      </div>

      {/* Ledger Journal Entries */}
      <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs space-y-3 p-5">
        <div className="flex items-center justify-between">
          <div>
            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
              <Scale className="w-4 h-4 text-emerald-700" />
              <span>دفتر الأستاذ والقيود المحاسبية التلقائية (General Ledger Journal)</span>
            </h3>
            <p className="text-xs text-slate-500 pt-0.5">
              كل سداد أو حجز أو إفراج مالي يتم تدوينه في قيد محاسبي مزدوج غير قابل للتعديل.
            </p>
          </div>
        </div>

        {loading ? (
          <div className="py-12 text-center text-slate-500 text-xs">جاري جلب القيود المحاسبية...</div>
        ) : entries.length === 0 ? (
          <div className="p-8 text-center text-slate-500 text-xs">لا توجد قيود مسجلة بعد.</div>
        ) : (
          <div className="border border-slate-200 rounded-xl overflow-hidden">
            <table className="w-full text-xs text-right">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
                <tr>
                  <th className="p-3">رقم القيد</th>
                  <th className="p-3">التاريخ والوقت</th>
                  <th className="p-3">الصفقة المرجعية</th>
                  <th className="p-3">نوع الحركة</th>
                  <th className="p-3">البيان والشرح المحاسبي</th>
                  <th className="p-3 text-left">المبلغ (YER)</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 font-mono">
                {entries.map((entry) => (
                  <tr key={entry.id} className="hover:bg-slate-50/70 transition-colors font-sans">
                    <td className="p-3 font-mono text-slate-400">#{entry.id}</td>
                    <td className="p-3 text-slate-500 font-mono text-[11px]">{entry.created_at}</td>
                    <td className="p-3 font-bold text-slate-800">{entry.deal_number || 'حركة عامة'}</td>
                    <td className="p-3">
                      <span
                        className={`text-[10px] font-bold px-2 py-0.5 rounded ${
                          entry.entry_type === 'escrow_hold'
                            ? 'bg-blue-50 text-blue-800'
                            : entry.entry_type === 'escrow_release'
                            ? 'bg-emerald-50 text-emerald-800'
                            : entry.entry_type === 'commission_deduction'
                            ? 'bg-purple-50 text-purple-800'
                            : 'bg-slate-100 text-slate-800'
                        }`}
                      >
                        {entry.entry_type}
                      </span>
                    </td>
                    <td className="p-3 text-slate-700 font-normal">{entry.description}</td>
                    <td className="p-3 text-left font-black font-serif text-slate-900">
                      {entry.amount.toFixed(2)} ر.س
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
};

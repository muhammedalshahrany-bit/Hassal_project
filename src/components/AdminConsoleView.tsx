import React, { useState, useEffect } from 'react';
import { 
  Sliders, 
  ShieldCheck, 
  Terminal, 
  Database, 
  Activity, 
  Save, 
  Code, 
  Copy, 
  Check, 
  TrendingUp, 
  Layers, 
  FileText,
  Clock,
  Key
} from 'lucide-react';
import { api } from '../lib/api';
import type { SystemSetting, AuditLog } from '../types';

export const AdminConsoleView: React.FC = () => {
  const [activeSubTab, setActiveSubTab] = useState<'analytics' | 'settings' | 'audit' | 'api_explorer'>('analytics');
  const [analytics, setAnalytics] = useState<any>(null);
  const [settings, setSettings] = useState<SystemSetting[]>([]);
  const [auditLogs, setAuditLogs] = useState<AuditLog[]>([]);
  const [loading, setLoading] = useState(true);

  // Settings edit state
  const [vatRate, setVatRate] = useState('0.15');
  const [commissionRate, setCommissionRate] = useState('0.025');
  const [reservationTimeout, setReservationTimeout] = useState('30');
  const [inspectionWindow, setInspectionWindow] = useState('48');
  const [savingSettings, setSavingSettings] = useState(false);
  const [saveSuccess, setSaveSuccess] = useState(false);

  // API Explorer Live Execution State
  const [selectedEndpoint, setSelectedEndpoint] = useState<string>('bootstrap');
  const [apiResponse, setApiResponse] = useState<any>(null);
  const [apiLoading, setApiLoading] = useState(false);
  const [copiedCurl, setCopiedCurl] = useState(false);

  const loadData = async () => {
    setLoading(true);
    try {
      const [anRes, setRes, audRes] = await Promise.all([
        api.getAnalyticsOverview(),
        api.getSettings(),
        api.getAuditLogs(),
      ]);

      if (anRes.success && anRes.data) setAnalytics(anRes.data);
      if (setRes.success && setRes.data) {
        setSettings(setRes.data);
        const vat = setRes.data.find((s) => s.key === 'vat_rate')?.value;
        const comm = setRes.data.find((s) => s.key === 'platform_commission_rate')?.value;
        const rto = setRes.data.find((s) => s.key === 'reservation_timeout_minutes')?.value;
        const ins = setRes.data.find((s) => s.key === 'inspection_window_hours')?.value;
        if (vat) setVatRate(vat);
        if (comm) setCommissionRate(comm);
        if (rto) setReservationTimeout(rto);
        if (ins) setInspectionWindow(ins);
      }
      if (audRes.success && audRes.data) setAuditLogs(audRes.data);
    } catch (err) {
      console.error(err);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadData();
  }, []);

  const handleSaveSettings = async (e: React.FormEvent) => {
    e.preventDefault();
    setSavingSettings(true);
    setSaveSuccess(false);

    const res = await api.updateSettings([
      { key: 'vat_rate', value: vatRate },
      { key: 'platform_commission_rate', value: commissionRate },
      { key: 'reservation_timeout_minutes', value: reservationTimeout },
      { key: 'inspection_window_hours', value: inspectionWindow },
    ]);

    setSavingSettings(false);
    if (res.success) {
      setSaveSuccess(true);
      setTimeout(() => setSaveSuccess(false), 3000);
      loadData();
    } else {
      alert(res.message);
    }
  };

  // Live API Explorer Runner
  const executeApiEndpoint = async (endpoint: string) => {
    setApiLoading(true);
    setApiResponse(null);
    let result: any = null;

    if (endpoint === 'bootstrap') {
      result = await api.getBootstrap();
    } else if (endpoint === 'health') {
      result = await api.getHealth();
    } else if (endpoint === 'nearby_offers') {
      result = await api.getNearbyOffers(24.7136, 46.6753, 100);
    } else if (endpoint === 'deals') {
      result = await api.getDeals();
    } else if (endpoint === 'ledger_statement') {
      result = await api.getLedgerStatement();
    } else if (endpoint === 'demands') {
      result = await api.getDemands();
    }

    setApiResponse(result);
    setApiLoading(false);
  };

  const getCurlSnippet = (endpoint: string) => {
    const token = api.getToken() || 'YOUR_BEARER_TOKEN';
    const orgId = api.getTenantId() || 1;

    if (endpoint === 'bootstrap') {
      return `curl -s http://localhost:3000/api/v1/bootstrap`;
    }
    if (endpoint === 'health') {
      return `curl -s http://localhost:3000/api/v1/health`;
    }
    if (endpoint === 'nearby_offers') {
      return `curl -s "http://localhost:3000/api/v1/offers/nearby?latitude=24.7136&longitude=46.6753&radius_km=100"`;
    }
    if (endpoint === 'deals') {
      return `curl -s http://localhost:3000/api/v1/deals \\\n  -H "Authorization: Bearer ${token}" \\\n  -H "X-Organization-Id: ${orgId}"`;
    }
    if (endpoint === 'ledger_statement') {
      return `curl -s http://localhost:3000/api/v1/ledger/statement \\\n  -H "Authorization: Bearer ${token}" \\\n  -H "X-Organization-Id: ${orgId}"`;
    }
    return `curl -s http://localhost:3000/api/v1/demands`;
  };

  return (
    <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">
      {/* Top Banner */}
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 bg-white border border-slate-200 rounded-xl p-4 shadow-2xs">
        <div>
          <div className="flex items-center gap-2">
            <h2 className="text-lg font-black text-slate-900 font-serif">لوحة تحكم النظام والـ API Explorer</h2>
            <span className="text-[11px] font-bold px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-800 border border-indigo-200">
              Admin & Developer Console
            </span>
          </div>
          <p className="text-xs text-slate-500 pt-0.5">
            مؤشرات الأداء المالي، إعدادات المنصة المركزية، سجل التدقيق الأمني، وتجربة مباشرة للـ REST Endpoints.
          </p>
        </div>

        {/* Sub-tabs */}
        <div className="flex items-center gap-1.5 bg-slate-100 p-1 rounded-xl text-xs font-semibold">
          <button
            onClick={() => setActiveSubTab('analytics')}
            className={`px-3 py-1.5 rounded-lg transition-all ${
              activeSubTab === 'analytics' ? 'bg-white text-indigo-900 shadow-2xs font-bold' : 'text-slate-600'
            }`}
          >
            المؤشرات المالية
          </button>
          <button
            onClick={() => setActiveSubTab('settings')}
            className={`px-3 py-1.5 rounded-lg transition-all ${
              activeSubTab === 'settings' ? 'bg-white text-indigo-900 shadow-2xs font-bold' : 'text-slate-600'
            }`}
          >
            إعدادات الضريبة والعمولة
          </button>
          <button
            onClick={() => setActiveSubTab('audit')}
            className={`px-3 py-1.5 rounded-lg transition-all ${
              activeSubTab === 'audit' ? 'bg-white text-indigo-900 shadow-2xs font-bold' : 'text-slate-600'
            }`}
          >
            سجل التدقيق ({auditLogs.length})
          </button>
          <button
            onClick={() => {
              setActiveSubTab('api_explorer');
              if (!apiResponse) executeApiEndpoint(selectedEndpoint);
            }}
            className={`px-3 py-1.5 rounded-lg transition-all flex items-center gap-1 ${
              activeSubTab === 'api_explorer' ? 'bg-white text-emerald-800 shadow-2xs font-bold' : 'text-slate-600'
            }`}
          >
            <Code className="w-3.5 h-3.5" />
            <span>API Explorer</span>
          </button>
        </div>
      </div>

      {/* Analytics View */}
      {activeSubTab === 'analytics' && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-2xs space-y-2">
              <span className="text-xs font-semibold text-slate-500">حجم التداولات الإجمالي (GMV)</span>
              <div className="text-2xl font-black text-slate-900 font-serif">
                {analytics?.deals?.gmv ? analytics.deals.gmv.toFixed(2) : '24,560.00'}{' '}
                <span className="text-xs font-sans font-bold text-slate-500">ر.س</span>
              </div>
              <p className="text-[11px] text-emerald-700 flex items-center gap-1 font-medium">
                <TrendingUp className="w-3.5 h-3.5" />
                <span>شامل ضريبة الـ VAT ورسوم المنصة</span>
              </p>
            </div>

            <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-2xs space-y-2">
              <span className="text-xs font-semibold text-slate-500">إجمالي الصفقات المنفذة</span>
              <div className="text-2xl font-black text-emerald-800 font-serif">
                {analytics?.deals?.total_count || '12'} صفقة
              </div>
              <p className="text-[11px] text-slate-400">عقود توريد نشطة ومكتملة</p>
            </div>

            <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-2xs space-y-2">
              <span className="text-xs font-semibold text-slate-500">العروض والمخزون الفعّال</span>
              <div className="text-2xl font-black text-blue-900 font-serif">
                {analytics?.offers?.active_offers || '8'} عروض
              </div>
              <p className="text-[11px] text-slate-400">
                {analytics?.offers?.total_available_qty || '1,420'} وحدة بالمستودعات
              </p>
            </div>

            <div className="bg-white border border-slate-200 rounded-xl p-5 shadow-2xs space-y-2">
              <span className="text-xs font-semibold text-slate-500">المؤسسات الموثقة تجارياً</span>
              <div className="text-2xl font-black text-purple-900 font-serif">
                {analytics?.organizations?.verified || '4'} شركات
              </div>
              <p className="text-[11px] text-slate-400">KYB وسجل تجاري معتمد</p>
            </div>
          </div>
        </div>
      )}

      {/* Settings View */}
      {activeSubTab === 'settings' && (
        <div className="bg-white border border-slate-200 rounded-xl p-6 shadow-2xs max-w-2xl space-y-4">
          <div className="border-b border-slate-100 pb-3">
            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
              <Sliders className="w-4 h-4 text-emerald-700" />
              <span>إعدادات النظام والرسوم المركزية</span>
            </h3>
            <p className="text-xs text-slate-500 pt-0.5">
              تتحكم هذه القيم مباشرة في احتساب فواتير الصفقات، فترات حجز المخزون الذري، ونسب الضريبة.
            </p>
          </div>

          {saveSuccess && (
            <div className="p-3 bg-emerald-50 border border-emerald-200 text-emerald-900 text-xs rounded-lg flex items-center gap-2">
              <Check className="w-4 h-4 text-emerald-600" />
              <span>تم تحديث إعدادات النظام بنجاح وحفظها في قاعدة البيانات.</span>
            </div>
          )}

          <form onSubmit={handleSaveSettings} className="space-y-4 text-xs">
            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">نسبة ضريبة القيمة المضافة (VAT):</label>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    value={vatRate}
                    onChange={(e) => setVatRate(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 font-bold focus:bg-white focus:outline-none"
                  />
                  <span className="text-slate-500 font-bold whitespace-nowrap">
                    ({(parseFloat(vatRate) * 100).toFixed(0)}%)
                  </span>
                </div>
                <p className="text-[11px] text-slate-400">النسبة تحددها الإدارة بعد التحقق القانوني</p>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">نسبة عمولة منصة حصّال:</label>
                <div className="flex items-center gap-2">
                  <input
                    type="text"
                    value={commissionRate}
                    onChange={(e) => setCommissionRate(e.target.value)}
                    className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 font-bold focus:bg-white focus:outline-none"
                  />
                  <span className="text-slate-500 font-bold whitespace-nowrap">
                    ({(parseFloat(commissionRate) * 100).toFixed(1)}%)
                  </span>
                </div>
                <p className="text-[11px] text-slate-400">تخصم من قيمة الصفقة الإجمالية</p>
              </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div className="space-y-1">
                <label className="font-bold text-slate-700">مهلة الحجز الذري للمخزون (بالدقائق):</label>
                <input
                  type="number"
                  value={reservationTimeout}
                  onChange={(e) => setReservationTimeout(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 font-bold focus:bg-white focus:outline-none"
                />
                <p className="text-[11px] text-slate-400">يتحرر المخزون تلقائياً إذا لم يقبل المورد</p>
              </div>

              <div className="space-y-1">
                <label className="font-bold text-slate-700">مهلة فحص البضاعة (بالساعات):</label>
                <input
                  type="number"
                  value={inspectionWindow}
                  onChange={(e) => setInspectionWindow(e.target.value)}
                  className="w-full bg-slate-50 border border-slate-200 rounded-lg p-2.5 font-bold focus:bg-white focus:outline-none"
                />
                <p className="text-[11px] text-slate-400">يحق للمشتري فتح نزاع خلال هذه الفترة</p>
              </div>
            </div>

            <div className="pt-2">
              <button
                type="submit"
                disabled={savingSettings}
                className="bg-emerald-600 hover:bg-emerald-700 disabled:opacity-50 text-white px-5 py-2.5 rounded-xl font-bold shadow-xs flex items-center gap-2 cursor-pointer"
              >
                <Save className="w-4 h-4" />
                <span>{savingSettings ? 'جاري الحفظ...' : 'حفظ التعديلات'}</span>
              </button>
            </div>
          </form>
        </div>
      )}

      {/* Audit Logs View */}
      {activeSubTab === 'audit' && (
        <div className="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-2xs space-y-3 p-5">
          <div>
            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
              <ShieldCheck className="w-4 h-4 text-emerald-700" />
              <span>سجل التدقيق الأمني غير القابل للتعديل (Immutable Audit Trail)</span>
            </h3>
            <p className="text-xs text-slate-500 pt-0.5">
              يسجل النظام كل عملية تعديل أو حجز أو ترقية حالة مع عنوان الـ IP وهوية المنفذ.
            </p>
          </div>

          <div className="border border-slate-200 rounded-xl overflow-hidden">
            <table className="w-full text-xs text-right">
              <thead className="bg-slate-50 border-b border-slate-200 text-slate-600 font-semibold">
                <tr>
                  <th className="p-3">#</th>
                  <th className="p-3">الوقت</th>
                  <th className="p-3">الحدث (Action)</th>
                  <th className="p-3">الكيان المتأثر</th>
                  <th className="p-3">المستخدم والمنشأة</th>
                  <th className="p-3 text-left">التفاصيل</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 font-mono">
                {auditLogs.map((log) => (
                  <tr key={log.id} className="hover:bg-slate-50/70 transition-colors font-sans">
                    <td className="p-3 font-mono text-slate-400">#{log.id}</td>
                    <td className="p-3 text-slate-500 font-mono text-[11px]">{log.created_at}</td>
                    <td className="p-3">
                      <span className="text-[10px] font-bold bg-slate-100 text-slate-800 px-2 py-0.5 rounded font-mono">
                        {log.action}
                      </span>
                    </td>
                    <td className="p-3 text-slate-700">
                      {log.auditable_type ? `${log.auditable_type} #${log.auditable_id}` : '—'}
                    </td>
                    <td className="p-3 text-slate-600 text-[11px]">
                      {log.user?.name || log.organization?.name_ar || 'النظام'}
                    </td>
                    <td className="p-3 text-left">
                      <button
                        onClick={() => alert(JSON.stringify(log.new_values, null, 2))}
                        className="text-emerald-700 hover:underline font-mono text-[11px]"
                      >
                        عرض Payload
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {/* API Explorer & Curl Generator */}
      {activeSubTab === 'api_explorer' && (
        <div className="bg-white border border-slate-200 rounded-xl p-6 shadow-2xs space-y-5">
          <div>
            <h3 className="text-sm font-bold text-slate-900 flex items-center gap-2">
              <Terminal className="w-4 h-4 text-emerald-700" />
              <span>مستكشف واجهات برمجة التطبيقات (API Explorer & Contract Tester)</span>
            </h3>
            <p className="text-xs text-slate-500 pt-0.5">
              اختبر الـ REST Endpoints بشكل فوري واحصل على أوامر cURL جاهزة للاستخدام في تطبيقات الجوال والأنظمة الخارجية.
            </p>
          </div>

          <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
            {/* Endpoint list */}
            <div className="space-y-2">
              <label className="text-xs font-bold text-slate-700 block">اختر الـ Endpoint المطلوب اختباره:</label>
              <div className="space-y-1.5">
                {[
                  { id: 'bootstrap', label: 'GET /api/v1/bootstrap', desc: 'إعدادات النظام والتهيئة الأولية' },
                  { id: 'health', label: 'GET /api/v1/health', desc: 'فحص جاهزية الخادم وقاعدة البيانات' },
                  { id: 'nearby_offers', label: 'GET /api/v1/offers/nearby', desc: 'استعلام العروض بالأقرب جغرافياً' },
                  { id: 'deals', label: 'GET /api/v1/deals', desc: 'قائمة الصفقات وعقود التوريد' },
                  { id: 'ledger_statement', label: 'GET /api/v1/ledger/statement', desc: 'كشف الأستاذ والقيود المحاسبية' },
                  { id: 'demands', label: 'GET /api/v1/demands', desc: 'لوحة طلبات الاحتياج (RFQ)' },
                ].map((ep) => (
                  <button
                    key={ep.id}
                    onClick={() => {
                      setSelectedEndpoint(ep.id);
                      executeApiEndpoint(ep.id);
                    }}
                    className={`w-full text-right p-3 rounded-xl border text-xs transition-all cursor-pointer ${
                      selectedEndpoint === ep.id
                        ? 'bg-emerald-50 border-emerald-500 text-emerald-950 font-bold'
                        : 'bg-white border-slate-200 text-slate-700 hover:bg-slate-50'
                    }`}
                  >
                    <div className="font-mono text-emerald-800">{ep.label}</div>
                    <div className="text-[11px] text-slate-500 font-normal pt-0.5">{ep.desc}</div>
                  </button>
                ))}
              </div>
            </div>

            {/* Live Response & Curl Viewer */}
            <div className="lg:col-span-2 space-y-3">
              {/* cURL Box */}
              <div className="bg-slate-900 text-slate-200 rounded-xl p-4 space-y-2">
                <div className="flex items-center justify-between text-xs text-slate-400">
                  <span className="font-mono flex items-center gap-1.5">
                    <Terminal className="w-3.5 h-3.5 text-emerald-400" />
                    <span>أمر cURL الجاهز للتشغيل في الطرفية (Terminal):</span>
                  </span>

                  <button
                    onClick={() => {
                      navigator.clipboard.writeText(getCurlSnippet(selectedEndpoint));
                      setCopiedCurl(true);
                      setTimeout(() => setCopiedCurl(false), 2000);
                    }}
                    className="flex items-center gap-1 text-[11px] text-emerald-400 hover:text-white bg-white/10 px-2 py-0.5 rounded cursor-pointer"
                  >
                    {copiedCurl ? <Check className="w-3 h-3" /> : <Copy className="w-3 h-3" />}
                    <span>{copiedCurl ? 'تم النسخ' : 'نسخ الأمر'}</span>
                  </button>
                </div>

                <pre className="font-mono text-xs text-emerald-300 p-2 bg-slate-950 rounded-lg overflow-x-auto whitespace-pre-wrap dir-ltr">
                  {getCurlSnippet(selectedEndpoint)}
                </pre>
              </div>

              {/* Live JSON Output */}
              <div className="space-y-1.5">
                <div className="flex items-center justify-between text-xs">
                  <span className="font-bold text-slate-700">استجابة الخادم الحية (Live Response):</span>
                  {apiLoading && <span className="text-emerald-600 font-medium">جاري تنفيذ الطلب...</span>}
                </div>

                <div className="bg-slate-950 text-slate-100 p-4 rounded-xl font-mono text-xs max-h-80 overflow-y-auto dir-ltr">
                  {apiLoading ? (
                    <div className="text-slate-500">Executing HTTP Request...</div>
                  ) : apiResponse ? (
                    <pre className="text-emerald-400">{JSON.stringify(apiResponse, null, 2)}</pre>
                  ) : (
                    <div className="text-slate-500">Click an endpoint to execute.</div>
                  )}
                </div>
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

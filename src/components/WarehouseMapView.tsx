import React, { useEffect, useMemo, useState } from 'react';
import { MapPin, RefreshCw } from 'lucide-react';
import { api } from '../lib/api';
import type { Warehouse } from '../types';
import type { Locale } from '../lib/i18n';

export const WarehouseMapView: React.FC<{ locale?: Locale }> = ({ locale = 'ar' }) => {
  const [warehouses, setWarehouses] = useState<Warehouse[]>([]);
  const [loading, setLoading] = useState(true);

  const loadWarehouses = async () => {
    setLoading(true);
    const response = await api.getPublicWarehouses();
    if (response.success && response.data) setWarehouses(response.data);
    setLoading(false);
  };

  useEffect(() => { loadWarehouses(); }, []);

  const center = useMemo(() => {
    if (!warehouses.length) return { latitude: 20.5, longitude: 44.5 };
    return {
      latitude: warehouses.reduce((sum, warehouse) => sum + Number(warehouse.latitude), 0) / warehouses.length,
      longitude: warehouses.reduce((sum, warehouse) => sum + Number(warehouse.longitude), 0) / warehouses.length,
    };
  }, [warehouses]);

  const mapUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${center.longitude - 8}%2C${center.latitude - 5}%2C${center.longitude + 8}%2C${center.latitude + 5}&layer=mapnik&marker=${center.latitude}%2C${center.longitude}`;

  return (
    <section className="mx-auto max-w-7xl space-y-5 px-4 py-6 sm:px-6 lg:px-8" dir={locale === 'ar' ? 'rtl' : 'ltr'}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <h2 className="text-xl font-black text-slate-900">{locale === 'ar' ? 'خريطة المستودعات واللوجستيات' : 'Warehouse logistics map'}</h2>
          <p className="mt-1 text-sm text-slate-500">{locale === 'ar' ? 'مواقع المستودعات التجريبية ومراكز التوزيع المسجلة في المنصة.' : 'Demo warehouses and distribution centers registered on the platform.'}</p>
        </div>
        <button type="button" onClick={loadWarehouses} className="flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50">
          <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} /> تحديث المواقع
        </button>
      </div>

      <div className="grid gap-5 lg:grid-cols-[1fr_320px]">
        <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
          <iframe title="خريطة المستودعات" src={mapUrl} className="h-[520px] w-full border-0" loading="lazy" />
          <div className="border-t border-slate-100 px-4 py-2 text-left text-[11px] text-slate-400">© OpenStreetMap contributors</div>
        </div>

        <div className="space-y-3">
          <div className="rounded-2xl border border-slate-200 bg-white p-4">
            <div className="mb-3 flex items-center justify-between">
              <h3 className="font-black text-slate-900">{locale === 'ar' ? 'المواقع' : 'Locations'} ({warehouses.length})</h3>
              <MapPin className="h-5 w-5 text-emerald-700" />
            </div>
            {loading ? <p className="text-sm text-slate-500">جارٍ تحميل المواقع...</p> : warehouses.length === 0 ? <p className="text-sm text-slate-500">لا توجد مستودعات مسجلة.</p> : warehouses.map((warehouse) => (
              <div key={warehouse.id} className="border-t border-slate-100 py-3 first:border-t-0">
                <div className="font-bold text-slate-800">{warehouse.name}</div>
                <div className="text-xs text-slate-500">{warehouse.city}، {warehouse.district || warehouse.address_line}</div>
                <div className="mt-1 font-mono text-[10px] text-emerald-700">{Number(warehouse.latitude).toFixed(4)}, {Number(warehouse.longitude).toFixed(4)}</div>
              </div>
            ))}
          </div>
        </div>
      </div>
    </section>
  );
};
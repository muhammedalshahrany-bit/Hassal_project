import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight, MapPin, Package, Sparkles } from 'lucide-react';
import { api } from '../lib/api';
import type { Offer } from '../types';
import { formatMoney } from '../lib/config';
import { localizedField, type Locale } from '../lib/i18n';

interface PromotedStripProps {
  locale: Locale;
  /**
   * Opens the buyer's ask dialog for the promoted listing — the same modal a
   * market card opens, because a promotion that led to a different action than
   * a normal listing would be two products on one screen.
   */
  onOrder: (offer: Offer) => void;
}

const COPY = {
  ar: {
    eyebrow: 'اختيار المنصة',
    title: 'عروض روّجت لها المنصة',
    unit: 'سعر الوحدة كما نشره البائع',
    moq: 'أقل كمية للطلب',
    available: 'المتاح الآن',
    ask: 'طلب شراء من المتاح',
    previous: 'العرض المروّج السابق',
    next: 'العرض المروّج التالي',
    pause: 'إيقاف التنقل المؤقت',
    resume: 'استئناف التنقل',
    jump: 'انتقل إلى هذا العرض',
    noImage: 'لا صورة مرفقة بهذا الصنف',
    hiddenSeller: 'بائع لا يُظهر اسمه',
    unknownSeller: 'لم يُسجَّل',
    decay: 'تزول هذه الواجهة تلقائياً: القائمة تأتي من الخادم، وعرضٌ يبيع كميته أو ينتهي تاريخه يغادر الشريط من نفسه.',
  },
  en: {
    eyebrow: 'Chosen by the platform',
    title: 'Listings the platform is promoting',
    unit: 'Unit price as published by the seller',
    moq: 'Minimum order',
    available: 'Available now',
    ask: 'Ask to buy from the available stock',
    previous: 'Previous promoted listing',
    next: 'Next promoted listing',
    pause: 'Pause rotation',
    resume: 'Resume rotation',
    jump: 'Go to this listing',
    noImage: 'No photograph attached to this item',
    hiddenSeller: 'Seller who withholds their name',
    unknownSeller: 'Not recorded',
    decay: 'This strip needs no upkeep: the list comes from the server, and a listing that sells out or passes its date leaves it by itself.',
  },
} as const;

/** How long a promoted card holds the screen before the strip moves on. */
const DWELL_MS = 6500;

function slideName(offer: Offer, locale: Locale): string {
  return localizedField(offer, 'product_name', locale) || localizedField(offer, 'title', locale);
}

/** The same reading rule the market card uses: the seller's choice, not the platform's. */
function slideSeller(offer: Offer, locale: Locale): string {
  const name = (locale === 'en' ? offer.seller_name_en || offer.seller_name_ar : offer.seller_name_ar || offer.seller_name_en)?.trim();
  if (name) return name;
  return offer.show_seller_identity === false
    ? COPY[locale].hiddenSeller
    : COPY[locale].unknownSeller;
}

/**
 * The promoted strip: the platform's own hand on what a buyer sees first.
 *
 * It reads `GET /marketplace/offers/featured`, which returns only listings that
 * clear the public visibility chain, newest pin first, up to the ceiling held in
 * `marketplace.featured_limit`. Three consequences follow from that and shape
 * this component:
 *
 *   * There is no "hide promotions" state and no local list to keep in step. The
 *     server decides membership; a sold-out or expired listing is simply not in
 *     the response the next time it is read.
 *   * Rotation is presentation only. Re-ordering cards on this screen would
 *     reorder a promotion, which is a platform decision recorded with who made
 *     it and when — so the strip walks the server's order and never edits it.
 *   * The staff name behind a pin is not shown: the endpoint sends `featured_at`
 *     to everyone and `featured_by` only to a holder of `offers.feature`.
 *
 * Motion is a crossfade between stacked slides rather than a translating track,
 * which keeps the component honest in RTL — a track built from `translateX`
 * needs to know which end of the container is the beginning, and the document's
 * `dir` does that for it.
 */
const PromotedStrip: React.FC<PromotedStripProps> = ({ locale, onOrder }) => {
  const copy = COPY[locale];
  const [offers, setOffers] = useState<Offer[]>([]);
  const [index, setIndex] = useState(0);
  const [paused, setPaused] = useState(false);
  /**
   * `false` until the first read answers. A strip that renders an empty panel
   * while loading would flash a gap above the market on every visit, and a
   * platform with no promotion today is a normal state, not a failure to show.
   */
  const [settled, setSettled] = useState(false);
  const timer = useRef<number | null>(null);

  useEffect(() => {
    let alive = true;
    void (async () => {
      const res = await api.getFeaturedOffers().catch(() => null);
      if (!alive) return;
      setOffers(res?.success && res.data?.offers ? res.data.offers : []);
      setSettled(true);
    })();
    return () => { alive = false; };
  }, []);

  // A visitor who cannot see motion should not have it taken from them by a
  // timer, and neither should a screen reader's user whose focus is inside the
  // panel while it swaps the text out from under them.
  const reduceMotion = useMemo(
    () => typeof window !== 'undefined'
      && typeof window.matchMedia === 'function'
      && window.matchMedia('(prefers-reduced-motion: reduce)').matches,
    []
  );

  const step = useCallback((delta: number) => {
    setIndex((current) => {
      if (offers.length < 2) return 0;
      return (current + delta + offers.length) % offers.length;
    });
  }, [offers.length]);

  useEffect(() => {
    if (timer.current !== null) window.clearTimeout(timer.current);
    if (paused || reduceMotion || offers.length < 2) return;
    timer.current = window.setTimeout(() => setIndex((i) => (i + 1) % offers.length), DWELL_MS);
    return () => { if (timer.current !== null) window.clearTimeout(timer.current); };
  }, [index, paused, reduceMotion, offers.length]);

  if (!settled || offers.length === 0) return null;

  const active = offers[Math.min(index, offers.length - 1)];
  const cover = active.product_cover_image || active.product_image || active.variant_image;
  const where = [active.warehouse_city, active.warehouse_name].filter(Boolean).join(' — ');

  return (
    <section
      aria-label={copy.title}
      className="rounded-2xl overflow-hidden border border-slate-800 bg-slate-950 shadow-lg relative"
      onMouseEnter={() => setPaused(true)}
      onMouseLeave={() => setPaused(false)}
      onFocusCapture={() => setPaused(true)}
      onBlurCapture={() => setPaused(false)}
    >
      <div className="flex items-center justify-between gap-2 px-4 pt-3.5">
        <div className="flex items-center gap-2 min-w-0">
          <Sparkles className="w-4 h-4 shrink-0 text-amber-300" aria-hidden="true" />
          <span className="text-[11px] font-bold uppercase tracking-wide text-amber-200 truncate">{copy.eyebrow}</span>
          <span className="hidden sm:inline text-[11px] text-slate-400 truncate">— {copy.title}</span>
        </div>

        {offers.length > 1 && (
          <div className="flex items-center gap-1.5 shrink-0">
            <button
              type="button"
              onClick={() => step(-1)}
              aria-label={copy.previous}
              className="w-8 h-8 rounded-full grid place-items-center bg-white/10 hover:bg-white/20 text-white transition-colors cursor-pointer"
            >
              <ChevronLeft className="w-4 h-4" />
            </button>
            <button
              type="button"
              onClick={() => step(1)}
              aria-label={copy.next}
              className="w-8 h-8 rounded-full grid place-items-center bg-white/10 hover:bg-white/20 text-white transition-colors cursor-pointer"
            >
              <ChevronRight className="w-4 h-4" />
            </button>
            <button
              type="button"
              onClick={() => setPaused((p) => !p)}
              aria-label={paused ? copy.resume : copy.pause}
              aria-pressed={paused}
              className="hidden sm:inline-flex items-center gap-1 px-2 py-1 rounded-full text-[10px] font-bold bg-white/5 hover:bg-white/15 text-slate-200 transition-colors cursor-pointer"
            >
              {paused ? '▶' : '❚❚'}
            </button>
          </div>
        )}
      </div>

      {/* Stacked slides: every promoted listing is painted in the same box and
          the active one is raised. Only the active slide is reachable by keyboard. */}
      <div className="relative h-[260px] sm:h-[300px]">
        {offers.map((offer, position) => {
          const isActive = position === Math.min(index, offers.length - 1);
          const image = offer.product_cover_image || offer.product_image || offer.variant_image;
          return (
            <article
              key={offer.id}
              aria-hidden={!isActive}
              className={`absolute inset-0 flex transition-all duration-700 ease-out motion-reduce:transition-none ${
                isActive ? 'opacity-100 translate-y-0 z-10' : 'opacity-0 translate-y-2 z-0 pointer-events-none'
              }`}
            >
              <div className="relative w-2/5 sm:w-1/3 max-w-[360px] bg-slate-900 overflow-hidden">
                {image ? (
                  <img src={image} alt={slideName(offer, locale)} className="w-full h-full object-cover" loading="lazy" />
                ) : (
                  <div className="w-full h-full grid place-items-center gap-1 text-slate-500">
                    <Package className="w-7 h-7" aria-hidden="true" />
                    <span className="text-[10px] px-3 text-center">{COPY[locale].noImage}</span>
                  </div>
                )}
                <div className="absolute inset-0 bg-gradient-to-t from-slate-950/70 via-slate-950/10 to-transparent" />
              </div>

              <div className="flex-1 min-w-0 p-4 sm:p-6 flex flex-col justify-between gap-3">
                <div className="min-w-0 space-y-1.5">
                  <h3 className="text-base sm:text-xl font-black text-white leading-snug line-clamp-2 font-serif">
                    {slideName(offer, locale)}
                  </h3>
                  <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-300 min-w-0">
                    <span className="truncate max-w-[46%]" title={slideSeller(offer, locale)}>{slideSeller(offer, locale)}</span>
                    {where && (
                      <span className="inline-flex items-center gap-1 truncate max-w-[46%]" title={where}>
                        <MapPin className="w-3 h-3 shrink-0 text-emerald-400" aria-hidden="true" />
                        {where}
                      </span>
                    )}
                  </div>
                </div>

                <div className="flex flex-wrap items-end justify-between gap-3">
                  <div className="min-w-0">
                    <div className="text-[10px] text-slate-400">{copy.unit}</div>
                    <div className="text-xl sm:text-2xl font-black text-emerald-300 font-serif leading-tight">
                      {formatMoney(offer.unit_price, offer.currency_code)}
                    </div>
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[11px] text-slate-400 pt-1">
                      <span>{copy.moq}: {offer.min_order_quantity} {offer.unit}</span>
                      <span>{copy.available}: {offer.available_quantity} {offer.unit}</span>
                    </div>
                  </div>

                  <button
                    type="button"
                    onClick={() => onOrder(offer)}
                    tabIndex={isActive ? 0 : -1}
                    className="shrink-0 bg-emerald-500 hover:bg-emerald-400 active:scale-98 text-slate-950 font-bold text-xs px-4 py-2.5 rounded-lg transition-all cursor-pointer shadow-sm"
                  >
                    {copy.ask}
                  </button>
                </div>
              </div>
            </article>
          );
        })}
      </div>

      {offers.length > 1 && (
        <div className="flex items-center gap-1.5 px-4 pb-3" role="tablist" aria-label={copy.title}>
          {offers.map((offer, position) => (
            <button
              key={offer.id}
              type="button"
              role="tab"
              aria-selected={position === index}
              aria-label={`${copy.jump} — ${slideName(offer, locale)}`}
              onClick={() => setIndex(position)}
              className={`h-1.5 rounded-full transition-all duration-300 cursor-pointer ${
                position === index ? 'w-8 bg-amber-300' : 'w-3 bg-white/25 hover:bg-white/45'
              }`}
            />
          ))}
        </div>
      )}

      {/* The one line the strip owes the visitor: this ordering is the platform's
          doing, and it is not a ranking of quality computed from anything. */}
      <p className="px-4 pb-3.5 text-[10px] leading-relaxed text-slate-500">{copy.decay}</p>
    </section>
  );
};

export default PromotedStrip;

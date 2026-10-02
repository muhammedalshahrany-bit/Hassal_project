import React, { useCallback, useEffect, useRef, useState } from 'react';
import { AlertCircle, Camera, ImagePlus, Package, Plus, RefreshCw, ChevronDown, ChevronUp, Star, Trash2, Upload, X } from 'lucide-react';
import { api, fieldError, type ApiResult, type Pagination } from '../lib/api';
import { getLocale } from '../lib/i18n';
import { BannerView, DecisionButton, ErrorNote, LabeledSelect, PanelShell, TextInput, type Banner } from './panel';
import type { Brand, Category, Product, ProductImage, ProductVariant } from '../types';

interface CatalogViewProps {
  can: (permission: string) => boolean;
}

/**
 * One product's photographs.
 *
 * These are the supplier's own pictures of the goods, stored per product and
 * read back by the marketplace as `product_cover_image` / `product_images`.
 * Nothing here is generated, and nothing here is a stock photo standing in for
 * a pallet nobody has seen.
 *
 * The upload answers with two lists, and both are shown: the files that were
 * stored and the files the server sent back with a reason. A picker that
 * accepted five files and stored two would otherwise leave the seller believing
 * the shelf has five photographs.
 *
 * `accept` below mirrors `config('hassal.uploads.images')` so a wrong file type
 * is not worth an upload; the server's refusal is still the rule.
 */
const ProductImagesPanel: React.FC<{ product: Product; openAtImages?: boolean }> = ({
  product,
  openAtImages,
}) => {
  const locale = getLocale();
  const picker = useRef<HTMLInputElement | null>(null);
  const section = useRef<HTMLDivElement | null>(null);
  const [images, setImages] = useState<ProductImage[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [readError, setReadError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [banner, setBanner] = useState<Banner>(null);
  /** Files sitting in the picker, not yet sent. Display only — the upload reads the input itself. */
  const [picked, setPicked] = useState<File[]>([]);
  /** What the last upload answered: how many the server stored, and which it refused with why. */
  const [report, setReport] = useState<{ saved: number; rejected: { file: string; reason: string }[] } | null>(
    null
  );

  const load = useCallback(async () => {
    setReadError(null);
    const result = await api.getProductImages(product.id);
    setLoaded(true);
    if (result.success) setImages(result.data ?? []);
    else {
      setImages([]);
      setReadError(refusalOf(result));
    }
  }, [product.id]);

  useEffect(() => {
    void load();
  }, [load]);

  // Opened from a row's «صور الصنف», the sheet scrolls to the picker instead of
  // leaving the seller to find it under the correction fields.
  useEffect(() => {
    if (openAtImages) section.current?.scrollIntoView({ block: 'start' });
  }, [openAtImages]);

  const upload = async () => {
    const files = Array.from(picker.current?.files ?? []);
    if (!files.length) {
      setBanner({ kind: 'error', text: 'اختر ملفًا أو أكثر أولًا.' });
      return;
    }

    setBusy(true);
    setBanner(null);
    setReport(null);
    const result = await api.uploadProductImages(product.id, files);
    setBusy(false);

    if (!result.success) {
      // NO_IMAGE_ACCEPTED and IMAGE_LIMIT_REACHED both carry what the server
      // would take instead; its words, not a guess about the limit.
      setBanner({ kind: 'error', text: refusalOf(result) });
      setPicked([]);
      if (picker.current) picker.current.value = '';
      void load();
      return;
    }

    const saved = result.data?.images.length ?? 0;
    const rejected = result.data?.rejected ?? [];
    setPicked([]);
    setReport({ saved, rejected });
    setBanner(
      rejected.length
        ? { kind: 'error', text: `حفظ الخادم ${saved} صورة، ومضى في رفض ${rejected.length} ملفًا — أسبابه مبيّنة تحتها.` }
        : {
            kind: 'ok',
            text: `حُفظت ${saved} صورة${
              saved === 1 && !images.some((image) => image.is_cover) ? '؛ صار أول ملف مقبول هو الغلاف' : ''
            }.`,
          },
    );
    if (picker.current) picker.current.value = '';
    void load();
  };

  const setCover = async (image: ProductImage) => {
    setBusy(true);
    setBanner(null);
    const result = await api.updateProductImage(product.id, image.id, { is_cover: true });
    setBusy(false);
    if (!result.success) setBanner({ kind: 'error', text: refusalOf(result) });
    else setBanner({ kind: 'ok', text: result.message ?? 'صارت هذه النسخة هي الغلاف' });
    void load();
  };

  const move = async (image: ProductImage, sortOrder: number) => {
    setBusy(true);
    const result = await api.updateProductImage(product.id, image.id, { sort_order: sortOrder });
    setBusy(false);
    if (!result.success) setBanner({ kind: 'error', text: refusalOf(result) });
    void load();
  };

  const remove = async (image: ProductImage) => {
    setBusy(true);
    setBanner(null);
    const result = await api.deleteProductImage(product.id, image.id);
    setBusy(false);
    setBanner(
      result.success
        ? { kind: 'ok', text: result.message ?? 'حُذفت الصورة' }
        : { kind: 'error', text: refusalOf(result) },
    );
    void load();
  };

  return (
    <div ref={section} className="space-y-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h4 className="flex items-center gap-1.5 text-xs font-black text-slate-900">
          <Camera className="w-4 h-4 text-emerald-700" />
          صور الصنف
        </h4>
        <span className="rounded-full bg-slate-100 px-2.5 py-1 text-[10px] font-bold text-slate-600">
          {loaded ? `${images.length} صورة محفوظة لدى الخادم` : 'لم تُقرأ الصور بعد…'}
        </span>
      </div>

      <p className="text-[11px] leading-relaxed text-slate-500">
        هذه صور المورّد لبضاعته كما رفعها، وهي ما يُقرأ في السوق. أول ملف يقبله الخادم يصير غلاف الصنف إن لم يكن له
        غلاف، والحدود — الحجم وعدد الصور وأنواعها — يضعها الخادم وتظهر في رفضه حين تُتجاوز.
      </p>

      {/* The picker sits above the stored photographs on purpose: the control that
          makes a picture exist is the one a seller has to find first, not the
          gallery that appears only after something was saved. */}
      <div className="space-y-2 rounded-xl border-2 border-dashed border-slate-300 bg-slate-50 p-3">
        <label className="flex cursor-pointer flex-wrap items-center gap-x-2 gap-y-1">
          <input
            ref={picker}
            type="file"
            multiple
            accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
            onChange={(event) => {
              setPicked(Array.from(event.target.files ?? []));
              setReport(null);
              setBanner(null);
            }}
            className="sr-only"
          />
          <span className="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-2 text-[11px] font-bold text-emerald-800">
            <ImagePlus className="w-3.5 h-3.5" />
            اختيار صور من الجهاز
          </span>
          <span className="text-[10px] leading-relaxed text-slate-500">
            JPG أو PNG أو WebP، وأكثر من ملفٍ مرة واحدة. لا يصل شيء إلى الخادم قبل الضغط على «رفع الصور».
          </span>
        </label>

        {picked.length ? (
          <ul className="space-y-1">
            {picked.map((file, index) => (
              <li key={`${file.name}-${index}`} className="flex items-center gap-2 text-[10px] text-slate-600">
                <span className="min-w-0 flex-1 truncate" dir="ltr" title={file.name}>
                  {file.name}
                </span>
                <span className="shrink-0 text-slate-400">{Math.round(file.size / 1024)} KB</span>
              </li>
            ))}
          </ul>
        ) : null}

        <div className="flex flex-wrap items-center gap-2">
          <DecisionButton busy={busy} onClick={() => void upload()}>
            <Upload className="w-3.5 h-3.5" />
            <span>{busy ? 'جارٍ الرفع…' : picked.length ? `رفع الصور (${picked.length})` : 'رفع الصور'}</span>
          </DecisionButton>
          <span className="text-[10px] leading-relaxed text-slate-500">
            {picked.length
              ? 'بعد الرفع يُعرض هنا ما حفظه الخادم وما رفضه وسببه.'
              : 'لم تُختر ملفات بعد؛ الزر يرفع ما في هذا الاختيار فقط.'}
          </span>
        </div>
      </div>

      <BannerView banner={banner} />
      {readError ? <ErrorNote text={readError} /> : null}

      {report?.rejected.length ? (
        <ul className="space-y-1 rounded-lg border border-rose-200 bg-rose-50 p-2.5 text-[10px] leading-relaxed text-rose-800">
          {report.rejected.map((item) => (
            <li key={`${item.file}-${item.reason}`} className="flex items-start gap-1.5">
              <AlertCircle className="mt-0.5 h-3 w-3 shrink-0" />
              <span className="min-w-0">
                <span dir="ltr" className="font-bold">
                  {item.file}
                </span>
                : {item.reason}
              </span>
            </li>
          ))}
        </ul>
      ) : null}

      {images.length ? (
        <ul className="grid grid-cols-2 gap-2 sm:grid-cols-3">
          {images.map((image) => (
            <li key={image.id} className="min-w-0 space-y-1">
              <div className="relative">
                <img
                  src={image.url ?? undefined}
                  alt={image.original_name || `صورة ${image.id}`}
                  className="h-28 w-full rounded-lg border border-slate-200 object-cover bg-slate-50"
                />
                {image.is_cover ? (
                  <span className="absolute top-1 right-1 rounded-md bg-emerald-700/90 px-1.5 py-0.5 text-[9px] font-bold text-white">
                    الغلاف
                  </span>
                ) : null}
              </div>
              <div className="truncate text-[10px] text-slate-500" dir="ltr" title={image.original_name || ''}>
                {image.original_name || `#${image.id}`}
                {image.width && image.height ? ` · ${image.width}×${image.height}` : ''}
                {image.size_bytes ? ` · ${Math.round(image.size_bytes / 1024)} KB` : ''}
              </div>
              <div className="flex flex-wrap items-center gap-1">
                {!image.is_cover ? (
                  <button
                    type="button"
                    disabled={busy}
                    onClick={() => void setCover(image)}
                    className="inline-flex items-center gap-1 rounded-md border border-slate-300 px-1.5 py-0.5 text-[10px] font-bold text-slate-700 hover:bg-slate-100 disabled:opacity-40"
                  >
                    <Star className="w-2.5 h-2.5" />
                    <span>جعلها الغلاف</span>
                  </button>
                ) : null}
                <button
                  type="button"
                  disabled={busy}
                  aria-label="تقديم صورة"
                  onClick={() => void move(image, Math.max(0, (image.sort_order ?? 0) - 1))}
                  className="rounded-md border border-slate-300 p-1.5 text-slate-700 hover:bg-slate-100 disabled:opacity-40"
                >
                  <ChevronUp className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  disabled={busy}
                  aria-label="تأخير صورة"
                  onClick={() => void move(image, (image.sort_order ?? 0) + 1)}
                  className="rounded-md border border-slate-300 p-1.5 text-slate-700 hover:bg-slate-100 disabled:opacity-40"
                >
                  <ChevronDown className="w-3.5 h-3.5" />
                </button>
                <button
                  type="button"
                  disabled={busy}
                  onClick={() => void remove(image)}
                  className="inline-flex items-center gap-1 rounded-md border border-rose-300 px-1.5 py-0.5 text-[10px] font-bold text-rose-800 hover:bg-rose-50 disabled:opacity-40"
                >
                  <Trash2 className="w-2.5 h-2.5" />
                  <span>حذف</span>
                </button>
              </div>
            </li>
          ))}
        </ul>
      ) : loaded && !readError ? (
        <p className="text-[11px] text-slate-500">
          {locale === 'en' ? 'No photograph filed for this product.' : 'لا صورة محفوظة لهذا الصنف لدى الخادم.'}
        </p>
      ) : null}
    </div>
  );
};
/** The keys the server validates, so a refusal can be placed under its field. */
const PRODUCT_FIELDS = ['category_id', 'brand_id', 'name_ar', 'name_en', 'sku', 'unit', 'package_size', 'temperature_class'] as const;
type ProductField = (typeof PRODUCT_FIELDS)[number];
type FieldErrors = Partial<Record<ProductField, string | null>>;

const emptyErrors: FieldErrors = {};

function refusalOf(result: ApiResult<unknown>): string {
  return result.error?.message || result.message || 'تعذّر تنفيذ الطلب على الكتالوج.';
}

export const CatalogView: React.FC<CatalogViewProps> = ({ can }) => {
  const locale = getLocale();
  const [products, setProducts] = useState<Product[]>([]);
  const [categories, setCategories] = useState<Category[]>([]);
  const [brands, setBrands] = useState<Brand[]>([]);
  const [pagination, setPagination] = useState<Pagination | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [loading, setLoading] = useState(true);
  const [readDenied, setReadDenied] = useState<string | null>(null);
  const [openVariants, setOpenVariants] = useState<number | null>(null);
  const [variants, setVariants] = useState<ProductVariant[]>([]);
  const [banner, setBanner] = useState<Banner>(null);

  const canManage = can('catalog.manage');
  const [saving, setSaving] = useState(false);
  const [form, setForm] = useState({
    category_id: '',
    brand_id: '',
    name_ar: '',
    name_en: '',
    sku: '',
    unit: '',
    package_size: '',
    temperature_class: '',
  });
  const [fieldErrors, setFieldErrors] = useState<FieldErrors>(emptyErrors);

  const label = (entity: { name_ar: string; name_en?: string | null } | undefined): string => {
    if (!entity) return 'غير مسجّل';
    return (locale === 'en' && entity.name_en ? entity.name_en : entity.name_ar) || 'غير مسجّل';
  };

  const load = useCallback(async () => {
    setLoading(true);
    setReadDenied(null);
    try {
      const [productResult, categoryResult, brandResult] = await Promise.all([
        api.getProducts({
          page,
          ...(search.trim() ? { search: search.trim() } : {}),
          ...(categoryFilter ? { category_id: Number(categoryFilter) } : {}),
        }),
        api.getCategories(),
        api.getBrands(),
      ]);

      if (productResult.success && Array.isArray(productResult.data)) {
        setProducts(productResult.data as Product[]);
        setPagination(productResult.pagination ?? null);
      } else {
        // Catalogues the account may not read are a refusal, not an empty shelf.
        setProducts([]);
        setPagination(null);
        setReadDenied(refusalOf(productResult));
      }

      if (categoryResult.success && Array.isArray(categoryResult.data)) setCategories(categoryResult.data);
      if (brandResult.success && Array.isArray(brandResult.data)) setBrands(brandResult.data);
    } finally {
      setLoading(false);
    }
  }, [page, search, categoryFilter]);

  useEffect(() => {
    void load();
  }, [load]);

  const toggleVariants = async (product: Product) => {
    if (openVariants === product.id) {
      setOpenVariants(null);
      setVariants([]);
      return;
    }
    setOpenVariants(product.id);
    setVariants(product.variants ?? []);
    const result = await api.getProductVariants(product.id);
    if (result.success && Array.isArray(result.data)) setVariants(result.data as ProductVariant[]);
  };

  const set = (key: ProductField) => (value: string) => {
    setForm((current) => ({ ...current, [key]: value }));
    setFieldErrors((current) => ({ ...current, [key]: null }));
  };

  /** Client-side mirror of the server rules; the server still decides. */
  const validate = (): boolean => {
    const errors: FieldErrors = {};
    if (!form.category_id) errors.category_id = 'التصنيف مطلوب لاختيار الصنف.';
    if (!form.name_ar.trim()) errors.name_ar = 'الاسم بالعربية مطلوب.';
    if (!form.unit.trim()) errors.unit = 'الوحدة التي تُعدّ بها الكمية مطلوبة (كرتون، كجم، كيس…).';
    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const createProduct = async () => {
    setBanner(null);
    if (!validate()) return;

    setSaving(true);
    const payload: Record<string, unknown> = {
      category_id: Number(form.category_id),
      name_ar: form.name_ar.trim(),
      unit: form.unit.trim(),
    };
    if (form.brand_id) payload.brand_id = Number(form.brand_id);
    if (form.name_en.trim()) payload.name_en = form.name_en.trim();
    if (form.sku.trim()) payload.sku = form.sku.trim();
    if (form.package_size.trim()) payload.package_size = form.package_size.trim();
    if (form.temperature_class.trim()) payload.temperature_class = form.temperature_class.trim();

    const result = await api.createProduct(payload);
    setSaving(false);

    if (result.success) {
      setBanner({
        kind: 'ok',
        text:
          'أُنشئ الصنف، وأُنشئ معه صنف افتراضي واحد تلقائياً بحسب سلوك الخادم. لا يظهر في السوق قبل نشر عرض له. ' +
          'صوره تُرفع من زر «صور الصنف» في سطره بالجدول أعلاه.',
      });
      setForm({
        category_id: '',
        brand_id: '',
        name_ar: '',
        name_en: '',
        sku: '',
        unit: '',
        package_size: '',
        temperature_class: '',
      });
      await load();
      return;
    }

    setFieldErrors({
      category_id: fieldError(result, 'category_id'),
      brand_id: fieldError(result, 'brand_id'),
      name_ar: fieldError(result, 'name_ar'),
      name_en: fieldError(result, 'name_en'),
      sku: fieldError(result, 'sku'),
      unit: fieldError(result, 'unit'),
      package_size: fieldError(result, 'package_size'),
      temperature_class: fieldError(result, 'temperature_class'),
    });
    setBanner({ kind: 'error', text: refusalOf(result) });
  };

  /* ─────────────────────────── correcting a product ─────────────────────────── */

  const [editing, setEditing] = useState<Product | null>(null);
  const [editForm, setEditForm] = useState({
    category_id: '',
    brand_id: '',
    name_ar: '',
    name_en: '',
    unit: '',
    package_size: '',
    temperature_class: '',
  });
  const [editErrors, setEditErrors] = useState<FieldErrors>(emptyErrors);
  const [savingEdit, setSavingEdit] = useState(false);
  const [editBanner, setEditBanner] = useState<Banner>(null);
  /** Opened from a row's «صور الصنف»: the sheet scrolls to the uploader. */
  const [imagesRequested, setImagesRequested] = useState(false);

  const setEdit = (key: ProductField) => (value: string) => {
    setEditForm((current) => ({ ...current, [key]: value }));
    setEditErrors((current) => ({ ...current, [key]: null }));
  };

  /**
   * The SKU is deliberately not carried into the drawer. `sku` is the identity
   * the server accepts on create and refuses to move on update — a code that
   * changed under an existing offer would leave the listing pointing at a
   * different thing than it said when it was raised.
   */
  const openEditor = (product: Product, atImages = false) => {
    if (editing?.id === product.id && !atImages) {
      setEditing(null);
      return;
    }
    setImagesRequested(atImages);
    setEditing(product);
    setEditBanner(null);
    setEditErrors(emptyErrors);
    setEditForm({
      category_id: String(product.category_id ?? ''),
      brand_id: product.brand_id ? String(product.brand_id) : '',
      name_ar: product.name_ar ?? '',
      name_en: product.name_en ?? '',
      unit: product.unit ?? '',
      package_size: product.package_size ?? '',
      temperature_class: product.temperature_class ?? '',
    });
  };

  /** Escape closes the correction sheet the same way its ✕ does. */
  useEffect(() => {
    if (!editing) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setEditing(null);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [editing]);

  const saveProduct = async () => {
    if (!editing) return;
    setEditBanner(null);

    if (!editForm.category_id || !editForm.name_ar.trim() || !editForm.unit.trim()) {
      setEditErrors({
        category_id: editForm.category_id ? null : 'التصنيف مطلوب لاختيار الصنف.',
        name_ar: editForm.name_ar.trim() ? null : 'الاسم بالعربية مطلوب.',
        unit: editForm.unit.trim() ? null : 'الوحدة التي تُعدّ بها الكمية مطلوبة.',
      });
      return;
    }

    const payload: Record<string, unknown> = {
      category_id: Number(editForm.category_id),
      name_ar: editForm.name_ar.trim(),
      unit: editForm.unit.trim(),
    };
    if (editForm.brand_id) payload.brand_id = Number(editForm.brand_id);
    if (editForm.name_en.trim()) payload.name_en = editForm.name_en.trim();
    if (editForm.package_size.trim()) payload.package_size = editForm.package_size.trim();
    if (editForm.temperature_class.trim()) payload.temperature_class = editForm.temperature_class.trim();

    setSavingEdit(true);
    const result = await api.updateProduct(editing.id, payload);
    setSavingEdit(false);

    if (!result.success) {
      setEditErrors({
        category_id: fieldError(result, 'category_id'),
        brand_id: fieldError(result, 'brand_id'),
        name_ar: fieldError(result, 'name_ar'),
        name_en: fieldError(result, 'name_en'),
        unit: fieldError(result, 'unit'),
        package_size: fieldError(result, 'package_size'),
        temperature_class: fieldError(result, 'temperature_class'),
      });

      // Two refusals are about ownership rather than a field, and saying which
      // one is the difference between "ask the platform" and "fix your typing".
      if (result.error_code === 'PLATFORM_REFERENCE_NOT_EDITABLE') {
        setEditBanner({
          kind: 'error',
          text: 'هذا صنف مرجعي تملكه المنصة؛ لا تُصحّحه المنشأة. أنشئ صنف منشأتك بالاسم الذي تريده.',
        });
        return;
      }
      if (result.error_code === 'PRODUCT_NOT_OWNED') {
        setEditBanner({ kind: 'error', text: 'هذا الصنف ليس من كتالوج منشأتك العاملة، فالتعديل مرفوض.' });
        return;
      }
      setEditBanner({ kind: 'error', text: refusalOf(result) });
      return;
    }

    setEditBanner({
      kind: 'ok',
      text: result.message ?? 'حُفظ التعديل. الأصناف الفرعية والعروض القائمة تحتفظ بما نُشر عليها وقتها.',
    });
    await load();
  };

  return (
    <div className="mx-auto w-full max-w-7xl space-y-5 px-3 py-6 sm:px-6 lg:px-8">
      <PanelShell
        title="كتالوج الأصناف"
        hint="الأصناف هي ما تُنسب إليه العروض والطلبات. هذه الشاشة تعرض ما يُرجعه `/catalog/products` وتضيف صنفاً، وتُصحّح ما تقبله `PUT /catalog/products/{id}` من اسم وتصنيف وعلامة ووحدة وتغليف وصنف حرارة؛ كود الصنف (SKU) لا يُعدَّل هنا. صور الصنف تُرفع من زر «صور الصنف» في سطر كل صنف."
      >
        <div className="grid grid-cols-1 items-end gap-3 sm:grid-cols-2 lg:grid-cols-4">
          <LabeledSelect
            label="التصنيف الغذائي"
            value={categoryFilter}
            onChange={(value) => {
              setPage(1);
              setCategoryFilter(value);
            }}
            options={[
              { value: '', label: 'كل التصنيفات' },
              ...categories.map((category) => ({ value: String(category.id), label: label(category) })),
            ]}
          />
          <TextInput
            label="بحث بالاسم أو الكود"
            value={search}
            onChange={(value) => {
              setPage(1);
              setSearch(value);
            }}
            placeholder="ارز، حليب، SKU…"
            wide
          />
          <DecisionButton onClick={() => void load()} busy={loading}>
            <RefreshCw className="w-3.5 h-3.5" />
            <span>تحديث</span>
          </DecisionButton>
        </div>

        <BannerView banner={banner} />
        {readDenied ? <ErrorNote text={readDenied} /> : null}

        {loading ? (
          <p className="py-8 text-center text-xs text-slate-500">جارٍ جلب الأصناف…</p>
        ) : products.length === 0 && !readDenied ? (
          <p className="py-8 text-center text-xs text-slate-500">
            لا توجد أصناف مطابقة للبحث الحالي ضمن ما يُرجعه الخادم.
          </p>
        ) : (
          <div className="min-w-0 overflow-x-auto rounded-lg border border-slate-200">
            <table className="w-full min-w-[40rem] text-right text-xs">
              <thead className="bg-slate-50 text-slate-600">
                <tr>
                  <th className="p-3 font-semibold">الصنف</th>
                  <th className="p-3 font-semibold">التصنيف</th>
                  <th className="p-3 font-semibold">العلامة</th>
                  <th className="p-3 font-semibold">الوحدة</th>
                  <th className="p-3 font-semibold">الكود</th>
                  <th className="p-3 font-semibold">التغليف</th>
                  <th className="p-3 text-center font-semibold">الأصناف الفرعية</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {products.map((product) => (
                  <React.Fragment key={product.id}>
                    <tr className="hover:bg-slate-50/70">
                      <td className="p-3">
                        <div className="max-w-[12rem] font-bold text-slate-900" title={label(product)}>
                          {label(product)}
                        </div>
                        {product.name_en && locale !== 'en' ? (
                          <div className="text-[10px] text-slate-400">{product.name_en}</div>
                        ) : null}
                        {canManage ? (
                          <div className="mt-1 flex flex-wrap gap-1.5">
                            <button
                              onClick={() => openEditor(product)}
                              className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2 py-1 text-[10px] font-bold text-slate-700 hover:bg-slate-100"
                            >
                              <ChevronDown className="w-3 h-3" />
                              <span>تصحيح الصنف</span>
                            </button>
                            <button
                              onClick={() => openEditor(product, true)}
                              title="رفع صور هذا الصنف وحفظها لديه"
                              className="inline-flex items-center gap-1 rounded-lg border border-emerald-300 bg-emerald-50 px-2 py-1 text-[10px] font-bold text-emerald-800 hover:bg-emerald-100"
                            >
                              <Camera className="w-3 h-3" />
                              <span>صور الصنف</span>
                            </button>
                          </div>
                        ) : null}
                      </td>
                      <td className="p-3 text-slate-600">
                        {label(categories.find((category) => category.id === product.category_id))}
                      </td>
                      <td className="p-3 text-slate-600">
                        {product.brand_id ? label(brands.find((brand) => brand.id === product.brand_id)) : 'لا علامة مسجّلة'}
                      </td>
                      <td className="p-3 text-slate-700">{product.unit || '—'}</td>
                      <td className="p-3 font-mono text-[11px] text-slate-600">{product.sku || 'غير مسجّل'}</td>
                      <td className="p-3 text-slate-600">{product.package_size || '—'}</td>
                      <td className="p-3 text-center">
                        <button
                          onClick={() => void toggleVariants(product)}
                          className="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 text-[11px] font-bold text-slate-700 hover:bg-slate-100"
                        >
                          {openVariants === product.id ? (
                            <ChevronUp className="w-3.5 h-3.5" />
                          ) : (
                            <ChevronDown className="w-3.5 h-3.5" />
                          )}
                          <span>
                            {product.variants?.length ? `${product.variants.length} صنف` : 'عرض'}
                          </span>
                        </button>
                      </td>
                    </tr>
                    {openVariants === product.id ? (
                      <tr className="bg-slate-50/60">
                        <td colSpan={7} className="p-3">
                          {variants.length === 0 ? (
                            <p className="text-[11px] text-slate-500">
                              لا أصناف فرعية نشطة مسجّلة لهذا الصنف.
                            </p>
                          ) : (
                            <ul className="space-y-1 text-[11px] text-slate-700">
                              {variants.map((variant) => (
                                <li key={variant.id} className="flex flex-wrap items-center gap-3">
                                  <span className="font-bold">
                                    {(locale === 'en' && variant.name_en ? variant.name_en : variant.name_ar) || variant.sku}
                                  </span>
                                  <span className="font-mono text-slate-500">SKU: {variant.sku}</span>
                                  <span className="text-slate-500">
                                    التغليف: {variant.packaging_spec || 'غير مسجّل'}
                                  </span>
                                  {variant.weight_kg != null && variant.weight_kg !== '' ? (
                                    <span className="text-slate-500">الوزن: {variant.weight_kg} كجم</span>
                                  ) : null}
                                  {variant.barcode ? <span className="text-slate-400">{variant.barcode}</span> : null}
                                </li>
                              ))}
                            </ul>
                          )}
                        </td>
                      </tr>
                    ) : null}
                  </React.Fragment>
                ))}
              </tbody>
            </table>
          </div>
        )}

        {pagination ? (
          <div className="flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-600">
            <span>
              صفحة {pagination.current_page} من {pagination.total_pages} · {pagination.total_items} صنف
            </span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={loading || pagination.current_page <= 1}
                className="rounded-lg border border-slate-300 px-3 py-2 font-bold hover:bg-slate-100 disabled:opacity-40"
              >
                السابق
              </button>
              <button
                onClick={() => setPage((current) => current + 1)}
                disabled={loading || !pagination.has_more}
                className="rounded-lg border border-slate-300 px-3 py-2 font-bold hover:bg-slate-100 disabled:opacity-40"
              >
                التالي
              </button>
            </div>
          </div>
        ) : null}
      </PanelShell>

      {canManage ? (
        <PanelShell
          title="إضافة صنف إلى كتالوج المنشأة"
          hint="الصنف يُنسب إلى تصنيف وعلامة اختيارية، ويُحفظ بالوحدة التي تُعدّ بها كمياته فقط. لا عملة ولا سعر على شاشة الكتالوج: السعر والعملة يقرّرهما البائع حين ينشر عرضاً لهذا الصنف. الصنف لا يظهر في السوق بمجرّد إضافته."
        >
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <LabeledSelect
                label="التصنيف (مطلوب)"
                value={form.category_id}
                onChange={set('category_id')}
                options={[
                  { value: '', label: 'اختر تصنيفاً' },
                  ...categories.map((category) => ({ value: String(category.id), label: label(category) })),
                ]}
              />
              {fieldErrors.category_id ? <ErrorNote text={fieldErrors.category_id} /> : null}
            </div>

            <div>
              <LabeledSelect
                label="العلامة التجارية (اختياري)"
                value={form.brand_id}
                onChange={set('brand_id')}
                options={[
                  { value: '', label: 'بدون علامة' },
                  ...brands.map((brand) => ({ value: String(brand.id), label: label(brand) })),
                ]}
              />
              {fieldErrors.brand_id ? <ErrorNote text={fieldErrors.brand_id} /> : null}
            </div>

            <div>
              <TextInput label="اسم الصنف بالعربية (مطلوب)" value={form.name_ar} onChange={set('name_ar')} wide />
              {fieldErrors.name_ar ? <ErrorNote text={fieldErrors.name_ar} /> : null}
            </div>

            <TextInput label="الاسم بالإنجليزية (اختياري)" value={form.name_en} onChange={set('name_en')} wide />

            <div>
              <TextInput label="الوحدة (مطلوب)" value={form.unit} onChange={set('unit')} placeholder="كرتون / كيس / كجم" />
              {fieldErrors.unit ? <ErrorNote text={fieldErrors.unit} /> : null}
            </div>

            <div>
              <TextInput label="كود الصنف SKU (اختياري)" value={form.sku} onChange={set('sku')} />
              {fieldErrors.sku ? <ErrorNote text={fieldErrors.sku} /> : null}
            </div>

            <TextInput label="حجم العبوة (اختياري)" value={form.package_size} onChange={set('package_size')} />
            <TextInput
              label="درجة حرارة التخزين (اختياري)"
              value={form.temperature_class}
              onChange={set('temperature_class')}
              placeholder="جاف / مبرد / مجمّد"
            />
          </div>

          <div className="flex flex-wrap items-center gap-x-3 gap-y-2 pt-1">
            <DecisionButton tone="primary" onClick={() => void createProduct()} busy={saving}>
              <Plus className="w-3.5 h-3.5" />
              <span>حفظ الصنف</span>
            </DecisionButton>
            <span className="flex items-center gap-1.5 text-[11px] text-slate-500">
              <Package className="w-3.5 h-3.5" />
              <span>الإضافة تحتاج صلاحية catalog.manage، ويتحقق منها الخادم عند الطلب.</span>
            </span>
            <span className="flex basis-full items-start gap-1.5 text-[11px] leading-relaxed text-slate-500">
              <Camera className="mt-0.5 w-3.5 h-3.5 shrink-0 text-emerald-700" />
              <span>
                الصور ليست هنا: الخادم يربط الصورة بصنفٍ موجود، فاحفظ الصنف أولاً ثم اضغط «صور الصنف» في سطره بالجدول
                لترفع صوره وتُبيَّن نتيجة كل ملف.
              </span>
            </span>
          </div>
        </PanelShell>
      ) : null}

      {/* The correction sheet is a dialog rather than a row inside the table: a
          form of nine fields and the photograph uploader do not fit a phone when
          they are trapped in a cell of a table that scrolls sideways. */}
      {editing ? (
        <div
          className="fixed inset-0 z-50 flex items-end justify-center bg-slate-900/60 sm:items-center sm:p-4"
          role="dialog"
          aria-modal="true"
          onClick={(event) => {
            if (event.target === event.currentTarget) setEditing(null);
          }}
        >
          <div className="max-h-[92dvh] w-full space-y-4 overflow-y-auto rounded-t-2xl bg-white p-4 pb-[calc(1rem+env(safe-area-inset-bottom))] shadow-2xl sm:max-w-3xl sm:rounded-2xl sm:p-5">
            <div className="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
              <div className="min-w-0">
                <h3 className="truncate text-sm font-black text-slate-900">تصحيح «{label(editing)}»</h3>
                <p className="pt-0.5 text-[11px] leading-relaxed text-slate-500">
                  رقم الصنف #{editing.id} · سبب رفض الخادم لأي حقل يظهر تحت الحقل نفسه.
                </p>
              </div>
              <button
                type="button"
                onClick={() => setEditing(null)}
                aria-label="إغلاق"
                className="-m-1 shrink-0 rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
              >
                <X className="w-5 h-5" />
              </button>
            </div>

            <BannerView banner={editBanner} />

            <section className="space-y-3">
              <h4 className="text-[11px] font-black text-slate-700">بيانات الصنف</h4>
              <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                  <LabeledSelect
                    label="التصنيف (مطلوب)"
                    value={editForm.category_id}
                    onChange={setEdit('category_id')}
                    options={[
                      { value: '', label: 'اختر تصنيفاً' },
                      ...categories.map((category) => ({ value: String(category.id), label: label(category) })),
                    ]}
                  />
                  {editErrors.category_id ? <ErrorNote text={editErrors.category_id} /> : null}
                </div>
                <div>
                  <LabeledSelect
                    label="العلامة التجارية (اختياري)"
                    value={editForm.brand_id}
                    onChange={setEdit('brand_id')}
                    options={[
                      { value: '', label: 'بدون علامة' },
                      ...brands.map((brand) => ({ value: String(brand.id), label: label(brand) })),
                    ]}
                  />
                  {editErrors.brand_id ? <ErrorNote text={editErrors.brand_id} /> : null}
                </div>
                <div>
                  <TextInput label="الاسم بالعربية (مطلوب)" value={editForm.name_ar} onChange={setEdit('name_ar')} wide />
                  {editErrors.name_ar ? <ErrorNote text={editErrors.name_ar} /> : null}
                </div>
                <TextInput label="الاسم بالإنجليزية (اختياري)" value={editForm.name_en} onChange={setEdit('name_en')} wide />
                <div>
                  <TextInput label="الوحدة (مطلوب)" value={editForm.unit} onChange={setEdit('unit')} />
                  {editErrors.unit ? <ErrorNote text={editErrors.unit} /> : null}
                </div>
                <TextInput label="حجم العبوة (اختياري)" value={editForm.package_size} onChange={setEdit('package_size')} />
                <TextInput
                  label="درجة حرارة التخزين (اختياري)"
                  value={editForm.temperature_class}
                  onChange={setEdit('temperature_class')}
                  placeholder="جاف / مبرد / مجمّد"
                />
                <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                  <div className="text-[10px] text-slate-500">كود الصنف (SKU)</div>
                  <div className="font-mono text-[11px] font-bold text-slate-700" dir="ltr">
                    {editing.sku || 'غير مسجّل'}
                  </div>
                  <div className="pt-0.5 text-[10px] leading-relaxed text-slate-400">
                    لا يُعدَّل: العروض القائمة أشارت إليه كما نُشر.
                  </div>
                </div>
              </div>

              <DecisionButton tone="primary" busy={savingEdit} onClick={() => void saveProduct()}>
                <span>حفظ التصحيح</span>
              </DecisionButton>
            </section>

            <section className="border-t border-slate-100 pt-3">
              <ProductImagesPanel key={`${editing.id}`} product={editing} openAtImages={imagesRequested} />
            </section>
          </div>
        </div>
      ) : null}
    </div>
  );
};

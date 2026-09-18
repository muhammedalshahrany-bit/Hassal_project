export type Locale = 'ar' | 'en';

export const LOCALE_KEY = 'hassal_locale';

export const translations = {
  ar: {
    language: 'العربية',
    switchLanguage: 'English',
    login: 'تسجيل الدخول',
    register: 'إنشاء حساب',
    logout: 'تسجيل الخروج',
    notifications: 'الإشعارات',
    noNotifications: 'لا توجد إشعارات',
    markAllRead: 'قراءة الكل',
    marketplace: 'سوق الفرص والمخزون',
    deals: 'الصفقات وطلبات الشراء',
    seller: 'إدارة العروض والمستودعات',
    demands: 'طلبات الاحتياج (RFQ)',
    maps: 'خريطة المستودعات واللوجستيات',
    gmail: 'بريد الصفقات (Gmail)',
    ledger: 'المحفظة وحساب الضمان Escrow',
    disputes: 'النزاعات والتحكيم',
    admin: 'لوحة الإدارة و API Explorer',
    activeUser: 'مستخدم موثّق',
    guest: 'غير مسجل',
    chooseOrganization: 'سجّل الدخول لاختيار المنظمة',
    commercialContext: 'المنظور التجاري:',
    sessionOrganization: 'سياق المنظمة من الجلسة',
    currency: 'العملة الأساسية: الريال اليمني (YER)',
    reset: 'تحديث',
    loading: 'جارٍ التحميل...',
    mapTitle: 'خريطة المستودعات واللوجستيات',
    mapDescription: 'مواقع المستودعات ومراكز التوزيع المسجلة في المنصة.',
    locations: 'المواقع',
  },
  en: {
    language: 'English',
    switchLanguage: 'العربية',
    login: 'Sign in',
    register: 'Create account',
    logout: 'Sign out',
    notifications: 'Notifications',
    noNotifications: 'No notifications',
    markAllRead: 'Mark all read',
    marketplace: 'Marketplace',
    deals: 'Deals & purchase requests',
    seller: 'Offers & warehouses',
    demands: 'Demand board (RFQ)',
    maps: 'Warehouse logistics map',
    gmail: 'Deal email (Gmail)',
    ledger: 'Wallet & escrow',
    disputes: 'Disputes & arbitration',
    admin: 'Admin & API Explorer',
    activeUser: 'Verified user',
    guest: 'Guest',
    chooseOrganization: 'Sign in to choose an organization',
    commercialContext: 'Business context:',
    sessionOrganization: 'Organization session context',
    currency: 'Base currency: Yemeni rial (YER)',
    reset: 'Refresh',
    loading: 'Loading...',
    mapTitle: 'Warehouse logistics map',
    mapDescription: 'Registered warehouses and distribution centers on the platform.',
    locations: 'Locations',
  },
} as const;

export type TranslationKey = keyof typeof translations.ar;

export function getLocale(): Locale {
  if (typeof window === 'undefined') return 'ar';
  return localStorage.getItem(LOCALE_KEY) === 'en' ? 'en' : 'ar';
}

export function localizedField<T extends Record<string, unknown>>(item: T, field: string, locale: Locale): string {
  const localized = item[`${field}_${locale}`] ?? item[`${field}_ar`] ?? item[`${field}_en`];
  return typeof localized === 'string' ? localized : '';
}

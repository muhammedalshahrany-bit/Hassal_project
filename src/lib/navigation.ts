import {
  ClipboardList,
  LayoutGrid,
  Megaphone,
  Map as MapIcon,
  MoreHorizontal,
  Package,
  Settings2,
  Scale,
  ShieldCheck,
  ShoppingCart,
  Store,
  BookOpen,
  UserCircle2,
  Users,
  Building2,
  type LucideIcon,
} from 'lucide-react';
import type { Locale } from './i18n';

/**
 * The navigation map, in one place.
 *
 * Every destination used to be written out three times — a tab in the header
 * strip, a case in `App.canOpenView`, and a view block below it — with the
 * label, the emoji and the active styling repeated in each. That is how a tab
 * ends up offered for one permission and rendered for another (the demands
 * screen did exactly that), and it left no shared list a phone could build a
 * bottom bar from.
 *
 * So the vocabulary lives here: id, icon, bilingual label, and the order both
 * the desktop strip and the mobile sheet walk. `App.canOpenView` stays the
 * single answer to "may this session open it", and this file deliberately does
 * not duplicate that rule — a nav table that carried its own copy would drift
 * from the server's again.
 */
export interface NavItem {
  id: string;
  Icon: LucideIcon;
  label: Record<Locale, string>;
  /** Fits a bottom bar where the full name is a sentence. */
  short: Record<Locale, string>;
  /**
   * Whether the destination is worth a thumb on a phone. The strip under the
   * header shows everything; the bottom bar shows these, in this order, and
   * whatever the account is allowed to open.
   */
  mobilePriority?: number;
  /**
   * Which heading this destination sits under when a phone has to list them
   * all. Grouping is what makes a nine-item sheet scannable; an alphabet of
   * equal-weight buttons is what makes people read all nine.
   */
  group?: 'work' | 'business' | 'platform';
  /**
   * A destination that already has a place of its own in the chrome, and is
   * therefore not repeated as a tab.
   *
   * `profile` is the account, and the account is the chip beside the brand:
   * the same screen in the tab strip, in the bottom bar and in the account
   * sheet as well made three doors to one room, and a person standing in front
   * of three doors reasonably assumes they open onto three different places.
   * `org` and `members` belong to the account sheet on a phone for the same
   * reason — they are about *whose* business this session is, which is exactly
   * what that sheet is for.
   */
  viaAccount?: boolean;
}

export const GROUP_LABELS: Record<NonNullable<NavItem['group']>, Record<Locale, string>> = {
  work: { ar: 'العمل اليومي', en: 'Day-to-day' },
  business: { ar: 'المنشأة والفريق', en: 'Business & team' },
  platform: { ar: 'إدارة المنصة', en: 'Platform administration' },
};

export const NAV_ITEMS: NavItem[] = [
  {
    id: 'marketplace',
    Icon: ShoppingCart,
    label: { ar: 'سوق الفرص والمخزون', en: 'Marketplace' },
    short: { ar: 'السوق', en: 'Market' },
    mobilePriority: 1,
    group: 'work',
  },
  {
    id: 'deals',
    Icon: ClipboardList,
    label: { ar: 'الصفقات وطلبات الشراء', en: 'Deals & purchase requests' },
    short: { ar: 'الصفقات', en: 'Deals' },
    mobilePriority: 2,
    group: 'work',
  },
  {
    id: 'seller',
    Icon: Store,
    label: { ar: 'إدارة العروض والمستودعات', en: 'Offers & warehouses' },
    short: { ar: 'عروضي', en: 'Offers' },
    mobilePriority: 3,
    group: 'work',
  },
  {
    id: 'demands',
    Icon: Megaphone,
    label: { ar: 'طلبات الاحتياج (RFQ)', en: 'Demand board (RFQ)' },
    short: { ar: 'الاحتياج', en: 'Demands' },
    mobilePriority: 5,
    group: 'work',
  },
  {
    id: 'catalog',
    Icon: Package,
    label: { ar: 'كتالوج الأصناف', en: 'Product catalog' },
    short: { ar: 'الكتالوج', en: 'Catalog' },
    mobilePriority: 6,
    group: 'work',
  },
  {
    id: 'maps',
    Icon: MapIcon,
    label: { ar: 'خريطة المستودعات واللوجستيات', en: 'Warehouse logistics map' },
    short: { ar: 'الخريطة', en: 'Map' },
    // Fourth rather than last: a guest can open the marketplace and the map and
    // nothing else, and a bottom bar with one destination is a decoration.
    mobilePriority: 4,
    group: 'work',
  },
  {
    id: 'org',
    Icon: Building2,
    label: { ar: 'ملف المنشأة والسجل', en: 'Organization & legal record' },
    short: { ar: 'المنشأة', en: 'Business' },
    mobilePriority: 7,
    group: 'business',
    viaAccount: true,
  },
  {
    id: 'members',
    Icon: Users,
    label: { ar: 'عضوية المنشأة', en: 'Organization members' },
    short: { ar: 'الأعضاء', en: 'Members' },
    mobilePriority: 8,
    group: 'business',
    viaAccount: true,
  },
  {
    id: 'profile',
    Icon: UserCircle2,
    label: { ar: 'ملفي الشخصي', en: 'My profile' },
    short: { ar: 'حسابي', en: 'Account' },
    mobilePriority: 9,
    group: 'business',
    // The chip beside «حَصّال» is its only tab. It was in the strip, the bottom
    // bar and the account sheet on top of that.
    viaAccount: true,
  },
  {
    id: 'ledger',
    Icon: BookOpen,
    label: { ar: 'دفتر الأستاذ والقيود', en: 'Ledger & journal entries' },
    short: { ar: 'الدفتر', en: 'Ledger' },
    group: 'business',
  },
  {
    id: 'disputes',
    Icon: Scale,
    label: { ar: 'النزاعات والتحكيم', en: 'Disputes & arbitration' },
    short: { ar: 'النزاعات', en: 'Disputes' },
    group: 'business',
  },
  {
    id: 'admin',
    Icon: ShieldCheck,
    label: { ar: 'لوحة قرارات المنصة', en: 'Platform decisions' },
    short: { ar: 'القرارات', en: 'Decisions' },
    group: 'platform',
  },
  {
    id: 'config',
    Icon: Settings2,
    label: { ar: 'إعدادات المنصة والقدرات', en: 'Platform settings' },
    short: { ar: 'الإعدادات', en: 'Settings' },
    group: 'platform',
  },
];

/** Tabs a screen chrome may offer: everything that is not the account's own. */
export const TAB_ITEMS: NavItem[] = NAV_ITEMS.filter((item) => !item.viaAccount);

/** The account-sheet destinations, in the order the sheet shows them. */
export const ACCOUNT_DESTINATIONS: NavItem[] = NAV_ITEMS.filter(
  (item) => item.viaAccount
);

/** The overflow control's own icon, exported so both bars share it. */
export const MORE_ICON: LucideIcon = MoreHorizontal;

export const MORE_LABEL: Record<Locale, string> = {
  ar: 'المزيد',
  en: 'More',
};

export function navItem(id: string): NavItem | undefined {
  return NAV_ITEMS.find((item) => item.id === id);
}

export function navLabel(id: string, locale: Locale, fallback: string): string {
  return navItem(id)?.label[locale] ?? fallback;
}

/**
 * The four cells of a phone's bottom bar: the marketplace always, then the
 * three highest-priority destinations this account may actually open, then the
 * sheet that holds the rest.
 *
 * Priority decides rather than permission-counting: a buyer and a seller get
 * different bars from the same table, and neither sees a door the server would
 * shut in their face. Account destinations never compete for a cell — the chip
 * beside the brand already carries them.
 */
export function mobilePrimaryNav(canOpen: (view: string) => boolean): NavItem[] {
  const ordered = TAB_ITEMS.filter((item) => item.mobilePriority).sort(
    (a, b) => (a.mobilePriority ?? 99) - (b.mobilePriority ?? 99)
  );

  const picked: NavItem[] = [];
  for (const item of ordered) {
    if (item.id === 'marketplace') {
      picked.push(item);
      continue;
    }
    if (picked.length >= 3) break;
    if (canOpen(item.id)) picked.push(item);
  }

  return picked;
}

/**
 * Everything the account may open that is not already in the bar, grouped under
 * the headings the sheet prints.
 */
export function mobileOverflowNav(
  canOpen: (view: string) => boolean,
  exceptIds: string[]
): NavItem[] {
  return TAB_ITEMS.filter((item) => !exceptIds.includes(item.id) && canOpen(item.id));
}

export interface NavGroup {
  group: NonNullable<NavItem['group']>;
  items: NavItem[];
}

/** Table order, then the order the headings are printed in. */
export function groupNav(items: NavItem[]): NavGroup[] {
  const order: NonNullable<NavItem['group']>[] = ['work', 'business', 'platform'];

  return order
    .map((group) => ({ group, items: items.filter((item) => item.group === group) }))
    .filter((section) => section.items.length > 0);
}

/** Rendered as a small grid icon when a destination has no entry of its own. */
export const NAV_GRID_ICON: LucideIcon = LayoutGrid;

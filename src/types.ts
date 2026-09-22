/**
 * Client-side shapes for the HASSAL API.
 *
 * These mirror what the endpoints actually return: statuses keep the server's
 * UPPERCASE vocabulary, amounts are sent as decimal strings by MySQL, and any
 * field the platform only knows after a human entered it is nullable. When a
 * type here and a component disagree, the component is wrong — fix the
 * component rather than loosening the type until it accepts anything.
 */

export interface User {
  id: number;
  name: string;
  email: string;
  phone?: string | null;
  national_id?: string | null;
  status: string;
  roles?: string[];
  permissions?: string[];
}

/**
 * A row from `GET /users`, the platform-admin listing.
 *
 * `roles` are the Spatie names attached to the account — the same strings
 * `/auth/me` gives — so `roles[0]` is `'SuperAdmin'`, not an object. Only the
 * platform's own roles can appear here now: `POST /users/{id}/roles` accepts
 * nothing outside `GET /users/roles`, and a migration detached the retired
 * business names (`SellerAdmin` and the rest) from the accounts that carried
 * them, so a list no longer mixes two layers that mean different things.
 *
 * `organizations[].role` is the person's standing inside that business, read
 * from `organization_memberships`, and it is the layer that decides what they
 * may do there. It arrives as a named field for the same reason this type exists:
 * when the endpoint handed back raw models the standing lived in `pivot.role`,
 * this field was `undefined`, and every dropdown on the screen fell back to its
 * first option and showed the wrong role.
 */
export interface ManagedUser {
  id: number;
  name: string;
  email: string | null;
  phone?: string | null;
  national_id?: string | null;
  /** Lowercase: `active`, `suspended`, `inactive`. Login only allows `active`. */
  status: 'active' | 'suspended' | 'inactive' | string;
  roles?: string[];
  organizations?: OrganizationSummary[];
  email_verified_at?: string | null;
  phone_verified_at?: string | null;
  created_at?: string;
}

/**
 * What a person is inside one business. Membership role and platform role are
 * separate: an `admin` here is an administrator *of that organization*, and the
 * server accepts no other value.
 *
 * `staff` is the ordinary employee standing; it replaced `operator`, which only
 * ever existed in the column default and in a validator that rejected the value
 * (`staff`) the rows actually held.
 */
export type MembershipRole = 'owner' | 'admin' | 'staff' | 'finance' | 'auditor';

/**
 * One option from `GET /organizations/membership-roles` — the server's own
 * vocabulary plus the label it wants shown for it, so no screen writes the
 * list out and drifts from what the validator accepts.
 *
 * `permissions` is the standing's actual worth, read from the same matrix the
 * server enforces with. It is optional because a row can be built from a stored
 * role name alone when the vocabulary endpoint has not answered; such a row
 * names the standing but cannot say what it grants, and the screens that would
 * show the grants stay silent rather than guess.
 */
export interface MembershipRoleOption {
  value: string;
  label: string;
  permission_count?: number;
  permissions?: string[];
}

/**
 * One row from `GET /users/roles` — the roles a platform account may be granted,
 * read from the `roles` table narrowed to `config('rbac.platform_roles')`.
 *
 * `permissions` is the role's actual worth, counted by the server from its own
 * synced rows rather than a list copied into the client, so the console can say
 * what `Auditor` grants before anyone hands it out. Optional because a role that
 * has not been synced yet reports nothing; the screen then shows the name alone
 * rather than a number it invented.
 */
export interface PlatformRoleOption {
  id: number;
  name: string;
  permission_count?: number;
  permissions?: string[];
}

/** The organization the current session acts as (`auth/me.current_organization`). */
export interface ActiveOrganization {
  id: number;
  name_ar: string;
  name_en: string;
  type: OrganizationType;
  status: OrganizationStatus;
  cr_number: string | null;
  vat_number: string | null;
  credit_limit: string | number | null;
  /** null until completed deals and inspections produce a real score. */
  rating: number | null;
  membership_role?: string | null;
}

/**
 * One of the organizations a user belongs to (`auth/me.organizations`, and the
 * same shape in a `GET /users` row, where `role` is the membership standing).
 *
 * `membership_active` and `joined_at` only arrive on the admin listing: an
 * ended membership stays listed there, because a console that dropped it would
 * hide the fact an operator is asking about.
 */
export interface OrganizationSummary {
  id: number;
  name_ar: string;
  name_en: string;
  type: OrganizationType;
  status: OrganizationStatus;
  role: string;
  membership_active?: boolean;
  joined_at?: string | null;
}

/** @deprecated use {@link ActiveOrganization}. */
export type OrganizationMembership = ActiveOrganization;
/** @deprecated use {@link OrganizationSummary}. */
export type OrganizationMember = OrganizationSummary;

/**
 * What the account still owes this deployment, carried by `/auth/login`,
 * `/auth/register`, `/auth/google` and `/auth/me`.
 *
 * The client does not decide which fields a signup needs — the operator's
 * `account.require_*` settings do, and the server has already read them. So a
 * form here asks for exactly `missing_fields` and nothing else: no field can be
 * demanded after the setting is switched off, and none can be skipped while an
 * action is still refused.
 */
export interface ProfileCompletion {
  required: boolean;
  /** Field keys the server named, e.g. `phone`, `national_id`. */
  missing_fields: string[];
  has_organization: boolean;
  /** False for an account opened from a provider assertion: it has no local password. */
  has_password: boolean;
}

export type OrganizationType = 'buyer' | 'seller' | 'intermediary' | 'admin';

/**
 * `verified` means an administrator of this platform accepted the documents
 * submitted to it. It does not mean a government system confirmed anything,
 * and no screen may say otherwise.
 */
export type OrganizationStatus = 'pending_kyc' | 'verified' | 'suspended' | 'rejected';

/** A business as stored (`organizations`), returned by the organization endpoints. */
export interface Organization {
  id: number;
  name_ar: string;
  name_en: string;
  trade_name_ar?: string | null;
  trade_name_en?: string | null;
  type: OrganizationType;
  country_code?: string | null;
  city?: string | null;
  website?: string | null;
  phone?: string | null;
  email?: string | null;
  commercial_registration_number?: string | null;
  vat_number?: string | null;
  status: OrganizationStatus;
  credit_limit?: string | number | null;
  rating: number | null;
  /**
   * When an administrator marked this business's documents as reviewed. The
   * column keeps the legacy name `kyc_verified_at`; it records an internal
   * review, never a claim that an official source confirmed anything.
   */
  kyc_verified_at?: string | null;
  /** Active memberships, counted by the endpoint rather than stored. */
  members_count?: number;
  created_at?: string | null;
  metadata?: Record<string, unknown> | string | null;
}

export interface Category {
  id: number;
  parent_id?: number | null;
  code?: string | null;
  slug: string;
  name_ar: string;
  name_en: string;
  description_ar?: string | null;
  description_en?: string | null;
  icon?: string | null;
  sort_order: number;
  is_active?: boolean;
  children?: Category[];
}

export interface Brand {
  id: number;
  name_ar: string;
  name_en: string;
  slug?: string;
  logo_url?: string | null;
  is_active?: boolean;
}

/**
 * A storage location. Coordinates are nullable on purpose: a warehouse whose
 * position was never recorded must not be ranked as though it were somewhere,
 * so distance UI has to hide the distance rather than guess one.
 */
export interface Warehouse {
  id: number;
  organization_id: number;
  name: string;
  code?: string | null;
  city: string;
  district?: string | null;
  address_line: string;
  latitude: string | number | null;
  longitude: string | number | null;
  contact_name?: string | null;
  contact_phone?: string | null;
  is_active?: boolean;
}

export interface ProductVariant {
  id: number;
  product_id: number;
  sku: string;
  barcode?: string | null;
  name_ar: string;
  name_en: string;
  weight_kg?: string | number | null;
  packaging_spec?: string | null;
  image_url?: string | null;
  attributes_json?: Record<string, unknown> | null;
  is_active?: boolean;
}

export interface Product {
  id: number;
  organization_id?: number | null;
  category_id: number;
  brand_id?: number | null;
  sku?: string | null;
  barcode?: string | null;
  name_ar: string;
  /** Optional by contract and by column: the catalogue is named in Arabic first. */
  name_en?: string | null;
  description_ar?: string | null;
  description_en?: string | null;
  /** The unit a quantity is counted in, e.g. `carton`, `kg`, `pallet`. */
  unit: string;
  package_size?: string | null;
  temperature_class?: string | null;
  shelf_life_days?: number | null;
  image_url?: string | null;
  is_active?: boolean;
  category_name_ar?: string | null;
  brand_name_ar?: string | null;
  variants?: ProductVariant[];
}

/**
 * Why a seller releases stock. The server owns this vocabulary and serves the
 * labels through `bootstrap.opportunity_reasons`, so the codes listed here are
 * documentation — not a list the client may invent values for.
 */
export type OpportunityReason =
  | 'SURPLUS_STOCK'
  | 'SLOW_MOVING'
  | 'SEASONAL_STOCK'
  | 'EXCESS_INVENTORY'
  | 'LIQUIDATION'
  | 'BRANCH_CLOSURE'
  | 'ASSORTMENT_CHANGE'
  | 'LOW_LOCAL_DEMAND'
  | string;

export type OfferStatus =
  | 'DRAFT'
  | 'PENDING_REVIEW'
  | 'ACTIVE'
  | 'PAUSED'
  | 'SOLD_OUT'
  | 'EXPIRED'
  | 'CANCELLED';

/** Flat offer payload as returned by `OfferResource`. */
export interface Offer {
  id: number;
  seller_organization_id: number;
  warehouse_id: number;
  product_variant_id: number;
  category_id: number | null;

  title_ar: string;
  title_en?: string | null;
  description_ar?: string | null;
  description_en?: string | null;

  opportunity_reason: OpportunityReason;
  opportunity_reason_notes?: string | null;
  status: OfferStatus;

  total_quantity: string | number;
  /** On the shelf right now. Reserved and sold stay separate facts. */
  available_quantity: string | number;
  reserved_quantity: string | number;
  sold_quantity: string | number;
  min_order_quantity: string | number;
  max_order_quantity?: string | number | null;
  unit: string;

  unit_price: string | number;
  currency_code: string;

  production_date?: string | null;
  expiry_date?: string | null;
  storage_condition?: string | null;
  delivery_terms: 'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH' | string;
  inspection_window_hours?: number | null;
  published_at?: string | null;
  expires_at?: string | null;

  product_id?: number | null;
  product_name_ar?: string | null;
  product_name_en?: string | null;
  variant_sku?: string | null;
  variant_name_ar?: string | null;
  variant_name_en?: string | null;
  variant_image?: string | null;
  weight_kg?: string | number | null;
  packaging_spec?: string | null;
  brand_id?: number | null;
  brand_name_ar?: string | null;
  brand_name_en?: string | null;
  product_image?: string | null;
  /**
   * Photographs the seller actually uploaded, ordered as the buyer should see
   * them. Present only where the endpoint loaded the relation.
   */
  product_images?: ProductImage[];
  product_cover_image?: string | null;

  category_name_ar?: string | null;
  category_name_en?: string | null;

  warehouse_name?: string | null;
  warehouse_city?: string | null;
  warehouse_district?: string | null;
  warehouse_address?: string | null;
  warehouse_lat?: string | number | null;
  warehouse_lon?: string | number | null;

  seller_name_ar?: string | null;
  seller_name_en?: string | null;
  seller_status?: OrganizationStatus | null;
  seller_cr?: string | null;
  seller_vat?: string | null;
  /** null until real completed deals exist; never render a placeholder score. */
  seller_rating?: number | null;

  /**
   * The seller's own choice about this listing. When it is `false` the server
   * sends `null` for the name, registration and tax numbers and for the
   * warehouse's name and address line, to everyone except the selling
   * organization's members and a platform reviewer — so a missing name here is
   * a recorded decision, not data the platform never had.
   *
   * It never hides the seller from a transaction: a purchase request still
   * reaches the named company, because a counterparty you cannot name is not a
   * counterparty.
   */
  show_seller_identity?: boolean;

  /**
   * Counted rows, sent only by the endpoints that run `withCount`. These are
   * not scores: `likes_count` is how many businesses recorded interest, and
   * `comments_count` counts published questions only.
   *
   * Whether *this* account liked the listing is deliberately absent — the
   * public list answers identically signed in or not. Read
   * `GET /marketplace/liked-offers` for that.
   */
  likes_count?: number;
  comments_count?: number;

  /** Only the nearby endpoint computes this. */
  distance_km?: number | null;

  /**
   * Attached by `GET /marketplace/moderation-queue` only, and only for callers
   * holding `offers.moderate`. Every other offer response omits it, so a
   * business looking at its own listing sees no reviewer dossier.
   */
  moderation?: OfferModerationContext;

  items?: OfferItem[];
}

export interface OfferItem {
  id: number;
  product_id: number;
  product_variant_id: number;
  quantity: string | number;
  unit_price: string | number;
  currency_code: string;
}

export type ReservationStatus = 'ACTIVE' | 'CONVERTED_TO_DEAL' | 'EXPIRED' | 'CANCELLED';

/** A stock hold. It is neither a deal nor a purchase request. */
export interface Reservation {
  id: number;
  reservation_number: string;
  offer_id: number;
  buyer_organization_id: number;
  buyer_user_id: number;
  quantity: string | number;
  unit_price: string | number;
  currency_code: string;
  total_amount: string | number;
  delivery_preference: 'PICKUP' | 'DELIVERY' | string;
  status: ReservationStatus;
  expires_at?: string | null;
  converted_at?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  notes?: string | null;
  created_at?: string;
  offer?: Offer | null;
}

export type PurchaseRequestStatus = 'REQUESTED' | 'ACCEPTED' | 'REJECTED' | 'PROPOSED' | 'CANCELLED';

/**
 * The seller's counter-offer, exactly as `PurchaseRequestController::propose()`
 * stores it: only the terms that changed, plus the currency the price is in.
 * Who proposed it is `seller_user_id` on the request, not a field of the terms.
 */
export interface ProposedTerms {
  quantity?: string | number;
  unit_price?: string | number;
  currency_code?: string;
}

export interface PurchaseRequest {
  id: number;
  purchase_request_number: string;
  demand_id?: number | null;
  demand_match_id?: number | null;
  offer_id: number;
  reservation_id?: number | null;
  buyer_organization_id: number;
  seller_organization_id: number;
  buyer_user_id: number;
  seller_user_id?: number | null;
  requested_quantity: string | number;
  unit: string;
  requested_price: string | number;
  currency_code: string;
  delivery_preference: 'PICKUP' | 'DELIVERY' | string;
  status: PurchaseRequestStatus;
  notes?: string | null;
  proposed_terms?: ProposedTerms | null;
  created_at?: string;
  offer?: Offer | null;
  reservation?: Reservation | null;
  deal_id?: number | null;
}

export type DemandStatus = 'OPEN' | 'QUOTED' | 'ACCEPTED' | 'CLOSED' | 'EXPIRED';

export interface Demand {
  id: number;
  demand_number: string;
  buyer_organization_id: number;
  buyer_user_id: number;
  category_id?: number | null;
  title_ar: string;
  title_en?: string | null;
  description?: string | null;
  target_quantity: string | number;
  unit: string;
  target_unit_price?: string | number | null;
  currency_code: string;
  target_delivery_date?: string | null;
  delivery_city: string;
  delivery_latitude?: string | number | null;
  delivery_longitude?: string | number | null;
  special_requirements?: string | null;
  status: DemandStatus;
  expires_at?: string | null;
  created_at?: string;
  category_name_ar?: string | null;
  buyer_organization?: BusinessSummary | null;
  quotations?: Quotation[];
}

export interface Quotation {
  id: number;
  quotation_number: string;
  demand_id: number;
  seller_organization_id: number;
  seller_user_id: number;
  quoted_quantity: string | number;
  unit_price: string | number;
  currency_code: string;
  total_amount: string | number;
  validity_days?: number | null;
  notes?: string | null;
  status: string;
  accepted_at?: string | null;
  rejected_at?: string | null;
  converted_deal_id?: number | null;
  seller_organization?: BusinessSummary | null;
}

export type MatchStatus = 'ACTIVE' | 'USED' | 'EXPIRED' | 'INVALIDATED';

/**
 * One pairing the matching engine produced. `match_reasons` is the list that
 * explains the pairing; a bare score is not an explanation and must not be
 * presented as one.
 */
export interface DemandMatch {
  id: number;
  demand_id: number;
  offer_id: number;
  buyer_organization_id: number;
  seller_organization_id: number;
  requested_quantity: string | number;
  match_score?: string | number | null;
  match_reasons?: string[] | null;
  estimated_total?: string | number | null;
  status: MatchStatus;
  expires_at?: string | null;
  offer?: Offer | null;
}

export type DealStatus =
  | 'DRAFT'
  | 'PENDING_SELLER_APPROVAL'
  | 'ACCEPTED'
  | 'PENDING_PAYMENT'
  | 'ESCROW_FUNDED'
  | 'PROCESSING'
  | 'READY_FOR_PICKUP'
  | 'DISPATCHED'
  | 'DELIVERED'
  | 'INSPECTION'
  | 'COMPLETED'
  | 'SETTLED'
  | 'CANCELLED'
  | 'DISPUTED'
  | 'RESOLVED'
  | 'REFUNDED';

/**
 * A payment the platform was told about. `SUCCEEDED` is set by a human or a
 * provider callback that verified it — an unverified transfer stays `PENDING`,
 * so no screen can read "paid" from a claim alone.
 */
export interface Payment {
  id: number;
  payment_number: string;
  deal_id: number;
  organization_id: number;
  amount: string | number;
  currency_code: string;
  exchange_rate_snapshot?: string | number | null;
  provider: 'BANK_TRANSFER' | 'CASH_ON_DELIVERY' | 'ESCROW_WALLET' | 'ONLINE_GATEWAY' | string;
  status: 'PENDING' | 'PROCESSING' | 'SUCCEEDED' | 'FAILED' | 'REFUNDED' | string;
  transaction_reference?: string | null;
  bank_receipt_url?: string | null;
  verified_by_user_id?: number | null;
  verified_at?: string | null;
  created_at?: string;
}

/** Held funds for a deal. Only exists when the escrow feature is switched on. */
export interface Escrow {
  id: number;
  escrow_number: string;
  deal_id: number;
  buyer_organization_id: number;
  seller_organization_id: number;
  gross_amount: string | number;
  platform_fee_amount: string | number;
  seller_net_amount: string | number;
  currency_code: string;
  status: 'HELD' | 'RELEASED' | 'REFUNDED' | 'PARTIALLY_REFUNDED' | string;
  funded_at?: string | null;
  released_at?: string | null;
  refunded_at?: string | null;
  release_notes?: string | null;
}

/**
 * The organization objects nested inside deals, demands and quotations.
 *
 * These endpoints serialize the whole `organizations` row, so a screen may show
 * a tax number or a registration state from here. Anything it does show must be
 * rendered as recorded — a missing number says "not recorded", never a
 * placeholder.
 */
export type BusinessSummary = Pick<
  Organization,
  'id' | 'name_ar' | 'name_en' | 'type' | 'status'
> &
  Partial<Organization>;

/**
 * The commercial snapshot frozen when the deal was created. The rates and
 * amounts below are facts about that deal; later setting changes never rewrite
 * them.
 */
export interface Deal {
  id: number;
  deal_number: string;
  offer_id?: number | null;
  reservation_id?: number | null;
  purchase_request_id?: number | null;
  buyer_organization_id: number;
  seller_organization_id: number;
  buyer_user_id?: number | null;
  seller_user_id?: number | null;
  warehouse_id?: number | null;
  quantity: string | number;
  unit_price: string | number;
  currency_code: string;
  transaction_currency?: string | null;
  base_currency?: string | null;
  exchange_rate?: string | number | null;
  exchange_rate_snapshot?: string | number | null;
  rate_source?: string | null;
  rate_timestamp?: string | null;
  base_amount?: string | number | null;
  discount_amount?: string | number | null;
  tax_rate_snapshot: string | number;
  tax_amount: string | number;
  platform_fee_rate_snapshot: string | number;
  platform_fee_amount: string | number;
  delivery_fee_amount?: string | number | null;
  buyer_total_amount: string | number;
  seller_net_amount: string | number;
  status: DealStatus;
  delivery_method?: string | null;
  delivery_address?: string | null;
  inspection_window_hours?: number | null;
  inspection_deadline_at?: string | null;
  accepted_at?: string | null;
  paid_at?: string | null;
  dispatched_at?: string | null;
  delivered_at?: string | null;
  completed_at?: string | null;
  settled_at?: string | null;
  cancelled_at?: string | null;
  cancellation_reason?: string | null;
  created_at?: string;
  items?: DealItem[];
  status_histories?: DealStatusHistory[];
  payments?: Payment[];
  escrow?: Escrow | null;
  dispute?: Dispute | null;
  buyer_organization?: BusinessSummary | null;
  seller_organization?: BusinessSummary | null;
  offer?: Offer | null;
}

export interface DealItem {
  id: number;
  deal_id: number;
  product_variant_id: number;
  snapshot_product_name: string | null;
  snapshot_sku: string | null;
  snapshot_unit_name: string | null;
  quantity: string | number;
  unit_price: string | number;
  subtotal_amount: string | number;
}

export interface DealStatusHistory {
  id: number;
  deal_id: number;
  from_status: string | null;
  to_status: string;
  changed_by_user_id?: number | null;
  changed_by_user?: { id: number; name: string } | null;
  notes?: string | null;
  created_at: string;
}

export type DisputeStatus =
  | 'OPEN'
  | 'UNDER_REVIEW'
  | 'EVIDENCE_REQUIRED'
  | 'RESOLVED_REFUND'
  | 'RESOLVED_RELEASE'
  | 'RESOLVED_PARTIAL'
  | 'REJECTED';

export interface Dispute {
  id: number;
  dispute_number: string;
  deal_id: number;
  initiator_organization_id: number;
  initiator_user_id: number;
  reason: string;
  description?: string | null;
  claimed_amount: string | number;
  evidence_urls?: string[] | null;
  status: DisputeStatus;
  resolution_decision?: string | null;
  refunded_amount?: string | number | null;
  resolution_notes?: string | null;
  resolved_by_user_id?: number | null;
  resolved_at?: string | null;
  created_at?: string;
  deal?: Deal | null;
}

/** A chart-of-accounts row, as `ledger/accounts` returns the model. */
export interface LedgerAccount {
  id: number;
  account_code: string;
  name_ar?: string | null;
  name_en?: string | null;
  /** ASSET | LIABILITY | EQUITY | REVENUE | EXPENSE */
  type: string;
  organization_id?: number | null;
  currency_code: string;
  balance?: string | number | null;
  is_active?: boolean;
}

/** One side of a posting. Debits and credits are separate columns by design. */
export interface LedgerLine {
  id: number;
  journal_entry_id: number;
  ledger_account_id: number;
  organization_id?: number | null;
  debit_amount: string | number;
  credit_amount: string | number;
  memo?: string | null;
  account?: LedgerAccount | null;
  organization?: { id: number; name_ar: string; name_en: string | null } | null;
}

/** A journal entry with its lines, as `ledger/journal-entries` returns it. */
export interface LedgerEntry {
  id: number;
  entry_number: string;
  transaction_reference?: string | null;
  currency_code: string;
  exchange_rate_to_base?: string | number | null;
  description?: string | null;
  entity_type?: string | null;
  entity_id?: number | null;
  created_by_user_id?: number | null;
  total_debit: string | number;
  total_credit: string | number;
  posted_at?: string | null;
  is_reversal?: boolean;
  reversal_of_entry_id?: number | null;
  created_at: string;
  lines?: LedgerLine[];
  createdByUser?: { id: number; name: string } | null;
}

/** `ledger/trial-balance`: the same accounts shown as debit/credit columns. */
export interface TrialBalance {
  accounts: {
    account_code: string;
    name_ar: string | null;
    name_en: string | null;
    type: string;
    debit: number;
    credit: number;
  }[];
  total_debit: number;
  total_credit: number;
  is_balanced: boolean;
  generated_at: string;
}

export interface SystemSetting {
  id: number;
  key: string;
  value: string | null;
  type: string;
  group: string;
  description_ar?: string | null;
  description_en?: string | null;
  default_value?: string | null;
  is_editable: boolean;
  is_public: boolean;
  organization_id?: number | null;
}

export interface AuditLog {
  id: number;
  organization_id?: number | null;
  user_id?: number | null;
  action: string;
  auditable_type?: string | null;
  auditable_id?: number | null;
  ip_address?: string | null;
  user_agent?: string | null;
  old_values?: Record<string, unknown> | null;
  new_values?: Record<string, unknown> | null;
  created_at: string;
  user?: { id: number; name: string; email: string } | null;
  organization?: { id: number; name_ar: string; name_en: string } | null;
}

export type AuditLogItem = AuditLog;

export interface AppNotification {
  id: number;
  title?: string | null;
  body?: string | null;
  data?: Record<string, unknown> | null;
  read_at?: string | null;
  created_at: string;
}

/**
 * A currency the platform can price in. `exchange_rate_to_base` is an admin
 * entry unless `rate_source` says otherwise, so a converted amount is only as
 * trustworthy as that row — and a deal keeps its own snapshot of the rate
 * rather than re-reading this table later.
 */
export interface Currency {
  id: number;
  iso_code: string;
  name_ar: string;
  name_en: string;
  symbol: string;
  decimal_places: number;
  exchange_rate_to_base: string;
  rate_source?: string | null;
  rate_updated_at?: string | null;
  rate_updated_by_user_id?: number | null;
  is_active: boolean;
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

/** One installed capability. Off means the routes answer 503, not that the UI hid them. */
export interface FeatureFlag {
  id: number;
  key: string;
  name_ar: string;
  name_en: string;
  description_ar?: string | null;
  description_en?: string | null;
  is_enabled: boolean;
  rules_json?: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
}

/** A person's place inside one organization — separate from their platform role. */
export interface UserMembership {
  id: number;
  organization_id: number;
  user_id: number;
  role: MembershipRole;
  is_active: boolean;
  joined_at: string;
  user?: Pick<User, 'id' | 'name' | 'email' | 'phone' | 'status'> | null;
}

/**
 * Credentials for an integration. The secret is returned once by the create
 * call and stored only as a hash afterwards. Note that no request path
 * authenticates a key yet, which is why the `api_keys` capability ships off.
 */
export interface ApiKey {
  id: number;
  organization_id: number;
  name: string;
  key_id: string;
  scopes: string[];
  status: 'ACTIVE' | 'REVOKED' | 'EXPIRED';
  last_used_at?: string | null;
  expires_at?: string | null;
  created_at: string;
}

/** A signed outbound event subscription. */
export interface Webhook {
  id: number;
  organization_id: number;
  endpoint_url: string;
  events: string[];
  is_active: boolean;
  failure_count: number;
  last_triggered_at?: string | null;
  created_at: string;
  /** Only present in the create response; never readable afterwards. */
  secret?: string;
  deliveries_count?: number;
}

/** One attempt to hand an event to a webhook endpoint. */
export interface WebhookDelivery {
  id: number;
  webhook_id: number;
  event_name: string;
  event_id: string;
  /**
   * BLOCKED means the endpoint resolved to an address this server refuses to
   * post to; the delivery was never attempted, which is not the same as a
   * receiver answering badly.
   */
  status: 'PENDING' | 'DELIVERED' | 'FAILED' | 'BLOCKED' | string;
  response_status?: number | null;
  response_body?: string | null;
  attempt?: number;
  last_error?: string | null;
  delivered_at?: string | null;
  created_at: string;
}

/**
 * A movement of stock, recorded against the offer whose quantities it changed.
 *
 * `previous_quantity` and `new_quantity` are that offer's available quantity
 * before and after: the platform keeps no separate per-warehouse stock count,
 * so these are the only two numbers on the row that mean anything.
 */
export interface InventoryMovement {
  id: number;
  organization_id: number;
  warehouse_id: number;
  product_variant_id: number;
  offer_id?: number | null;
  deal_id?: number | null;
  user_id?: number | null;
  type: 'IN' | 'RESERVED' | 'RELEASED' | 'SOLD' | 'ADJUSTED' | 'RETURNED';
  quantity: string | number;
  previous_quantity: string | number;
  new_quantity: string | number;
  reason?: string | null;
  created_at: string;
  warehouse?: { id: number; name: string; code?: string | null; city?: string | null } | null;
  product_variant?: { id: number; sku?: string | null; name_ar?: string | null; name_en?: string | null } | null;
  user?: { id: number; name: string } | null;
}

/**
 * One uploaded photograph of a product (`/catalog/products/{id}/images`).
 *
 * `url` is relative to the storage disk and served through `public/storage`, so
 * it only resolves when `php artisan storage:link` has been run on the server —
 * a missing symlink shows as a broken image, not as a wrong path here.
 */
export interface ProductImage {
  id: number;
  product_id: number;
  url: string | null;
  is_cover: boolean;
  sort_order: number;
  mime_type?: string | null;
  size_bytes?: number | null;
  width?: number | null;
  height?: number | null;
  original_name?: string | null;
  uploaded_at?: string | null;
}

/**
 * A question or remark on a listing, as the public thread returns it.
 *
 * The server builds this with `array_filter(..., !== null)`, so a key whose
 * value was null is **absent** rather than present-and-null. Read these fields
 * as optional even when the thread usually carries them.
 */
export interface OfferComment {
  id: number;
  offer_id: number;
  body: string;
  author_name?: string;
  organization_id?: number;
  /** The business that wrote it — shown, because an anonymous remark on a commercial listing is not actionable. */
  organization_name?: string;
  /** Written by the company that owns the listing, not necessarily underneath a question. */
  is_seller_reply?: boolean;
  /**
   * Set when this is the seller's own answer under a listing whose seller hides
   * their name: `author_name`, `organization_name` and `organization_id` are
   * then absent because the seller withheld them, not because they were never
   * recorded. Say that in words; do not render a blank as if it were a name.
   */
  identity_hidden?: boolean;
  status?: 'pending' | 'published' | 'removed' | string;
  report_count?: number;
  created_at?: string;
  replies?: OfferComment[];
}

/** The closed vocabulary `POST /marketplace/comments/{id}/report` accepts. */
export type CommentReportReason =
  | 'spam'
  | 'off_topic'
  | 'abusive'
  | 'contact_info'
  | 'misleading'
  | 'other';

/** `GET /community/summary` — what is waiting on platform staff, counted from the queues themselves. */
export interface CommunitySummary {
  pending_offers: number;
  pending_comments: number;
  reported_comments: number;
  open_reports: number;
  pending_documents: number;
  pending_organizations: number;
}

/** A thread row on the reviewer's desk (`GET /community/comments`). */
export interface ModeratedComment extends OfferComment {
  moderated_at?: string | null;
  moderation_reason?: string | null;
  moderator_name?: string | null;
  organization?: { id: number; name_ar: string; status: string } | null;
  offer?: {
    id: number;
    title_ar: string;
    status: string;
    seller_org_id: number;
    seller_name_ar?: string | null;
    seller_cr?: string | null;
    seller_vat?: string | null;
    seller_status?: string | null;
  } | null;
  reports: {
    id: number;
    reason: string;
    note?: string | null;
    status: string;
    by_organization?: string | null;
    reported_by?: string | null;
  }[];
}

/** A report row (`GET /community/reports`). */
export interface CommunityReport {
  id: number;
  offer_comment_id: number;
  reason: CommentReportReason | string;
  note?: string | null;
  status: 'open' | 'upheld' | 'dismissed' | string;
  reviewed_by?: number | null;
  reviewed_at?: string | null;
  created_at?: string;
  comment?: { id: number; offer_id: number; body: string; status: string; report_count?: number } | null;
  reporter?: { name: string } | null;
  organization?: { name_ar: string } | null;
  reviewer?: { name: string } | null;
}

/**
 * A legal paper a business filed, and the platform's decision on that copy.
 *
 * `accepted` records that a member of this platform's staff read the file. No
 * official register is queried anywhere in the product, so no label may say a
 * number was confirmed by a ministry (§47).
 */
export interface OrganizationDocument {
  id: number;
  organization_id: number;
  type:
    | 'commercial_registration'
    | 'tax_certificate'
    | 'trade_license'
    | 'founding_statute'
    | 'id_proof'
    /** The national ID card, photographed as two separate filings. */
    | 'id_card_front'
    | 'id_card_back'
    | 'other';
  reference_number?: string | null;
  status: 'pending' | 'accepted' | 'rejected';
  original_name?: string | null;
  mime_type?: string | null;
  size_bytes?: number | null;
  url: string | null;
  uploaded_at?: string;
  uploaded_by_name?: string | null;
  reviewed_at?: string | null;
  reviewed_by_name?: string | null;
  review_reason?: string | null;
}

/** How long a pending copy has been waiting, and which papers are already accepted. */
export interface DocumentQueueRow extends OrganizationDocument {
  waiting_hours?: number | null;
  organization?: {
    id: number;
    name_ar: string;
    name_en: string | null;
    type: OrganizationType;
    status: OrganizationStatus;
    city: string | null;
    country_code: string;
    registration_no: string | null;
    tax_no: string | null;
    kyc_verified_at: string | null;
    accepted_types: string[];
  } | null;
}

/** `GET /organizations/document-summary` — counts the server took itself. */
export interface DocumentReviewSummary {
  by_status: { pending: number; accepted: number; rejected: number };
  organizations_with_pending_documents: number;
}

/**
 * What a reviewer needs that is not on the listing itself.
 *
 * `GET /marketplace/moderation-queue` attaches this to every row. The business
 * behind an offer is part of the decision on it, so the console renders it
 * rather than sending the reviewer to three other screens.
 *
 * `kyc_verified_at` and `reviewed_at` are timestamps of this platform's own
 * staff decisions. No government register is read anywhere in this product, so
 * neither field — and no label built on it — may claim an official check (§47).
 */
export interface OfferModerationContext {
  seller_legal_record?: {
    organization_id: number;
    name_ar: string;
    type: OrganizationType;
    country_code: string;
    city: string | null;
    platform_status: OrganizationStatus | string;
    registration_no: string | null;
    tax_no: string | null;
    reviewed_at: string | null;
    website: string | null;
    phone: string | null;
    member_count: number;
  } | null;
  documents?: OfferModerationDocument[];
  seller_history?: {
    offers_total: number;
    offers_active: number;
    offers_expired: number;
    deals_total: number;
    deals_settled: number;
    deals_disputed: number;
  } | null;
  waiting_since?: string | null;
  waiting_hours?: number | null;
  image_count?: number;
  warehouse?: {
    id: number;
    name: string;
    city: string | null;
    code: string | null;
    lat: number | string | null;
    lon: number | string | null;
  } | null;
}

export interface OfferModerationDocument {
  id: number;
  type: OrganizationDocument['type'];
  status: OrganizationDocument['status'];
  reference_number?: string | null;
  original_name?: string | null;
  url: string | null;
  uploaded_at?: string | null;
  reviewed_at?: string | null;
  reviewed_by?: string | null;
  review_reason?: string | null;
}

/** One line of the checklist the verification gate sends back with its refusal. */
export interface VerificationChecklistItem {
  key: string;
  label_ar: string;
  /** Required by the operator's `legal.require_*` settings, not by this screen. */
  required: boolean;
  done: boolean;
}

/** The `details` object of an `ORGANIZATION_NOT_VERIFIED` error. */
export interface VerificationGateDetails {
  required_action?: string;
  organization_status?: OrganizationStatus | string;
  checklist?: VerificationChecklistItem[];
}

/**
 * The single client for the HASSAL API.
 *
 * Every method below maps to a route that exists on the server — the list was
 * taken from `php artisan route:list --path=api/v1`, not from what a screen
 * would like to believe. That constraint is deliberate: a method that points at
 * a missing endpoint looks identical to a working one until somebody clicks it,
 * and then the failure lands on a real buyer mid-purchase. So when a capability
 * is not wired on the server (recording payments is the obvious one), there is
 * no method for it here and the UI must say the operation is unavailable rather
 * than pretend.
 *
 * Envelope (`App\Http\Responses\ApiResponse`):
 *   success   → { success, message, data, meta }
 *   paginated → { success, message, data: [...], pagination, meta }
 *   error     → { success: false, error_code, error: { code, message, details }, meta }
 */

import type {
  ActiveOrganization,
  ApiKey,
  AuditLog,
  Brand,
  Category,
  CommunityReport,
  CommunitySummary,
  CommentReportReason,
  Currency,
  Deal,
  Demand,
  DemandMatch,
  Dispute,
  DocumentQueueRow,
  DocumentReviewSummary,
  FeatureFlag,
  InventoryMovement,
  LedgerAccount,
  LedgerEntry,
  ManagedUser,
  MembershipRoleOption,
  ModeratedComment,
  OfferComment,
  Organization,
  OrganizationDocument,
  OrganizationStatus,
  OrganizationSummary,
  Offer,
  OfferStatus,
  PlatformRoleOption,
  ProfileCompletion,
  Product,
  ProductImage,
  PurchaseRequest,
  Quotation,
  Reservation,
  SystemSetting,
  User,
  UserMembership,
  VerificationGateDetails,
  Webhook,
  WebhookDelivery,
  Warehouse,
} from '../types';
import { getLocale, LOCALE_KEY, type Locale } from './i18n';

const TOKEN_KEY = 'hassal_auth_token';
const TENANT_KEY = 'hassal_active_tenant_id';

export interface Pagination {
  current_page: number;
  per_page: number;
  total_items: number;
  total_pages: number;
  has_more: boolean;
}

/** One decoded API response, whatever the server returned. */
export interface ApiResult<T = unknown> {
  success: boolean;
  message?: string;
  data?: T;
  pagination?: Pagination;
  error_code?: string;
  /**
   * On a failure this carries `{ code, message, details }`, and `details` is
   * where validation errors live — keyed by field, each an array of messages.
   * There is no top-level Laravel `errors` object in this API's envelope.
   */
  error?: { code?: string; message?: string; details?: Record<string, string[]> };
  /**
   * Always `timestamp` + `correlation_id`; endpoints add their own facts here,
   * e.g. `in_catalog` on a settings write.
   */
  meta?: { timestamp?: string; correlation_id?: string } & Record<string, unknown>;
}

/** The first message the server sent for one field, or undefined. */
export function fieldError<T>(result: ApiResult<T>, field: string): string | undefined {
  const messages = result.error?.details?.[field];
  return Array.isArray(messages) ? messages[0] : undefined;
}

/**
 * The checklist a verification refusal arrived with, or `null`.
 *
 * A screen that hardcoded "upload your registration certificate" would be wrong
 * the moment an operator turned that requirement off, so the refusal carries
 * which items the platform actually requires and which are done.
 */
export function verificationGate<T>(result: ApiResult<T>): VerificationGateDetails | null {
  if (result.error_code !== 'ORGANIZATION_NOT_VERIFIED') return null;
  return (result.error?.details as VerificationGateDetails | undefined) ?? null;
}

/** Sort intents accepted by `GET /marketplace/offers`. */
export type OfferSortIntent =
  | 'newest'
  | 'cheapest'
  | 'priciest'
  | 'expiring'
  | 'quantity'
  | 'nearest_to_expiry';

export interface OfferFilters {
  category_id?: number;
  opportunity_reason?: string;
  city?: string;
  min_price?: number;
  max_price?: number;
  max_moq?: number;
  currency_code?: string;
  delivery_terms?: 'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH';
  search?: string;
  sort_by?: OfferSortIntent;
  per_page?: number;
  page?: number;
}

/**
 * Filters for the two authenticated listings.
 *
 * `GET /marketplace/offers` is registered outside the authenticated group, so
 * it has no idea who is asking and cannot carry a "mine" flag. The seller's own
 * shelf and the reviewer's queue are separate routes (`my-offers`,
 * `moderation-queue`), each gated by its own permission; both take the filters
 * above plus the status they want listed.
 */
export interface ShelfFilters extends OfferFilters {
  status?: OfferStatus;
}

export interface NearbyFilters {
  radius_km?: number;
  category_id?: number;
  opportunity_reason?: string;
  min_price?: number;
  max_price?: number;
  min_quantity?: number;
  max_moq?: number;
  currency_code?: string;
  delivery_terms?: 'BUYER_PICKUP' | 'SELLER_DELIVERY' | 'BOTH';
  sort_by?: 'distance' | 'price' | 'freshness';
  per_page?: number;
}

/** What `GET /marketplace/offers/nearby` answers: an object, not a bare list. */
export interface NearbyResult {
  offers: Offer[];
  search_center: { latitude: number; longitude: number };
  radius_km: number;
  total_found: number;
}

export type DeliveryPreference = 'PICKUP' | 'DELIVERY';

/**
 * What every door that signs a session in returns inside `data`:
 * `/auth/login`, `/auth/register`, `/auth/google`.
 *
 * `active_organization` is the first membership the server found, not a choice
 * the buyer made — and it is the only organization key that exists. The old
 * `organization` sibling was a leftover from the days registration created a
 * business; keeping it here would invite a screen to read a field the API never
 * sends, which reads as "no organization" forever.
 */
export interface AuthSession {
  token: string;
  user?: User;
  active_organization?: Organization | null;
  organizations?: OrganizationSummary[];
  profile_completion?: ProfileCompletion;
}

/** The buyer's ask, always raised from a match the engine produced. */
export interface PurchaseRequestFromMatch {
  requested_quantity?: number;
  delivery_preference?: DeliveryPreference;
  notes?: string;
}

export interface ProposedTermsInput {
  quantity?: number;
  unit_price?: number;
  currency_code?: string;
}

/** Turn a value into `undefined` so the query string stays clean. */
function qs(params: Record<string, unknown>): string {
  const search = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue;
    search.set(key, String(value));
  }
  const encoded = search.toString();
  return encoded ? `?${encoded}` : '';
}

/**
 * A key that survives a retried click but not a second, genuine action.
 * The server stores it with the response, so replaying a dropped request
 * returns the original result instead of taking stock twice.
 */
function idempotencyKey(prefix: string): string {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).slice(2, 10)}`;
}

class ApiClient {
  private token: string | null = null;
  private tenantId: number | null = null;

  constructor() {
    if (typeof window !== 'undefined') {
      this.token = localStorage.getItem(TOKEN_KEY);
      const savedTenant = localStorage.getItem(TENANT_KEY);
      this.tenantId = savedTenant ? parseInt(savedTenant, 10) : null;
    }
  }

  setToken(token: string | null) {
    this.token = token;
    if (typeof window !== 'undefined') {
      if (token) localStorage.setItem(TOKEN_KEY, token);
      else localStorage.removeItem(TOKEN_KEY);
    }
  }

  getToken(): string | null {
    return this.token;
  }

  setTenantId(tenantId: number | null) {
    this.tenantId = tenantId;
    if (typeof window !== 'undefined') {
      if (tenantId) localStorage.setItem(TENANT_KEY, tenantId.toString());
      else localStorage.removeItem(TENANT_KEY);
    }
  }

  getTenantId(): number | null {
    return this.tenantId;
  }

  setLocale(locale: Locale) {
    if (typeof window !== 'undefined') localStorage.setItem(LOCALE_KEY, locale);
  }

  private async request<T = any>(
    path: string,
    options: {
      method?: string;
      body?: unknown;
      /**
       * Files go out as multipart, which is the one request shape this client
       * must not stamp `Content-Type: application/json` onto — the boundary is
       * generated by the browser and the server reads it to split the parts.
       */
      form?: FormData;
      headers?: Record<string, string>;
      idempotencyKey?: string;
    } = {}
  ): Promise<ApiResult<T>> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'Accept-Language': getLocale(),
      ...(options.form ? {} : { 'Content-Type': 'application/json' }),
      ...(options.headers || {}),
    };

    if (this.token) headers['Authorization'] = `Bearer ${this.token}`;
    // The active organization is a claim this client makes on every call; the
    // server re-checks membership rather than trusting it.
    if (this.tenantId) headers['X-Organization-Id'] = this.tenantId.toString();
    if (options.idempotencyKey) headers['X-Idempotency-Key'] = options.idempotencyKey;

    const res = await fetch(path, {
      method: options.method || 'GET',
      headers,
      body: options.form ?? (options.body !== undefined ? JSON.stringify(options.body) : undefined),
    });

    const json = (await res.json().catch(() => null)) as ApiResult<T> | null;

    if (!res.ok || json?.success === false) {
      return {
        ...(json || {}),
        success: false,
        message:
          json?.error?.message ||
          json?.message ||
          `تعذّر تنفيذ الطلب (${res.status})`,
        error_code: json?.error_code || json?.error?.code,
      };
    }

    return json ?? { success: false, message: 'استجابة غير صالحة من الخادم' };
  }

  // ── Auth & session ────────────────────────────────────────────────────────
  /**
   * Adopt a session the server just handed back.
   *
   * The tenant header is set from `active_organization` and cleared when it is
   * absent. Both halves matter: a leftover id from a previous session would
   * silently attribute this person's actions to somebody else's business, which
   * is why "no organization" is a write, not a skip.
   */
  private adoptSession(session: AuthSession | undefined): void {
    if (!session?.token) return;
    this.setToken(session.token);
    this.setTenantId(session.active_organization?.id ?? null);
  }

  /**
   * Open an account: a person, nothing else.
   *
   * There is no organization key here because the server refuses them — a
   * business is created on its own through `POST /organizations`, where the
   * legal-record rules, the duplicate-register check and the per-account ceiling
   * are applied. Registration is the one door shared by password and Google
   * accounts, so it asks what both kinds can answer.
   */
  async register(data: {
    name: string;
    email: string;
    phone?: string;
    national_id?: string;
    password: string;
    password_confirmation: string;
  }) {
    const res = await this.request<AuthSession>('/api/v1/auth/register', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('reg'),
    });
    if (res.success) this.adoptSession(res.data);
    return res;
  }

  async login(login: string, password: string) {
    const res = await this.request<AuthSession>('/api/v1/auth/login', {
      method: 'POST',
      body: { login, password },
    });
    if (res.success) this.adoptSession(res.data);
    return res;
  }

  /**
   * Exchange a Google ID token for a session.
   *
   * `nonce` is the random string this browser generated *before* it drew the
   * button, and it is passed to Google Identity Services at initialization so
   * Google signs it into the token. Sending it back lets the server prove the
   * token was minted for this attempt rather than carried in from an earlier
   * one — the check is required in both directions, so a token with a nonce and
   * no claim presented, or the reverse, is refused.
   */
  async googleSignIn(credential: string, nonce: string) {
    const res = await this.request<AuthSession>('/api/v1/auth/google', {
      method: 'POST',
      body: { credential, nonce },
    });
    if (res.success) this.adoptSession(res.data);
    return res;
  }

  async forgotPassword(email: string) {
    return this.request('/api/v1/auth/forgot-password', { method: 'POST', body: { email } });
  }

  async resetPassword(data: { email: string; token: string; password: string; password_confirmation: string }) {
    return this.request('/api/v1/auth/reset-password', { method: 'POST', body: data });
  }

  async logout() {
    await this.request('/api/v1/auth/logout', { method: 'POST' });
    this.setToken(null);
    this.setTenantId(null);
  }

  async getMe() {
    return this.request<{
      user: User;
      current_organization?: ActiveOrganization | null;
      organizations?: OrganizationSummary[];
      profile_completion?: ProfileCompletion;
    }>('/api/v1/auth/me');
  }

  /** The server validates the membership before honouring this. */
  async switchOrganization(orgId: number) {
    const res = await this.request<{ organization: OrganizationSummary }>('/api/v1/auth/switch-organization', {
      method: 'POST',
      body: { organization_id: orgId },
    });
    if (res.success) this.setTenantId(orgId);
    return res;
  }

  /**
   * The person's own details — nothing else. `email` is not accepted here: a
   * form that moved an account's login identity would hand whoever typed into
   * it the next owner's password resets. It is also not the business record;
   * that is `PUT /organizations/{id}` behind `organizations.manage`.
   */
  async updateProfile(data: { name?: string; phone?: string | null; national_id?: string | null }) {
    return this.request<Pick<User, 'id' | 'name' | 'email' | 'phone' | 'national_id'>>('/api/v1/auth/profile', {
      method: 'PATCH',
      body: data,
      idempotencyKey: idempotencyKey('profile'),
    });
  }

  /**
   * Re-hashes the password and revokes every other token on the account.
   *
   * The token in this browser survives because the server excludes the access
   * token that made the call — but any second device is signed out by this
   * action, so a screen that offers it has to say so.
   */
  async changePassword(data: { current_password: string; password: string; password_confirmation: string }) {
    return this.request('/api/v1/auth/password', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('pwd'),
    });
  }

  // ── Bootstrap & health ────────────────────────────────────────────────────
  async getBootstrap() {
    return this.request<Record<string, any>>('/api/v1/bootstrap');
  }

  async getHealth() {
    return this.request<Record<string, any>>('/api/v1/health');
  }

  // ── Notifications ─────────────────────────────────────────────────────────
  async getNotifications(page?: number) {
    return this.request('/api/v1/notifications' + qs({ page }));
  }

  async getUnreadNotificationCount() {
    return this.request<{ count: number }>('/api/v1/notifications/unread-count');
  }

  async markNotificationRead(id: number) {
    return this.request(`/api/v1/notifications/${id}/read`, { method: 'POST' });
  }

  async markAllNotificationsRead() {
    return this.request('/api/v1/notifications/read-all', { method: 'POST' });
  }

  // ── Catalog ───────────────────────────────────────────────────────────────
  async getCategories() {
    return this.request<Category[]>('/api/v1/marketplace/categories');
  }

  async getBrands() {
    return this.request<Brand[]>('/api/v1/marketplace/brands');
  }

  async getProducts(filters: { category_id?: number; search?: string; page?: number } = {}) {
    return this.request<Product[]>('/api/v1/catalog/products' + qs({ ...filters }));
  }

  async createProduct(product: Record<string, unknown>) {
    return this.request<Product>('/api/v1/catalog/products', {
      method: 'POST',
      body: product,
      idempotencyKey: idempotencyKey('prd'),
    });
  }

  async getProductVariants(productId: number) {
    return this.request(`/api/v1/catalog/products/${productId}/variants`);
  }

  /**
   * Edit this organization's own product record.
   *
   * `sku` is not in the editable set — the scanned code belongs to the variant,
   * and a product-level edit that could rewrite it would silently split a
   * barcode across the platform. A reference the platform published
   * (`organization_id` null) answers `PLATFORM_REFERENCE_NOT_EDITABLE`, and
   * another tenant's product answers `PRODUCT_NOT_OWNED` rather than a listing
   * of what exists.
   */
  async updateProduct(id: number, data: Record<string, unknown>) {
    return this.request<Product>(`/api/v1/catalog/products/${id}`, { method: 'PUT', body: data });
  }

  // ── Product photographs ───────────────────────────────────────────────────
  /**
   * The media set as stored rows, ordered as a buyer should see it.
   *
   * This is not `product.image_url`: that legacy column has no writer anywhere
   * in the application, and a screen that reads it shows a blank next to
   * listings that have real photographs attached.
   */
  async getProductImages(productId: number) {
    return this.request<ProductImage[]>(`/api/v1/catalog/products/${productId}/images`);
  }

  /**
   * Upload one or more photographs for a product.
   *
   * A batch is the normal case — the pallet, the carton, the label — and the
   * server answers with both halves: what it stored and what it refused with
   * the reason. Only the accepted subset is a success, so a caller that ignores
   * `rejected` would tell the seller eight files became eight photographs when
   * two of them were HEIC.
   */
  async uploadProductImages(productId: number, files: File[]) {
    const form = new FormData();
    files.forEach((file) => form.append('images[]', file, file.name));

    return this.request<{ images: ProductImage[]; rejected: { file: string; reason: string }[] }>(
      `/api/v1/catalog/products/${productId}/images`,
      { method: 'POST', form, idempotencyKey: idempotencyKey('prd_img') }
    );
  }

  /** Set the cover, or the position a buyer scrolls to. */
  async updateProductImage(productId: number, imageId: number, data: { is_cover?: boolean; sort_order?: number }) {
    return this.request<ProductImage>(`/api/v1/catalog/products/${productId}/images/${imageId}`, {
      method: 'PUT',
      body: data,
    });
  }

  /** Removes the stored file with the row, and moves the cover to the next photograph. */
  async deleteProductImage(productId: number, imageId: number) {
    return this.request<{ id: number; deleted: boolean }>(
      `/api/v1/catalog/products/${productId}/images/${imageId}`,
      { method: 'DELETE' }
    );
  }

  // ── Warehouses ────────────────────────────────────────────────────────────
  /** Warehouses of the active organization, including unlisted ones. */
  async getWarehouses() {
    return this.request<Warehouse[]>('/api/v1/warehouses');
  }

  /** Public discovery list: only locations that hold active offers. */
  async getPublicWarehouses(filters: { city?: string; search?: string } = {}) {
    return this.request<Warehouse[]>('/api/v1/marketplace/warehouses' + qs({ ...filters }));
  }

  async createWarehouse(warehouse: Partial<Warehouse>) {
    return this.request<Warehouse>('/api/v1/warehouses', {
      method: 'POST',
      body: warehouse,
      idempotencyKey: idempotencyKey('wh'),
    });
  }

  /**
   * Edit a location of the organization in context; another tenant's id reads
   * as `WAREHOUSE_NOT_FOUND`, which is what keeps the endpoint from confirming
   * how many warehouses a rival operates.
   *
   * `latitude`/`longitude` are required on an update exactly as on a create, so
   * a form that could not read the browser's location must resend the stored
   * pair rather than 0,0 — the server's bounds are WGS84 (-90..90, -180..180),
   * and 0,0 is inside them. It would save a warehouse in the Atlantic.
   */
  async updateWarehouse(id: number, warehouse: Partial<Warehouse>) {
    return this.request<Warehouse>(`/api/v1/warehouses/${id}`, { method: 'PUT', body: warehouse });
  }

  // ── Offers ────────────────────────────────────────────────────────────────
  async getOffers(filters: OfferFilters = {}) {
    return this.request<Offer[]>(
      '/api/v1/marketplace/offers' + qs({ ...filters })
    );
  }

  /**
   * The seller's own shelf: this business's offers in every status, including
   * drafts and listings waiting on a reviewer. Authenticated, and scoped by the
   * server to the organization in context — a shelf cannot be asked for by id.
   */
  async getMyOffers(filters: ShelfFilters = {}) {
    return this.request<Offer[]>('/api/v1/marketplace/my-offers' + qs({ ...filters }));
  }

  /**
   * The review queue: unpublished listings from every business, defaults to
   * what is awaiting a decision. Gated by `offers.moderate` at the route, so a
   * seller holding a token receives a refusal here rather than an empty list.
   */
  async getModerationQueue(filters: ShelfFilters = {}) {
    return this.request<Offer[]>('/api/v1/marketplace/moderation-queue' + qs({ ...filters }));
  }

  /**
   * Offers within a radius of real coordinates. Returns the whole search result
   * — centre, applied radius and count — because a list of offers without the
   * circle it came from is not a discovery answer, it is an unexplained one.
   */
  async getNearbyOffers(lat: number, lon: number, filters: NearbyFilters = {}) {
    return this.request<NearbyResult>(
      '/api/v1/marketplace/offers/nearby' + qs({ latitude: lat, longitude: lon, ...filters })
    );
  }

  async getOffer(id: number) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}`);
  }

  async createOffer(offer: Record<string, unknown>) {
    return this.request<Offer>('/api/v1/marketplace/offers', {
      method: 'POST',
      body: offer,
      idempotencyKey: idempotencyKey('off'),
    });
  }

  async updateOffer(id: number, offer: Record<string, unknown>) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}`, {
      method: 'PUT',
      body: offer,
      idempotencyKey: idempotencyKey('off_up'),
    });
  }

  /** DRAFT → PENDING_REVIEW. Publication is an admin act, not a seller's. */
  async submitOffer(id: number) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}/submit`, {
      method: 'POST',
      idempotencyKey: idempotencyKey('off_sub'),
    });
  }

  /**
   * The reviewer's decision. The server takes `APPROVE` or `RETURN` and refuses
   * a `RETURN` with no reason, because an unexplained return cannot be acted on
   * by the seller it was sent back to.
   */
  async reviewOffer(id: number, decision: 'APPROVE' | 'RETURN', reason?: string) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}/review`, {
      method: 'PUT',
      body: { decision, ...(reason ? { reason } : {}) },
      idempotencyKey: idempotencyKey('off_rev'),
    });
  }

  async setOfferStatus(id: number, status: OfferStatus, reason?: string) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}/status`, {
      method: 'PUT',
      body: { status, ...(reason ? { reason } : {}) },
      idempotencyKey: idempotencyKey('off_st'),
    });
  }

  async deleteOffer(id: number) {
    return this.request(`/api/v1/marketplace/offers/${id}`, { method: 'DELETE' });
  }

  // ── Marketplace community: interest and questions ─────────────────────────
  /**
   * "This business is interested in this listing."
   *
   * One row per (offer, account, organization), so the same person acting for
   * two companies produces two signals from two companies and re-liking cannot
   * inflate a number. There is no star, score or verdict anywhere in this
   * section — the platform has never computed one, so no screen may show one.
   *
   * Refused before a reviewer accepts the business's papers
   * (`ORGANIZATION_NOT_VERIFIED`), and only an ACTIVE listing accepts interest.
   */
  async likeOffer(id: number) {
    return this.request<{ liked: boolean; likes_count: number; created: boolean }>(
      `/api/v1/marketplace/offers/${id}/like`,
      { method: 'POST', idempotencyKey: idempotencyKey(`like_${id}`) }
    );
  }

  async unlikeOffer(id: number) {
    return this.request<{ liked: boolean; likes_count: number; removed: boolean }>(
      `/api/v1/marketplace/offers/${id}/like`,
      { method: 'DELETE' }
    );
  }

  /**
   * Which listings the signed-in account already marked, for the organization
   * in context.
   *
   * Needed because `GET /marketplace/offers` answers the same way with or
   * without a token — it cannot say "you liked this" without becoming a
   * different response per visitor. A list loads once and fills its buttons in
   * from here.
   */
  async getLikedOfferIds() {
    return this.request<{ offer_ids: number[] }>('/api/v1/marketplace/liked-offers');
  }

  /** The public thread: published questions, each with its published replies. */
  async getOfferComments(offerId: number) {
    return this.request<{ offer_id: number; comments: OfferComment[] }>(
      `/api/v1/marketplace/offers/${offerId}/comments`
    );
  }

  /**
   * Ask a question on a listing. A reply to a reply flattens onto the question
   * it answers, and with pre-moderation switched on the row comes back
   * `pending` — so the message the server chose ("سيظهر بعد المراجعة") is the
   * one to show, not a locally composed "posted".
   */
  async commentOnOffer(offerId: number, data: { body: string; parent_id?: number }) {
    return this.request<OfferComment>(`/api/v1/marketplace/offers/${offerId}/comments`, {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`cmt_${offerId}`),
    });
  }

  /** Withdraws the comment and everything under it; the row survives as `removed`. */
  async deleteOfferComment(id: number) {
    return this.request<{ id: number; status: string }>(`/api/v1/marketplace/comments/${id}`, {
      method: 'DELETE',
    });
  }

  /**
   * Ask platform staff to look at a comment.
   *
   * A report hides nothing by itself — the reporter is one party to the
   * disagreement, and letting an accusation act as a verdict would turn the
   * queue into a takedown service. A repeat report from the same actor is
   * acknowledged, not counted.
   */
  async reportOfferComment(id: number, data: { reason: CommentReportReason; note?: string }) {
    return this.request<{ comment_id: number; report_count: number; recorded: boolean }>(
      `/api/v1/marketplace/comments/${id}/report`,
      { method: 'POST', body: data, idempotencyKey: idempotencyKey(`rpt_${id}`) }
    );
  }

  // ── Reservations (a hold the seller made, which the buyer may release) ─────
  async getReservations(filters: { status?: string; page?: number } = {}) {
    return this.request<Reservation[]>('/api/v1/marketplace/reservations' + qs({ ...filters }));
  }

  async cancelReservation(id: number, reason?: string) {
    return this.request<Reservation>(`/api/v1/marketplace/reservations/${id}/cancel`, {
      method: 'POST',
      body: { reason },
      idempotencyKey: idempotencyKey(`res_cxl_${id}`),
    });
  }

  // ── Purchase requests (buyer's ask → seller's review) ─────────────────────
  /**
   * The marketplace fast path: an offer becomes an ask.
   *
   * There is deliberately no client call that reserves stock directly — a hold
   * is what `acceptPurchaseRequest()` produces, because §19–§26 put the seller's
   * decision between finding a listing and taking something off their shelf.
   */
  async createPurchaseRequestFromOffer(data: {
    offer_id: number;
    requested_quantity: number;
    delivery_preference?: 'PICKUP' | 'DELIVERY';
    notes?: string;
  }) {
    return this.request<PurchaseRequest>('/api/v1/purchase-requests', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`pr_offer_${data.offer_id}`),
    });
  }

  async getPurchaseRequests(page?: number) {
    return this.request<PurchaseRequest[]>('/api/v1/purchase-requests' + qs({ page }));
  }

  async getPurchaseRequest(id: number) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}`);
  }

  /** The demand-driven path: one of this buyer's matches becomes an ask. */
  async createPurchaseRequestFromMatch(matchId: number, data: PurchaseRequestFromMatch = {}) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/matches/${matchId}`, {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`pr_${matchId}`),
    });
  }

  /** Seller acceptance: this is the moment stock is held. */
  async acceptPurchaseRequest(id: number, notes?: string) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/accept`, {
      method: 'POST',
      body: { notes },
      idempotencyKey: idempotencyKey(`pr_acc_${id}`),
    });
  }

  async rejectPurchaseRequest(id: number, reason: string) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/reject`, {
      method: 'POST',
      body: { reason },
      idempotencyKey: idempotencyKey(`pr_rej_${id}`),
    });
  }

  /** Seller counter-offer. Recorded as proposed terms; the buyer's ask is not rewritten. */
  async proposePurchaseRequest(id: number, proposed_terms: ProposedTermsInput, notes?: string) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/propose`, {
      method: 'POST',
      body: { proposed_terms, notes },
      idempotencyKey: idempotencyKey(`pr_prop_${id}`),
    });
  }

  /** Buyer adopts the counter-offer, which sends it back for seller acceptance. */
  async acceptProposal(id: number) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/accept-proposal`, {
      method: 'POST',
      idempotencyKey: idempotencyKey(`pr_ap_${id}`),
    });
  }

  async cancelPurchaseRequest(id: number, reason?: string) {
    return this.request<PurchaseRequest>(`/api/v1/purchase-requests/${id}/cancel`, {
      method: 'POST',
      body: { reason },
      idempotencyKey: idempotencyKey(`pr_cxl_${id}`),
    });
  }

  // ── Deals ─────────────────────────────────────────────────────────────────
  async getDeals(filters: { perspective?: 'buyer' | 'seller' | 'all'; status?: string; search?: string; page?: number } = {}) {
    return this.request<Deal[]>('/api/v1/deals' + qs({ ...filters }));
  }

  async getDeal(id: number) {
    return this.request<Deal>(`/api/v1/deals/${id}`);
  }

  /** A deal is formed against held stock, never straight from an offer. */
  async createDealFromReservation(data: {
    reservation_id: number;
    delivery_method: 'PICKUP' | 'SELLER_DELIVERY' | 'THIRD_PARTY_CARRIER';
    delivery_address?: string;
    notes?: string;
  }) {
    return this.request<Deal>('/api/v1/deals', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('deal'),
    });
  }

  /**
   * Move a deal through the state machine. The server decides whether this
   * actor, in this state, with this capability configured, may ask for that
   * state — a button being visible here is not an entitlement.
   */
  async transitionDeal(id: number, status: string, notes?: string) {
    return this.request<Deal>(`/api/v1/deals/${id}/transition`, {
      method: 'POST',
      body: { status, notes },
      idempotencyKey: idempotencyKey(`deal_t_${id}_${status}`),
    });
  }

  // ── Demands, matches and quotations ───────────────────────────────────────
  async getDemands(filters: { my_demands?: 1; category_id?: number; city?: string; search?: string; page?: number } = {}) {
    return this.request<Demand[]>('/api/v1/demands' + qs({ ...filters }));
  }

  async createDemand(demand: {
    title_ar: string;
    title_en?: string;
    description?: string;
    category_id?: number;
    target_quantity: number;
    unit: string;
    target_unit_price?: number;
    currency_code?: string;
    target_delivery_date?: string;
    delivery_city: string;
    delivery_latitude?: number;
    delivery_longitude?: number;
    special_requirements?: string;
    expires_at?: string;
  }) {
    return this.request<Demand>('/api/v1/demands', {
      method: 'POST',
      body: demand,
      idempotencyKey: idempotencyKey('dmd'),
    });
  }

  /** Re-runs the matching engine, so this is a live read, not a cached list. */
  async getDemandMatches(demandId: number) {
    return this.request<{ demand: Demand; matches: DemandMatch[]; total: number }>(
      `/api/v1/demands/${demandId}/matches`
    );
  }

  async submitQuotation(demandId: number, data: {
    quoted_quantity: number;
    unit_price: number;
    currency_code?: string;
    validity_days?: number;
    notes?: string;
  }) {
    return this.request<Quotation>(`/api/v1/demands/${demandId}/quotations`, {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`q_${demandId}`),
    });
  }

  // ── Disputes ──────────────────────────────────────────────────────────────
  async getDisputes(filters: { status?: string; page?: number } = {}) {
    return this.request<Dispute[]>('/api/v1/disputes' + qs({ ...filters }));
  }

  async openDispute(data: {
    deal_id: number;
    reason: string;
    description: string;
    claimed_amount: number;
    evidence_urls?: string[];
  }) {
    return this.request<Dispute>('/api/v1/disputes', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`dsp_${data.deal_id}`),
    });
  }

  /** SuperAdmin only, and the decision is recorded with its reason. */
  async resolveDispute(id: number, data: {
    decision: 'RESOLVED_REFUND' | 'RESOLVED_RELEASE' | 'RESOLVED_PARTIAL' | 'REJECTED';
    refunded_amount?: number;
    notes: string;
  }) {
    return this.request<Dispute>(`/api/v1/disputes/${id}/resolve`, {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey(`dsp_res_${id}`),
    });
  }

  // ── Community moderation (platform staff; `community.moderate`) ───────────
  /**
   * What is waiting on a reviewer, in one call.
   *
   * The numbers are counted from the same tables the queues below read, so a
   * badge can never advertise work that opening the queue does not contain —
   * which is the failure mode of a hand-maintained counter.
   */
  async getCommunitySummary() {
    return this.request<CommunitySummary>('/api/v1/community/summary');
  }

  /**
   * The comment queue.
   *
   * `reported` is not a status: it is published comments somebody flagged,
   * ordered by how many flagged them. It is the queue that matters when
   * pre-moderation is off, and the server will not invent a fourth state.
   */
  async getCommunityComments(filters: { status?: 'pending' | 'published' | 'removed' | 'reported'; perPage?: number } = {}) {
    return this.request<ModeratedComment[]>('/api/v1/community/comments' + qs({ ...filters }));
  }

  /**
   * Publish, hold or remove one comment. A removal takes a reason from the
   * server's fixed vocabulary, because an appeal has to have something to
   * point at. Publishing a question does not publish the replies held under it.
   */
  async moderateComment(id: number, data: { action: 'publish' | 'remove' | 'pending'; reason?: string }) {
    return this.request<ModeratedComment>(`/api/v1/community/comments/${id}/moderate`, {
      method: 'PUT',
      body: data,
      idempotencyKey: idempotencyKey(`mod_${id}`),
    });
  }

  async getCommunityReports(filters: { status?: 'open' | 'upheld' | 'dismissed' } = {}) {
    return this.request<CommunityReport[]>('/api/v1/community/reports' + qs({ ...filters }));
  }

  /**
   * Decide a report. Upholding one does nothing on its own unless the reviewer
   * also takes the comment down, which is why `remove_comment` and its reason
   * are part of this single decision rather than a second call someone may
   * forget to make.
   */
  async reviewCommunityReport(id: number, data: {
    action: 'upheld' | 'dismissed';
    remove_comment?: boolean;
    reason?: string;
  }) {
    return this.request<{ report_id: number; status: string; comment_status?: string | null }>(
      `/api/v1/community/reports/${id}/review`,
      { method: 'POST', body: data, idempotencyKey: idempotencyKey(`rpt_rev_${id}`) }
    );
  }

  // ── Ledger & reconciliation (both flag-gated on the server) ───────────────
  async getLedgerAccounts() {
    return this.request<LedgerAccount[]>('/api/v1/ledger/accounts');
  }

  async getJournalEntries(filters: { deal_id?: number; entity_type?: string; page?: number } = {}) {
    return this.request<LedgerEntry[]>('/api/v1/ledger/journal-entries' + qs({ ...filters }));
  }

  async getTrialBalance() {
    return this.request<Record<string, any>>('/api/v1/ledger/trial-balance');
  }

  async getReconciliationAudit() {
    return this.request<Record<string, any>>('/api/v1/reconciliation/audit');
  }

  // ── Reports ───────────────────────────────────────────────────────────────
  /**
   * Totals for the caller's scope. `from`/`to` window the deal's creation date —
   * the server echoes them back as `period_from`/`period_to`/`period_field`.
   */
  async getSalesSummary(filters: { from?: string; to?: string } = {}) {
    return this.request<Record<string, any>>('/api/v1/reports/sales-summary' + qs({ ...filters }));
  }

  /**
   * The deals export, fetched rather than linked.
   *
   * The route answers `auth:sanctum`, so an `<a href>` would arrive without the
   * bearer token and download a 401 page as a spreadsheet. Fetching also lets a
   * refused scope or a bad date read as a message instead of a broken file.
   */
  async downloadDealsCsv(
    filters: { from?: string; to?: string } = {}
  ): Promise<{ ok: boolean; blob: Blob | null; filename: string | null; message: string | null; errorCode: string | null }> {
    const headers: Record<string, string> = {
      Accept: 'text/csv',
      'Accept-Language': getLocale(),
    };
    if (this.token) headers['Authorization'] = `Bearer ${this.token}`;
    if (this.tenantId) headers['X-Organization-Id'] = this.tenantId.toString();

    const res = await fetch('/api/v1/reports/export/deals-csv' + qs({ ...filters }), { headers });

    if (!res.ok) {
      const json = (await res.json().catch(() => null)) as ApiResult | null;
      return {
        ok: false,
        blob: null,
        filename: null,
        message: json?.error?.message || json?.message || `تعذّر التصدير (${res.status})`,
        errorCode: json?.error_code || json?.error?.code || null,
      };
    }

    // The server names the file; keeping its name keeps the export's own
    // timestamp next to the report it came from.
    const declared = /filename="?([^";]+)"?/.exec(res.headers.get('Content-Disposition') ?? '');

    return {
      ok: true,
      blob: await res.blob(),
      filename: declared?.[1] ?? 'hassal_deals.csv',
      message: null,
      errorCode: null,
    };
  }

  // ── Inventory movements ───────────────────────────────────────────────────
  async getInventoryMovements(filters: {
    offer_id?: number;
    type?: string;
    warehouse_id?: number;
    per_page?: number;
    page?: number;
  } = {}) {
    return this.request<InventoryMovement[]>('/api/v1/inventory-movements' + qs({ ...filters }));
  }

  /**
   * A stock correction, written against the offer whose quantities it changes.
   *
   * `quantity` is signed: the server accepts a negative number for ADJUSTED
   * only, and answers `QUANTITY_MUST_BE_POSITIVE` for the two kinds that
   * describe goods arriving.
   */
  async adjustInventory(data: {
    offer_id: number;
    type: 'IN' | 'ADJUSTED' | 'RETURNED';
    quantity: number;
    reason: string;
  }) {
    return this.request<InventoryMovement>('/api/v1/inventory-movements/adjust', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('adj'),
    });
  }

  // ── Organizations, members and users ──────────────────────────────────────
  /**
   * Businesses. The same URL answers two different questions: for the platform
   * role it lists every business on the platform and filters by `type`,
   * `status` and `search`; for anyone else it lists only the businesses that
   * caller belongs to. The scope is decided server-side, not by a parameter.
   */
  async getOrganizations(filters: {
    type?: string;
    status?: string;
    search?: string;
    page?: number;
    per_page?: number;
  } = {}) {
    return this.request<Organization[]>('/api/v1/organizations' + qs({ ...filters }));
  }

  async getOrganization(id: number) {
    return this.request<Organization>(`/api/v1/organizations/${id}`);
  }

  async createOrganization(data: Record<string, unknown>) {
    return this.request<Organization>('/api/v1/organizations', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('org'),
    });
  }

  async updateOrganization(id: number, data: Record<string, unknown>) {
    return this.request<Organization>(`/api/v1/organizations/${id}`, { method: 'PUT', body: data });
  }

  /**
   * The platform's decision about one business.
   *
   * `VERIFY` is refused while a legal identifier the platform itself requires
   * (`legal.require_*`) is missing; `REJECT` and `SUSPEND` are refused without
   * a reason, because a decision nobody can explain cannot be answered later;
   * and a decision that would leave the organization in the state it is already
   * in is refused rather than silently accepted.
   *
   * What this records is a review conducted inside HASSAL by a named platform
   * account. No external register is queried, so no screen may describe the
   * result as a government verification (§47).
   */
  async decideOrganizationVerification(
    id: number,
    decision: 'VERIFY' | 'REJECT' | 'SUSPEND' | 'REINSTATE',
    reason?: string
  ) {
    return this.request<Organization>(`/api/v1/organizations/${id}/verification`, {
      method: 'PUT',
      body: { decision, ...(reason ? { reason } : {}) },
      idempotencyKey: idempotencyKey('org_ver'),
    });
  }

  /**
   * The standings a company may hold a person at, with the label the platform
   * shows for each. Read from the server because the same list is what the
   * validator accepts and what the column constrains — a dropdown typed out in
   * the client is a 422 the next time a rank is renamed.
   */
  async getMembershipRoles() {
    return this.request<MembershipRoleOption[]>('/api/v1/organizations/membership-roles');
  }

  async getOrganizationMembers(orgId: number) {
    return this.request<UserMembership[]>(`/api/v1/organizations/${orgId}/members`);
  }

  /**
   * `reason` is mandatory on all three staffing writes. Adding, demoting or
   * removing a person changes who can act on that company's offers, deals and
   * documents, so the audit row has to say who decided it and why — a roster
   * that only records "role: staff → admin" cannot answer an objection later.
   */
  async addOrganizationMember(orgId: number, data: { email: string; role: string; reason: string }) {
    return this.request(`/api/v1/organizations/${orgId}/members`, {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('member_add'),
    });
  }

  /**
   * `is_active` is optional and the server applies it in the same write as the
   * role, so ending or restoring a person's access is one decision with one
   * audit row rather than a role change plus a separate edit nobody recorded.
   */
  async updateOrganizationMember(
    orgId: number,
    userId: number,
    role: string,
    reason: string,
    isActive?: boolean
  ) {
    return this.request(`/api/v1/organizations/${orgId}/members/${userId}`, {
      method: 'PUT',
      body: { role, reason, ...(isActive === undefined ? {} : { is_active: isActive }) },
      idempotencyKey: idempotencyKey('member_role'),
    });
  }

  /**
   * The reason travels in the body: a DELETE carries one here because removal
   * is a decision, not a navigation action, and the server refuses it without.
   */
  async removeOrganizationMember(orgId: number, userId: number, reason: string) {
    return this.request(`/api/v1/organizations/${orgId}/members/${userId}`, {
      method: 'DELETE',
      body: { reason },
      idempotencyKey: idempotencyKey('member_remove'),
    });
  }

  // ── Legal documents, and the platform's decision on each copy ─────────────
  /**
   * The papers one business filed, plus what was decided on each.
   *
   * `accepted_types` is the short answer a checklist needs — which kinds of
   * paper a reviewer has already accepted — and `status` is the organization's,
   * i.e. whether the marketplace gate is open.
   */
  async getOrganizationDocuments(orgId: number) {
    return this.request<{
      organization_id: number;
      status: OrganizationStatus;
      accepted_types: OrganizationDocument['type'][];
      documents: OrganizationDocument[];
    }>(`/api/v1/organizations/${orgId}/documents`);
  }

  /**
   * File one paper. Sending it before verification is the point: an applicant
   * that could not upload its registration certificate because it was not
   * verified had no way to become verified.
   *
   * Three server answers a form has to surface rather than swallow:
   * `DOCUMENT_ALREADY_ACCEPTED` (this kind is already accepted — delete it to
   * replace it), `DOCUMENT_LIMIT_REACHED`, and `DOCUMENT_TYPE_UNSUPPORTED`.
   * Re-filing a rejected or still-pending copy replaces that copy, so the
   * reviewer's queue holds one row per open question.
   *
   * Acceptance records that a member of this platform's staff read this copy.
   * No official register is queried anywhere in the product, so no label here
   * may claim a ministry confirmed a number (§47).
   */
  async uploadOrganizationDocument(
    orgId: number,
    data: { type: OrganizationDocument['type']; file: File; reference_number?: string }
  ) {
    const form = new FormData();
    form.append('type', data.type);
    if (data.reference_number) form.append('reference_number', data.reference_number);
    form.append('file', data.file, data.file.name);

    return this.request<OrganizationDocument>(`/api/v1/organizations/${orgId}/documents`, {
      method: 'POST',
      form,
      idempotencyKey: idempotencyKey(`doc_${orgId}`),
    });
  }

  /** Withdraw a copy before anyone rules on it; a reviewed one refuses. */
  async deleteOrganizationDocument(orgId: number, documentId: number) {
    return this.request<{ id: number; deleted: boolean }>(
      `/api/v1/organizations/${orgId}/documents/${documentId}`,
      { method: 'DELETE' }
    );
  }

  /**
   * The platform's review desk: filed papers across every business.
   *
   * `organizations.verify` gates it at the route, so a business asking for it is
   * refused rather than handed its own rows — a filtered list would still
   * disclose which other companies have filed anything.
   *
   * With no `status` filter the server answers what is *waiting*, not the whole
   * archive; a reviewer's first screen is the queue, and the archive is a choice.
   */
  async getDocumentReviewQueue(
    filters: {
      status?: OrganizationDocument['status'];
      type?: OrganizationDocument['type'];
      organization_id?: number;
      search?: string;
      page?: number;
      per_page?: number;
    } = {}
  ) {
    return this.request<DocumentQueueRow[]>('/api/v1/organizations/document-queue' + qs({ ...filters }));
  }

  /** The counts beside those tabs, taken by the server over the same rows. */
  async getDocumentReviewSummary() {
    return this.request<DocumentReviewSummary>('/api/v1/organizations/document-summary');
  }

  /**
   * The staff decision on one document (`organizations.verify`).
   *
   * A rejection has to carry the reason, because "your papers were rejected" is
   * not actionable and an applicant who cannot tell which paper failed resubmits
   * the same one forever. This decides the copy, not the business: the
   * marketplace gate still opens on `decideOrganizationVerification`.
   */
  async reviewOrganizationDocument(
    orgId: number,
    documentId: number,
    data: { action: 'accept' | 'reject'; reason?: string }
  ) {
    return this.request<OrganizationDocument>(
      `/api/v1/organizations/${orgId}/documents/${documentId}/review`,
      { method: 'PUT', body: data, idempotencyKey: idempotencyKey(`doc_rev_${documentId}`) }
    );
  }

  /**
   * Accounts on the platform. The server filters by `search` (name, email,
   * phone) and `status` — there is no role filter, so a screen that wants
   * people by role reads `roles` off the rows it already has.
   */
  async getUsers(filters: { search?: string; status?: string; page?: number; per_page?: number } = {}) {
    return this.request<ManagedUser[]>('/api/v1/users' + qs({ ...filters }));
  }

  async getUser(id: number) {
    return this.request<ManagedUser>(`/api/v1/users/${id}`);
  }

  /**
   * The roles the *platform* can grant, read from the `roles` table narrowed to
   * `config('rbac.platform_roles')`. The server rejects anything outside that
   * list, so a business role is never assigned from here — it belongs to one
   * organization and changes through `updateOrganizationMember`.
   *
   * Each row carries the permissions the role actually holds, so the console can
   * show what a grant is worth instead of a bare name.
   */
  async getUserRoles() {
    return this.request<PlatformRoleOption[]>('/api/v1/users/roles');
  }

  /**
   * `reason` is required and lands in the audit entry beside the role, so a
   * grant stays explainable months later. The endpoint is idempotency-keyed:
   * a double click must not create two audit rows for one decision.
   */
  async assignUserRole(id: number, roleName: string, reason: string) {
    return this.request(`/api/v1/users/${id}/roles`, {
      method: 'POST',
      body: { role_name: roleName, reason },
      idempotencyKey: idempotencyKey('role_grant'),
    });
  }

  /**
   * Revoking needs the same accounting as granting. The body is what makes a
   * DELETE carry it; the server also refuses to remove the last SuperAdmin or
   * to let an admin revoke their own platform role.
   */
  async revokeUserRole(id: number, role: string, reason: string) {
    return this.request(`/api/v1/users/${id}/roles/${encodeURIComponent(role)}`, {
      method: 'DELETE',
      body: { reason },
      idempotencyKey: idempotencyKey('role_revoke'),
    });
  }

  /**
   * `active`, `suspended` or `inactive` — lowercase, the vocabulary the column
   * holds and the one login checks. The reason is required: it is stored in the
   * audit entry next to the previous status, so a lockout stays explainable.
   * An account cannot be suspended by the person signed in as it.
   */
  async updateUserStatus(id: number, status: 'active' | 'suspended' | 'inactive', reason: string) {
    return this.request(`/api/v1/users/${id}/status`, { method: 'PUT', body: { status, reason } });
  }

  // ── Platform configuration (SuperAdmin) ───────────────────────────────────
  async getSettings(filters: { group?: string } = {}) {
    return this.request<SystemSetting[]>('/api/v1/settings' + qs({ ...filters }));
  }

  /** One key per call, as the endpoint accepts one key per call. */
  async updateSetting(key: string, value: string | number | boolean) {
    return this.request<SystemSetting>('/api/v1/settings/update', {
      method: 'POST',
      body: { key, value: String(value) },
    });
  }

  async getFeatureFlags() {
    return this.request<FeatureFlag[]>('/api/v1/feature-flags');
  }

  async toggleFeatureFlag(key: string, is_enabled: boolean, rules_json?: Record<string, unknown>) {
    return this.request(`/api/v1/feature-flags/${encodeURIComponent(key)}/toggle`, {
      method: 'POST',
      body: { is_enabled, rules_json },
    });
  }

  async getCurrencies() {
    return this.request<Currency[]>('/api/v1/currencies');
  }

  async createCurrency(data: Record<string, unknown>) {
    return this.request<Currency>('/api/v1/currencies', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('cur'),
    });
  }

  async updateCurrency(id: number, data: Record<string, unknown>) {
    return this.request<Currency>(`/api/v1/currencies/${id}`, { method: 'PUT', body: data });
  }

  async setDefaultCurrency(id: number) {
    return this.request(`/api/v1/currencies/${id}/set-default`, { method: 'POST' });
  }

  async getSetupStatus() {
    return this.request<Record<string, any>>('/api/v1/setup-wizard/status');
  }

  async saveSetupConfiguration(data: Record<string, unknown>) {
    return this.request('/api/v1/setup-wizard/configure', { method: 'POST', body: data });
  }

  // ── Integration surface ───────────────────────────────────────────────────
  async getApiKeys() {
    return this.request<ApiKey[]>('/api/v1/api-keys');
  }

  /**
   * `secret` in the response is shown exactly once, by the server. The default
   * scope on the endpoint is `*`, so a screen that omits `scopes` is handing out
   * a full-access key — pass the narrowest set the integration needs.
   */
  async createApiKey(data: { name: string; scopes?: string[]; expires_in_days?: number }) {
    return this.request<{ api_key: ApiKey; key_id: string; secret: string }>('/api/v1/api-keys', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('key'),
    });
  }

  async revokeApiKey(id: number) {
    return this.request(`/api/v1/api-keys/${id}`, { method: 'DELETE' });
  }

  async getWebhooks() {
    return this.request<Webhook[]>('/api/v1/webhooks');
  }

  /** The signing secret is generated server-side and returned once. */
  async createWebhook(data: { endpoint_url: string; events: string[] }) {
    return this.request<Webhook>('/api/v1/webhooks', {
      method: 'POST',
      body: data,
      idempotencyKey: idempotencyKey('whk'),
    });
  }

  async getWebhookDeliveries(id: number, page?: number) {
    return this.request<WebhookDelivery[]>(`/api/v1/webhooks/${id}/deliveries` + qs({ page }));
  }

  async pingWebhook(id: number) {
    return this.request(`/api/v1/webhooks/${id}/ping`, { method: 'POST' });
  }

  // ── Audit trail ───────────────────────────────────────────────────────────
  async getAuditLogs(filters: {
    action?: string;
    auditable_type?: string;
    organization_id?: number;
    user_id?: number;
    page?: number;
  } = {}) {
    return this.request<AuditLog[]>('/api/v1/audit-logs' + qs({ ...filters }));
  }
}

export const api = new ApiClient();

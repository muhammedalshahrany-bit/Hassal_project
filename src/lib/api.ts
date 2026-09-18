import type {
  User,
  Organization,
  Category,
  Brand,
  Product,
  ProductVariant,
  Warehouse,
  Offer,
  PurchaseRequest,
  Deal,
  Demand,
  LedgerAccount,
  LedgerEntry,
  SystemSetting,
  AuditLog,
} from '../types';
import { getLocale, LOCALE_KEY, type Locale } from './i18n';

const TOKEN_KEY = 'hassal_auth_token';
const TENANT_KEY = 'hassal_active_tenant_id';

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
      body?: any;
      headers?: Record<string, string>;
      idempotencyKey?: string;
    } = {}
  ): Promise<{ success: boolean; data?: T; message?: string; error_code?: string; errors?: any; error?: { code?: string; message?: string; details?: any } }> {
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'Accept-Language': getLocale(),
      ...(options.headers || {}),
    };

    if (this.token) {
      headers['Authorization'] = `Bearer ${this.token}`;
    }
    if (this.tenantId) {
      headers['X-Organization-Id'] = this.tenantId.toString();
    }
    if (options.idempotencyKey) {
      headers['X-Idempotency-Key'] = options.idempotencyKey;
    }

    const res = await fetch(path, {
      method: options.method || 'GET',
      headers,
      body: options.body ? JSON.stringify(options.body) : undefined,
    });

    const json = await res.json().catch(() => ({ success: false, message: 'استجابة غير صالحة من الخادم' }));
    if (!res.ok || json.success === false) {
      return {
        ...json,
        success: false,
        message: json.message || json.error?.message || `فشل الطلب (${res.status})`,
        error_code: json.error_code || json.error?.code,
      };
    }
    return json;
  }

  // Auth
  async register(data: {
    name: string;
    email: string;
    phone?: string;
    password: string;
    password_confirmation: string;
    organization_name?: string;
    organization_type?: 'buyer' | 'seller' | 'intermediary';
  }) {
    const res = await this.request('/api/v1/auth/register', {
      method: 'POST',
      body: data,
      idempotencyKey: 'reg_' + Date.now(),
    });
    if (res.success && res.data?.token) {
      this.setToken(res.data.token);
      if (res.data.organization?.id) {
        this.setTenantId(res.data.organization.id);
      }
    }
    return res;
  }

  async login(login: string, password: string) {
    const res = await this.request('/api/v1/auth/login', {
      method: 'POST',
      body: { login, password },
    });
    if (res.success && res.data?.token) {
      this.setToken(res.data.token);
      if (res.data.active_organization?.id) {
        this.setTenantId(res.data.active_organization.id);
      }
    }
    return res;
  }

  async forgotPassword(email: string) {
    return this.request('/api/v1/auth/forgot-password', {
      method: 'POST',
      body: { email },
    });
  }

  async resetPassword(data: { email: string; token: string; password: string; password_confirmation: string }) {
    return this.request('/api/v1/auth/reset-password', {
      method: 'POST',
      body: data,
    });
  }

  async logout() {
    await this.request('/api/v1/auth/logout', { method: 'POST' });
    this.setToken(null);
    this.setTenantId(null);
  }

  async getMe() {
    return this.request('/api/v1/auth/me');
  }

  async switchOrganization(orgId: number) {
    const res = await this.request('/api/v1/auth/switch-organization', {
      method: 'POST',
      body: { organization_id: orgId },
    });
    if (res.success) {
      this.setTenantId(orgId);
    }
    return res;
  }

  async getNotifications() {
    return this.request('/api/v1/notifications');
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

  // Bootstrap & Health
  async getBootstrap() {
    return this.request('/api/v1/bootstrap');
  }

  async getHealth() {
    return this.request('/api/v1/health');
  }

  // Catalog
  async getCategories() {
    return this.request<Category[]>('/api/v1/marketplace/categories');
  }

  async getBrands() {
    return this.request<Brand[]>('/api/v1/marketplace/brands');
  }

  async getProducts() {
    return this.request<Product[]>('/api/v1/catalog/products');
  }

  async getProductVariants(productId: number) {
    return this.request<ProductVariant[]>(`/api/v1/catalog/products/${productId}/variants`);
  }

  // Warehouses
  async getWarehouses() {
    return this.request<Warehouse[]>('/api/v1/warehouses');
  }

  async getPublicWarehouses() {
    return this.request<Warehouse[]>('/api/v1/marketplace/warehouses');
  }

  async createWarehouse(warehouse: Partial<Warehouse>) {
    return this.request<Warehouse>('/api/v1/warehouses', {
      method: 'POST',
      body: warehouse,
      idempotencyKey: 'wh_' + Date.now(),
    });
  }

  // Offers
  async getOffers(filters: {
    category_id?: number;
    brand_id?: number;
    reason?: string;
    city?: string;
    min_price?: number;
    max_price?: number;
    sort_by?: string;
    status?: string;
  } = {}) {
    const params = new URLSearchParams();
    if (filters.category_id) params.set('category_id', filters.category_id.toString());
    if (filters.brand_id) params.set('brand_id', filters.brand_id.toString());
    if (filters.reason) params.set('reason', filters.reason);
    if (filters.city) params.set('city', filters.city);
    if (filters.min_price) params.set('min_price', filters.min_price.toString());
    if (filters.max_price) params.set('max_price', filters.max_price.toString());
    if (filters.sort_by) params.set('sort_by', filters.sort_by);
    if (filters.status) params.set('status', filters.status);

    const qs = params.toString() ? `?${params.toString()}` : '';
    return this.request<Offer[]>(`/api/v1/marketplace/offers${qs}`);
  }

  async getNearbyOffers(lat: number, lon: number, radiusKm = 250) {
    return this.request<Offer[]>(`/api/v1/marketplace/offers/nearby?latitude=${lat}&longitude=${lon}&radius_km=${radiusKm}`);
  }

  async getOffer(id: number) {
    return this.request<Offer>(`/api/v1/marketplace/offers/${id}`);
  }

  async createOffer(offer: any) {
    return this.request<Offer>('/api/v1/marketplace/offers', {
      method: 'POST',
      body: offer,
      idempotencyKey: 'off_' + Date.now(),
    });
  }

  async publishOffer(id: number) {
    return this.request(`/api/v1/marketplace/offers/${id}/status`, {
      method: 'PUT',
      body: { status: 'ACTIVE' },
    });
  }

  async pauseOffer(id: number) {
    return this.request(`/api/v1/marketplace/offers/${id}/status`, {
      method: 'PUT',
      body: { status: 'PAUSED' },
    });
  }

  // Purchase Requests & Atomic Lock
  async createPurchaseRequest(data: {
    offer_id: number;
    quantity: number;
    delivery_preference: 'pickup' | 'delivery';
    notes?: string;
  }) {
    return this.request<PurchaseRequest>('/api/v1/purchase-requests', {
      method: 'POST',
      body: data,
      idempotencyKey: 'pr_' + Date.now() + '_' + Math.random().toString(36).substring(7),
    });
  }

  async getPurchaseRequests() {
    return this.request<PurchaseRequest[]>('/api/v1/purchase-requests');
  }

  async acceptPurchaseRequest(id: number) {
    return this.request<Deal>(`/api/v1/purchase-requests/${id}/accept`, {
      method: 'POST',
      idempotencyKey: 'pr_acc_' + id,
    });
  }

  async rejectPurchaseRequest(id: number, reason?: string) {
    return this.request(`/api/v1/purchase-requests/${id}/reject`, {
      method: 'POST',
      body: { reason },
      idempotencyKey: 'pr_rej_' + id,
    });
  }

  // Deals
  async getDeals() {
    return this.request<Deal[]>('/api/v1/deals');
  }

  async getDeal(id: number) {
    return this.request<Deal>(`/api/v1/deals/${id}`);
  }

  async transitionDeal(id: number, targetStatus: string, notes?: string) {
    return this.request<Deal>(`/api/v1/deals/${id}/transition`, {
      method: 'POST',
      body: { status: targetStatus, notes },
    });
  }

  async confirmReceived(id: number) {
    return this.request<Deal>(`/api/v1/deals/${id}/confirm-received`, {
      method: 'POST',
      idempotencyKey: 'deal_cnf_' + id,
    });
  }

  // Payments
  async recordPayment(dealId: number, data: {
    amount: number;
    provider: string;
    external_reference?: string;
    receipt_document_url?: string;
  }) {
    return this.request(`/api/v1/deals/${dealId}/payments`, {
      method: 'POST',
      body: data,
      idempotencyKey: 'pay_' + dealId + '_' + Date.now(),
    });
  }

  async verifyPayment(paymentId: number) {
    return this.request(`/api/v1/payments/${paymentId}/verify`, {
      method: 'POST',
      idempotencyKey: 'pay_vrf_' + paymentId,
    });
  }

  // Disputes
  async openDispute(dealId: number, data: {
    reason: string;
    claimed_amount: number;
    notes?: string;
  }) {
    return this.request(`/api/v1/deals/${dealId}/disputes`, {
      method: 'POST',
      body: data,
      idempotencyKey: 'dsp_' + dealId,
    });
  }

  async arbitrateDispute(disputeId: number, data: {
    decision: 'full_refund' | 'partial_refund' | 'dismiss_dispute';
    refunded_amount?: number;
    resolution_notes?: string;
  }) {
    return this.request(`/api/v1/disputes/${disputeId}/arbitrate`, {
      method: 'POST',
      body: data,
      idempotencyKey: 'arb_' + disputeId,
    });
  }

  // Demands (RFQ)
  async getDemands() {
    return this.request<Demand[]>('/api/v1/demands');
  }

  async createDemand(demand: any) {
    return this.request<Demand>('/api/v1/demands', {
      method: 'POST',
      body: demand,
      idempotencyKey: 'dmd_' + Date.now(),
    });
  }

  // Ledger & Financial Statement
  async getLedgerStatement() {
    return this.request<{ accounts: LedgerAccount[]; journal_entries: LedgerEntry[] }>('/api/v1/ledger/statement');
  }

  // Admin
  async getAnalyticsOverview() {
    return this.request('/api/v1/admin/analytics/overview');
  }

  async getSettings() {
    return this.request<SystemSetting[]>('/api/v1/admin/settings');
  }

  async updateSettings(settings: { key: string; value: string }[]) {
    return this.request('/api/v1/admin/settings', {
      method: 'PUT',
      body: { settings },
    });
  }

  async getAuditLogs() {
    return this.request<AuditLog[]>('/api/v1/audit-logs');
  }
}

export const api = new ApiClient();

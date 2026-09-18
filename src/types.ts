export interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
  national_id?: string;
  status: string;
  roles?: string[];
  permissions?: string[];
  organizations?: OrganizationMembership[];
}

export interface Organization {
  id: number;
  name_ar: string;
  name_en: string;
  type: 'seller' | 'buyer' | 'intermediary' | 'admin';
  commercial_registration_number: string;
  vat_number: string;
  status: 'pending_kyc' | 'verified' | 'suspended' | 'rejected';
  credit_limit: number;
  rating: number;
  kyc_verified_at?: string;
  metadata?: string;
}

export interface OrganizationMembership {
  id: number;
  organization_id: number;
  user_id: number;
  role: string;
  is_active: boolean;
  name_ar?: string;
  name_en?: string;
  type?: string;
  commercial_registration_number?: string;
  vat_number?: string;
  name?: string;
  email?: string;
  joined_at?: string;
}

export type OrganizationMember = OrganizationMembership;

export interface Category {
  id: number;
  parent_id?: number;
  name_ar: string;
  name_en: string;
  slug: string;
  icon?: string;
  sort_order: number;
}

export interface Brand {
  id: number;
  name_ar: string;
  name_en: string;
  slug: string;
  logo_url?: string;
}

export interface Warehouse {
  id: number;
  organization_id: number;
  name: string;
  city: string;
  district?: string;
  address_line: string;
  latitude: number;
  longitude: number;
  contact_name: string;
  contact_phone: string;
}

export interface ProductVariant {
  id: number;
  product_id: number;
  unit_id: number;
  sku: string;
  barcode?: string;
  variant_name_ar: string;
  variant_name_en: string;
  weight_kg?: number;
  packaging_spec?: string;
  image_url?: string;
  unit_name_ar?: string;
  unit_code?: string;
}

export interface Product {
  id: number;
  category_id: number;
  brand_id?: number;
  name_ar: string;
  name_en: string;
  description_ar?: string;
  image_url?: string;
  category_name_ar?: string;
  brand_name_ar?: string;
  variants?: ProductVariant[];
}

export type OpportunityReason =
  | 'surplus_stock'
  | 'slow_moving'
  | 'overproduction'
  | 'assortment_change'
  | 'seasonal_inventory'
  | 'commercial_liquidation';

export interface Offer {
  id: number;
  seller_organization_id: number;
  product_variant_id: number;
  warehouse_id: number;
  available_quantity: number;
  reserved_quantity: number;
  sold_quantity: number;
  unit_price: number;
  market_reference_price?: number;
  currency: string;
  min_order_quantity: number;
  max_order_quantity?: number;
  opportunity_reason: OpportunityReason;
  reason_notes?: string;
  production_date?: string;
  expiry_date?: string;
  delivery_terms: 'buyer_pickup' | 'seller_delivery' | 'both';
  status: 'draft' | 'pending_review' | 'active' | 'paused' | 'sold_out' | 'expired' | 'cancelled';
  published_at?: string;
  expires_at?: string;
  // Joined fields
  sku?: string;
  variant_name_ar?: string;
  weight_kg?: number;
  packaging_spec?: string;
  product_name_ar?: string;
  product_name_en?: string;
  product_image?: string;
  category_name_ar?: string;
  category_id?: number;
  brand_name_ar?: string;
  brand_id?: number;
  unit_name_ar?: string;
  warehouse_name?: string;
  warehouse_city?: string;
  warehouse_district?: string;
  warehouse_address?: string;
  warehouse_lat?: number;
  warehouse_lon?: number;
  warehouse_contact_name?: string;
  warehouse_contact_phone?: string;
  seller_name_ar?: string;
  seller_rating?: number;
  seller_status?: string;
  seller_cr?: string;
  seller_vat?: string;
  distance_km?: number;
}

export interface PurchaseRequest {
  id: number;
  offer_id: number;
  buyer_organization_id: number;
  buyer_user_id: number;
  quantity: number;
  unit_price: number;
  total_price: number;
  currency: string;
  delivery_preference: 'pickup' | 'delivery';
  status: 'pending' | 'accepted' | 'rejected' | 'expired' | 'cancelled';
  reserved_until: string;
  notes?: string;
  rejection_reason?: string;
  created_at: string;
  // Joined
  product_name_ar?: string;
  variant_name_ar?: string;
  seller_name?: string;
  buyer_name?: string;
  deal_id?: number;
  deal_number?: string;
}

export type DealStatus =
  | 'accepted'
  | 'payment_pending'
  | 'paid'
  | 'preparing'
  | 'ready_for_pickup'
  | 'shipped'
  | 'delivered'
  | 'received'
  | 'completed'
  | 'cancelled'
  | 'disputed'
  | 'refunded';

export interface DealItem {
  id: number;
  deal_id: number;
  product_variant_id: number;
  snapshot_product_name: string;
  snapshot_sku: string;
  snapshot_unit_name: string;
  quantity: number;
  unit_price: number;
  subtotal: number;
}

export interface DealStatusHistory {
  id: number;
  deal_id: number;
  from_status: string;
  to_status: string;
  changed_by_user_id?: number;
  changed_by_name?: string;
  notes?: string;
  created_at: string;
}

export interface Payment {
  id: number;
  deal_id: number;
  payment_reference: string;
  provider: string;
  amount: number;
  currency: string;
  external_transaction_id?: string;
  bank_receipt_url?: string;
  status: 'pending' | 'processing' | 'success' | 'failed' | 'refunded';
  verified_at?: string;
}

export interface Dispute {
  id: number;
  deal_id: number;
  opened_by_organization_id: number;
  opened_by_user_id: number;
  reason: string;
  claimed_amount: number;
  status: 'opened' | 'under_review' | 'evidence_required' | 'resolved_refund' | 'resolved_reject' | 'resolved_partial' | 'closed';
  resolution_decision?: string;
  refunded_amount?: number;
  resolution_notes?: string;
  resolved_at?: string;
  created_at: string;
}

export interface Deal {
  id: number;
  deal_number: string;
  purchase_request_id: number;
  seller_organization_id: number;
  buyer_organization_id: number;
  warehouse_id: number;
  currency: string;
  subtotal_amount: number;
  tax_rate: number;
  tax_amount: number;
  commission_rate: number;
  commission_amount: number;
  shipping_fee: number;
  total_amount: number;
  payment_method: string;
  delivery_method: string;
  status: DealStatus;
  inspection_window_hours: number;
  accepted_at?: string;
  paid_at?: string;
  dispatched_at?: string;
  delivered_at?: string;
  completed_at?: string;
  seller_name?: string;
  seller_vat?: string;
  buyer_name?: string;
  buyer_vat?: string;
  warehouse_name?: string;
  warehouse_city?: string;
  warehouse_address?: string;
  items?: DealItem[];
  history?: DealStatusHistory[];
  payments?: Payment[];
  dispute?: Dispute;
}

export interface LedgerAccount {
  id: number;
  organization_id?: number | null;
  account_type: 'escrow' | 'available_balance' | 'commission_revenue' | 'tax_liability' | 'receivable';
  currency: string;
  balance: number;
}

export interface LedgerEntry {
  id: number;
  deal_id?: number;
  deal_number?: string;
  payment_id?: number;
  debit_account_id: number;
  credit_account_id: number;
  amount: number;
  currency: string;
  entry_type: string;
  description: string;
  created_at: string;
}

export interface Demand {
  id: number;
  buyer_organization_id: number;
  category_id?: number;
  title: string;
  description: string;
  quantity: number;
  target_unit_price?: number;
  currency: string;
  target_city: string;
  required_by_date: string;
  status: 'open' | 'fulfilled' | 'expired' | 'cancelled';
  category_name_ar?: string;
  buyer_name_ar?: string;
  buyer_rating?: number;
  created_at?: string;
}

export interface SystemSetting {
  id: number;
  key: string;
  value: string;
  cast_type: string;
  group_name: string;
  is_public: number;
  description?: string;
}

export interface AuditLog {
  id: number;
  organization_id?: number;
  user_id?: number;
  action: string;
  auditable_type?: string;
  auditable_id?: number;
  ip_address?: string;
  user_agent?: string;
  new_values?: any;
  created_at: string;
  user?: { id: number; name: string; email: string };
  organization?: { id: number; name_ar: string; name_en: string };
}

export type AuditLogItem = AuditLog;

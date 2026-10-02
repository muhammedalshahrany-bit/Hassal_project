select 'api_keys' t, count(*) n from `api_keys`
union all
select 'audit_logs' t, count(*) n from `audit_logs`
union all
select 'brands' t, count(*) n from `brands`
union all
select 'cache' t, count(*) n from `cache`
union all
select 'cache_locks' t, count(*) n from `cache_locks`
union all
select 'categories' t, count(*) n from `categories`
union all
select 'currencies' t, count(*) n from `currencies`
union all
select 'deal_handovers' t, count(*) n from `deal_handovers`
union all
select 'deal_items' t, count(*) n from `deal_items`
union all
select 'deal_status_histories' t, count(*) n from `deal_status_histories`
union all
select 'deals' t, count(*) n from `deals`
union all
select 'demand_matches' t, count(*) n from `demand_matches`
union all
select 'demands' t, count(*) n from `demands`
union all
select 'disputes' t, count(*) n from `disputes`
union all
select 'external_identities' t, count(*) n from `external_identities`
union all
select 'failed_jobs' t, count(*) n from `failed_jobs`
union all
select 'feature_flags' t, count(*) n from `feature_flags`
union all
select 'idempotency_keys' t, count(*) n from `idempotency_keys`
union all
select 'inventory_movements' t, count(*) n from `inventory_movements`
union all
select 'job_batches' t, count(*) n from `job_batches`
union all
select 'jobs' t, count(*) n from `jobs`
union all
select 'journal_entries' t, count(*) n from `journal_entries`
union all
select 'ledger_accounts' t, count(*) n from `ledger_accounts`
union all
select 'ledger_lines' t, count(*) n from `ledger_lines`
union all
select 'migrations' t, count(*) n from `migrations`
union all
select 'model_has_permissions' t, count(*) n from `model_has_permissions`
union all
select 'model_has_roles' t, count(*) n from `model_has_roles`
union all
select 'notifications' t, count(*) n from `notifications`
union all
select 'offer_comment_reports' t, count(*) n from `offer_comment_reports`
union all
select 'offer_comments' t, count(*) n from `offer_comments`
union all
select 'offer_items' t, count(*) n from `offer_items`
union all
select 'offer_likes' t, count(*) n from `offer_likes`
union all
select 'offers' t, count(*) n from `offers`
union all
select 'organization_documents' t, count(*) n from `organization_documents`
union all
select 'organization_membership_permissions' t, count(*) n from `organization_membership_permissions`
union all
select 'organization_memberships' t, count(*) n from `organization_memberships`
union all
select 'organization_role_permissions' t, count(*) n from `organization_role_permissions`
union all
select 'organization_roles' t, count(*) n from `organization_roles`
union all
select 'organizations' t, count(*) n from `organizations`
union all
select 'password_reset_tokens' t, count(*) n from `password_reset_tokens`
union all
select 'permissions' t, count(*) n from `permissions`
union all
select 'personal_access_tokens' t, count(*) n from `personal_access_tokens`
union all
select 'product_images' t, count(*) n from `product_images`
union all
select 'product_variants' t, count(*) n from `product_variants`
union all
select 'products' t, count(*) n from `products`
union all
select 'purchase_requests' t, count(*) n from `purchase_requests`
union all
select 'quotations' t, count(*) n from `quotations`
union all
select 'reservations' t, count(*) n from `reservations`
union all
select 'role_has_permissions' t, count(*) n from `role_has_permissions`
union all
select 'roles' t, count(*) n from `roles`
union all
select 'sessions' t, count(*) n from `sessions`
union all
select 'support_tickets' t, count(*) n from `support_tickets`
union all
select 'system_settings' t, count(*) n from `system_settings`
union all
select 'user_devices' t, count(*) n from `user_devices`
union all
select 'users' t, count(*) n from `users`
union all
select 'warehouses' t, count(*) n from `warehouses`
union all
select 'webhook_deliveries' t, count(*) n from `webhook_deliveries`
union all
select 'webhooks' t, count(*) n from `webhooks`

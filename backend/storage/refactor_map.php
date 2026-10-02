<?php
return (function () {
// Structural refactor engine: moves the whole backend to a module-first layout,
// rewrites every namespace declaration and every fully-qualified reference.

$root = 'D:/hassal/backend';
$HV1 = 'app/Http/Controllers/Api/V1';

$move = [];
$add = function (string $newDir, array $files) use (&$move, $root) {
    foreach ($files as $f) {
        $base = basename($f);
        $move[$f] = $newDir . '/' . $base;
    }
};

// ── Core ────────────────────────────────────────────────────────────────
$add('app/Core/Http/Controllers', ['app/Http/Controllers/Controller.php']);
$add('app/Core/Http', ['app/Http/Responses/ApiResponse.php']);
$add('app/Core/Http/Middleware', array_map(fn ($m) => "app/Http/Middleware/{$m}.php", [
    'AuditLogMiddleware', 'EnsureAccountIsActive', 'EnsureFeatureEnabled',
    'EnsureVerifiedOrganization', 'IdempotencyMiddleware', 'LocalizationMiddleware',
    'ResolveOrganizationContext', 'SecurityHeaders',
]));
$add('app/Core/Tenant', ['app/Services/TenantContext.php']);
$add('app/Core/Rbac', ['app/Support/RbacScope.php']);
$add('app/Core/Support', array_map(fn ($s) => "app/Support/{$s}.php", [
    'Geo', 'LockOrder', 'QrCode', 'SensitivePayload', 'UploadProbe', 'UploadQuota', 'WaitingTime',
]));
$add('app/Core/Models', ['app/Models/IdempotencyKey.php', 'app/Models/AuditLog.php']);

// ── Auth ────────────────────────────────────────────────────────────────
$add('app/Auth/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'AuthController', 'BootstrapController', 'DeviceController', 'PreferenceController',
]));
$add('app/Auth/Requests', array_map(fn ($r) => "app/Http/Requests/{$r}.php", [
    'LoginRequest', 'RegisterRequest', 'GoogleSignInRequest', 'DeviceSyncRequest', 'DeviceUpdateRequest',
]));
$add('app/Auth/Requests/Concerns', ['app/Http/Requests/Concerns/CarriesDevice.php']);
$add('app/Auth/Models', ['app/Models/ExternalIdentity.php', 'app/Models/UserDevice.php']);
$add('app/Auth/Services', ['app/Services/Auth/DeviceRegistry.php', 'app/Services/Auth/GoogleIdTokenVerifier.php', 'app/Services/BootstrapService.php']);
$add('app/Auth/Exceptions', ['app/Exceptions/GoogleIdTokenRejectedException.php']);
$add('app/Auth/Support', ['app/Support/AccountRequirements.php', 'app/Support/PreferenceCatalog.php']);

// ── Identity ────────────────────────────────────────────────────────────
$add('app/Identity/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'UserManagementController', 'RoleGovernanceController',
]));
$add('app/Identity/Models', ['app/Models/User.php']);

// ── Organizations ───────────────────────────────────────────────────────
$add('app/Organizations/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'OrganizationController', 'OrganizationRoleController', 'OrganizationDocumentController',
]));
$add('app/Organizations/Requests', ['app/Http/Requests/StoreOrganizationRequest.php', 'app/Http/Requests/AddMemberRequest.php']);
$add('app/Organizations/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'Organization', 'OrganizationMembership', 'OrganizationRole', 'OrganizationMemberPermission', 'OrganizationDocument',
]));

// ── Catalog ─────────────────────────────────────────────────────────────
$add('app/Catalog/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'ProductCatalogController', 'ProductImageController',
]));
$add('app/Catalog/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'Product', 'ProductVariant', 'ProductImage', 'Category', 'Brand',
]));
$add('app/Catalog/Support', ['app/Support/CategoryCatalog.php']);

// ── Marketplace ─────────────────────────────────────────────────────────
$add('app/Marketplace/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'OfferController', 'DemandController',
]));
$add('app/Marketplace/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'Offer', 'OfferItem', 'Demand', 'DemandMatch', 'Quotation',
]));
$add('app/Marketplace/Resources', ['app/Http/Resources/OfferResource.php']);
$add('app/Marketplace/Services', ['app/Services/GeoDiscoveryService.php', 'app/Services/DemandMatchingService.php']);
$add('app/Marketplace/Support', ['app/Support/OfferNotices.php']);

// ── Community ───────────────────────────────────────────────────────────
$add('app/Community/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'OfferLikeController', 'OfferCommentController', 'CommunityModerationController',
]));
$add('app/Community/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'OfferLike', 'OfferComment', 'OfferCommentReport',
]));

// ── Trading ─────────────────────────────────────────────────────────────
$add('app/Trading/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'PurchaseRequestController', 'ReservationController', 'DealController',
    'DealHandoverController', 'DisputeController', 'LedgerController',
]));
$add('app/Trading/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'PurchaseRequest', 'Reservation', 'Deal', 'DealItem', 'DealStatusHistory',
    'DealHandover', 'Dispute', 'JournalEntry', 'LedgerAccount', 'LedgerLine',
]));
$add('app/Trading/Services', array_map(fn ($s) => "app/Services/{$s}.php", [
    'DealHandoverService', 'DealStateMachineService', 'InventoryReservationService',
    'DoubleEntryLedgerService', 'PricingAndCurrencyService',
]));
$add('app/Trading/Support', ['app/Support/DealNotices.php', 'app/Support/HandoverRefusal.php']);
$add('app/Trading/Exceptions', ['app/Exceptions/OfferExpiredException.php']);

// ── Inventory ───────────────────────────────────────────────────────────
$add('app/Inventory/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'WarehouseController', 'InventoryMovementController',
]));
$add('app/Inventory/Models', ['app/Models/Warehouse.php', 'app/Models/InventoryMovement.php']);
$add('app/Inventory/Resources', ['app/Http/Resources/WarehouseResource.php']);

// ── Notifications ───────────────────────────────────────────────────────
$add('app/Notifications/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", ['NotificationController']));
$add('app/Notifications/Models', ['app/Models/Notification.php']);
$add('app/Notifications/Services', ['app/Services/Notifications/NotificationManager.php']);
$add('app/Notifications/Channels', [
    'app/Services/Notifications/DatabaseNotificationChannel.php',
    'app/Services/Notifications/EmailNotificationChannel.php',
]);
$add('app/Notifications/Contracts', ['app/Contracts/NotificationChannelInterface.php']);

// ── SupportDesk ─────────────────────────────────────────────────────────
$add('app/SupportDesk/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", ['SupportController']));
$add('app/SupportDesk/Models', ['app/Models/SupportTicket.php']);

// ── Platform ────────────────────────────────────────────────────────────
$add('app/Platform/Controllers', array_map(fn ($c) => "{$HV1}/{$c}.php", [
    'ApiKeyController', 'CurrencyManagementController', 'FeatureFlagController',
    'ReportExportController', 'SetupWizardController', 'SystemSettingController', 'WebhookController',
]));
$add('app/Platform/Models', array_map(fn ($m) => "app/Models/{$m}.php", [
    'ApiKey', 'Currency', 'FeatureFlag', 'SystemSetting', 'Webhook', 'WebhookDelivery',
]));
$add('app/Platform/Services', array_map(fn ($s) => "app/Services/{$s}.php", [
    'ApiKeyAuthenticationService', 'FeatureFlagService', 'SystemSettingsService', 'WebhookDispatcherService',
]));
$add('app/Platform/Support', array_map(fn ($s) => "app/Support/{$s}.php", [
    'FlagCatalog', 'SettingCatalog', 'CatalogSync', 'OutboundEndpointPolicy',
]));
$add('app/Platform/Rules', ['app/Rules/SafeWebhookEndpoint.php']);

// ── Dead code (verified zero references) ───────────────────────────────
$delete = ['app/Scopes/TenantScope.php', 'app/Traits/BelongsToTenant.php'];

// ── Test reorganisation (folder -> namespace Tests\Feature\<Folder>) ────
$tests = [
    'Auth' => ['AuthTest', 'GoogleSignInTest', 'SignInDevicesTest', 'ProfileCompletionTest', 'AccountPreferencesTest', 'ProfileAndWarehouseEditTest'],
    'Identity' => ['AccountGovernanceTest', 'PermissionIsolationTest', 'RbacMatrixTest'],
    'Organizations' => ['OrganizationTest', 'OrganizationVerificationTest', 'DocumentReviewQueueTest', 'MembershipPermissionsTest'],
    'Catalog' => ['CatalogDriftTest', 'CatalogScopeTest', 'ProductImageTest'],
    'Marketplace' => ['OfferLifecycleTest', 'NearbyOffersTest', 'FeaturedOffersTest', 'DemandMatchingTest', 'SellerIdentityVisibilityTest'],
    'Community' => ['OfferCommunityTest'],
    'Trading' => ['DealFlowTest', 'DealHandoverTest', 'DealNoticeTest', 'PurchaseRequestFlowTest', 'InventoryReservationTest', 'PricingAndCurrencyTest'],
    'Inventory' => ['WarehouseBoardTest'],
    'SupportDesk' => ['SupportDeskTest'],
    'Platform' => ['ApiKeyCapabilityTest', 'ApiRateLimitTest', 'FeatureFlagSweepTest', 'SettingsManagementTest', 'ReportsAndExportTest', 'PlatformAdminLayerTest', 'DatabaseNormalizationTest', 'WaitingHoursReportedTest', 'UploadQuotaAndSecurityHeadersTest', 'TenantIsolationAndIdorTest', 'GoldenUserJourneyTest', 'WebhookDeliveryTest'],
];

$fqnOf = fn (string $path) => 'App\\' . str_replace('/', '\\', preg_replace('#^app/#', '', $path));


return ['move' => $move, 'delete' => $delete, 'tests' => $tests];
})();

<?php

/*
|--------------------------------------------------------------------------
| Role & Permission Matrix
|--------------------------------------------------------------------------
|
| THIS FILE IS THE SINGLE SOURCE OF TRUTH for RBAC.
|
|   * Database\Seeders\RolesAndPermissionsSeeder reads it to build the matrix.
|   * App\Console\Commands\InstallSystemCommand calls that seeder; it must
|     never declare permissions of its own. A second, divergent vocabulary
|     previously lived in the installer and silently replaced these grants
|     with permissions no route ever checks.
|
| Every name declared here must be enforced somewhere in the backend
| (route middleware or a service guard — this application has no policy
| classes). A permission that is seeded but never enforced is a lie about the
| security model, so tests/Feature/RbacMatrixTest.php asserts the reverse
| direction too: no catalog entry without a check, and no check without a
| catalog entry.
|
| Least privilege applies. Business roles describe what a *buyer* or a
| *seller* may do inside their own organization. Platform-wide concerns —
| the double-entry ledger, tax/currency definitions, dispute adjudication,
| moderation and settings — belong to platform roles, never to a
| counterparty that has an interest in the outcome.
|
*/

return [
    'permissions' => [
        // Marketplace — supply side. `offers.moderate` is intentionally granted
        // to no business role: approving another company's listing is a
        // platform responsibility, so only SuperAdmin (which holds '*') has it.
        'offers.view', 'offers.create', 'offers.update', 'offers.delete',
        'offers.publish', 'offers.cancel', 'offers.moderate',
        // `offers.moderate` decides whether a listing may be seen; `offers.feature`
        // decides where it sits in front of everyone. Placing a company's goods at
        // the head of the market's front page is the platform ranking its own
        // marketplace, so — again — no business standing holds it, whoever pays.
        'offers.feature',

        // Catalog (platform-owned reference data)
        'catalog.manage',

        // Offer community — interest and public questions, both attributed to an
        // organization. Anonymous engagement was not built: a public statement
        // that cannot be traced to a business cannot be moderated, cannot be
        // trusted, and cannot be acted on commercially.
        //
        // Two surfaces deliberately reuse existing vocabulary rather than
        // inventing a permission of their own: uploading a product photograph
        // is a business writing its own catalog row (`catalog.manage`), and
        // filing a legal document is an owner or admin acting inside their own
        // business (`organizations.manage`).
        'offers.like', 'comments.create',
        // Taking down or publishing someone else's public comment is a platform
        // act, so — like `offers.moderate` — no counterparty holds it.
        'community.moderate',

        // Demand side / RFQ. There is no `demands.match`: the engine computes
        // matches, and the action a role takes on one is turning it into a
        // purchase request, which `requests.create` already guards.
        'demands.view', 'demands.create', 'demands.quote',

        // Purchase requests (the buyer's ask, reviewed by the seller)
        'requests.view', 'requests.create', 'requests.update',
        'requests.cancel', 'requests.approve', 'requests.reject',

        // Deals (agreed terms, immutable snapshot). There is no `deals.manage`:
        // the closing acts of an order are the seller's dispatch, the delivery
        // scan and the buyer's receipt, each with its own name and its own actor.
        'deals.view', 'deals.create', 'deals.confirm',
        'deals.dispatch', 'deals.deliver', 'deals.dispute',

        // Locations
        'warehouses.view', 'warehouses.manage',

        // Disputes — "open" is a party right, "resolve" is neutral adjudication
        'disputes.open', 'disputes.resolve',

        // Organization & staffing. `organizations.manage` is what an owner or
        // admin does inside *their own* business; `organizations.verify` is the
        // platform deciding whether a business may trade at all, so no business
        // role holds it.
        'organizations.manage', 'members.manage', 'organizations.verify',

        // Notifications
        'notifications.view',

        // Platform administration (SuperAdmin / platform staff only)
        'users.manage', 'roles.manage', 'settings.manage', 'currencies.manage',
        'audit.view', 'reports.view', 'api.manage',
        'ledger.view',
        // Reading the support queue and answering it. Filing a ticket needs no
        // capability at all — the account this desk exists for is the one whose
        // paperwork was rejected or whose phone cannot get in — but the other
        // side of it is held by nobody but the platform: a counterparty must
        // never read what the other is complaining about.
        'support.manage',
    ],

    /*
    |--------------------------------------------------------------------------
    | Which subject a name may be attached to at all
    |--------------------------------------------------------------------------
    |
    | A list of permissions says what exists. It does not say *who is allowed to
    | be the holder*, and that gap is what this section closes. Before it, the
    | difference between a platform capability and a business one lived only in
    | prose: nothing stopped an admin screen from attaching `offers.moderate` to
    | a company's owner standing, or a script from stamping `offers.create` onto
    | an account — where it would follow that person into every business they
    | ever joined, which is the exact fault the retired `SellerAdmin` roles were
    | removed for.
    |
    | Three answers, and every name in `permissions` must carry exactly one:
    |
    |   * `platform` — an act upon the market or upon somebody else's business:
    |     approving a listing, promoting one, verifying a company, ruling a
    |     dispute, staffing accounts, editing the platform's own settings,
    |     answering the support desk. A counterparty holding one would be judging
    |     its own case, so no organization door may ever attach these.
    |   * `business` — an act a company performs on its own trade, its own shelf,
    |     its own staff. These are held by memberships and by nothing else:
    |     attached to an account they would survive the person leaving the
    |     company, and a grant that outlives the relationship that justified it is
    |     not a grant, it is a leftover.
    |   * `both` — a name that legitimately reads on either side, where the
    |     *difference is made by the context the code runs in and not by the name:
    |     `ledger.view` is one company's books under an organization context and
    |     every company's books without one, `audit.view` and `reports.view`
    |     likewise, and the read names the platform's own reviewer holds to see
    |     what a seller sees of its listings. Isolating these behind a second
    |     name (`ledger.view.own` / `ledger.view.all`) would only relocate the
    |     decision into two places that can disagree; the scoping rule here is
    |     about who may *hold* a name, and the tenant rule in the controllers is
    |     about what holding it *reaches*.
    |
    | `App\Core\Rbac\RbacScope` reads this list and refuses the wrong-direction
    | write by name, and `tests/Feature/RbacMatrixTest.php` asserts the catalog
    | is fully and uniquely covered — a name missing here is a name no one has
    | decided, and a name in two of these lists is a name whose boundary is a
    | rumour.
    |
    */

    'permission_scopes' => [
        'platform' => [
            'offers.moderate', 'offers.feature',
            'community.moderate',
            'organizations.verify',
            'disputes.resolve',
            'users.manage', 'roles.manage', 'settings.manage', 'currencies.manage',
            'support.manage',
        ],

        // Everything not named below and not named above is `business`: the
        // writes a company performs on its own trade.
        'both' => [
            'offers.view', 'demands.view', 'requests.view', 'deals.view',
            'warehouses.view',
            'catalog.manage',
            'notifications.view',
            'audit.view', 'reports.view', 'ledger.view',
        ],

        /*
         | Named rather than left as the remainder, even though `scopeOf()` treats
         | an unnamed name this way. The three lists are a partition, and a
         | partition nobody wrote down cannot be reviewed: a new capability added
         | at the top of this file would otherwise become grantable inside a
         | company the day it was written, silently, by not being mentioned.
         | `RolesAndPermissionsSeeder::assertScopesCover()` fails the seed when a
         | name is missing from all three or appears in two of them.
         */
        'business' => [
            'offers.create', 'offers.update', 'offers.delete',
            'offers.publish', 'offers.cancel', 'offers.like',
            'comments.create',
            'demands.create', 'demands.quote',
            'requests.create', 'requests.update', 'requests.cancel',
            'requests.approve', 'requests.reject',
            'deals.create', 'deals.confirm', 'deals.dispatch', 'deals.deliver',
            'deals.dispute',
            'warehouses.manage',
            'disputes.open',
            'organizations.manage', 'members.manage',
            // Machine credentials are issued by whoever owns the business they act
            // for, so this is a company's decision and not the platform's.
            'api.manage',
        ],
    ],

    /*
    |----------------------------------------------------------------------
    | Person capabilities — the consumer standing (see App\Core\Rbac\RbacScope)
    |----------------------------------------------------------------------
    |
    | A signed-in account with no organization is a consumer of the platform,
    | and §1 of the product brief makes that context first-class rather than an
    | error state. Three names are exercisable by the human alone: pressing
    | "interested" and asking a public question attribute to the person
    | (`user_id` is always stored; `organization_id` is stored when one stands
    | behind the click), and reading notifications is already a per-user read in
    | `NotificationController` — the rows are addressed to the account, never to
    | a company. Everything else — trading, ordering, publishing, warehousing —
    | stays company-only, because a commercial claim needs the registered
    | business behind it that a counterparty can hold to account.
    |
    | Names here must exist in `permissions` and be of the business/both scope;
    | `RbacMatrixTest` asserts both, because a list that grants a platform name
    | to any account is the one way this file could open a moderation door to a
    | stranger.
    |
    */
    'person_capabilities' => [
        'offers.like',
        'comments.create',
        'notifications.view',
    ],

    'roles' => [
        // Platform owner.
        'SuperAdmin' => '*',

        /*
        |----------------------------------------------------------------------
        | Retired: what a person is inside a company
        |----------------------------------------------------------------------
        |
        | These four once held the business grants. They are Spatie roles, so a
        | grant attached to the account rather than to the company: one row made
        | someone a seller administrator everywhere, and leaving a business did
        | not take it back. `membership_permissions` below is where that
        | authority lives now, read per organization.
        |
        | They stay declared with nothing in them so the seeder converges their
        | database rows to empty grants instead of deleting a role that is still
        | attached to an account, and so an old `assignRole('SellerAdmin')` in a
        | test or a script resolves rather than throwing. They are not in
        | `platform_roles`, so no screen offers them, and holding one now means
        | exactly what it says: nothing. When no `model_has_roles` row references
        | them, drop them from this list and the converging seeder removes them.
        |
        */
        'SellerAdmin' => [],
        'SellerStaff' => [],
        'BuyerAdmin' => [],
        'BuyerStaff' => [],

        // Read-only financial/compliance observer across the platform.
        'Auditor' => [
            'offers.view', 'demands.view', 'requests.view', 'deals.view',
            'warehouses.view',
            'notifications.view',
            'ledger.view',
            'audit.view', 'reports.view',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Which of those roles describe the PLATFORM, not a company
    |--------------------------------------------------------------------------
    |
    | The distinction is not cosmetic and the admin console was the place it
    | hurt: a tab headed "أدوار المنصة" offered SellerAdmin and BuyerAdmin,
    | which are what a person is *inside a business*. Assigning one there
    | changed a global Spatie row and left the person's standing in their own
    | company exactly where it was, so the control did not do what its label
    | promised.
    |
    | Business standing lives in `organization_memberships.role`, because it is
    | held per company; platform standing lives here, because it is held by the
    | account. A screen may only offer this list under a platform heading, and
    | `UserManagementController` refuses anything outside it.
    |
    */

    'platform_roles' => ['SuperAdmin', 'Auditor'],

    /*
    |--------------------------------------------------------------------------
    | Standing inside one company
    |--------------------------------------------------------------------------
    |
    | `organization_memberships.role` held three vocabularies at once: the column
    | defaulted to `operator`, the validators accepted
    | `owner,admin,operator,finance,auditor`, and the rows actually stored
    | `owner`, `staff` and `auditor`. A stored value the validator rejects is a
    | dead end — the owner of a company could be read but never re-saved — and a
    | default the validator rejects means every hand-created row starts invalid.
    |
    | So this is the one list. It is what the validators check, what the member
    | screens offer, what the migration maps the old values onto, and what the
    | column constrains. `staff` replaces `operator` because it is the word the
    | data already used and the word an Arabic UI translates cleanly (موظف).
    |
    | `owner` is special and not merely a rank: an organization must always keep
    | at least one active owner, and ownership is the only standing that cannot
    | be granted by an admin.
    |
    */

    'membership_roles' => [
        'owner' => 'مالك المنشأة',
        'admin' => 'مدير المنشأة',
        'staff' => 'موظف',
        'finance' => 'المالية',
        'auditor' => 'مدقق داخلي',
    ],

    /*
    |--------------------------------------------------------------------------
    | What each standing inside a company is allowed to do
    |--------------------------------------------------------------------------
    |
    | This is where business authority actually comes from, and it is the reason
    | `SellerAdmin`, `SellerStaff`, `BuyerAdmin` and `BuyerStaff` above are now
    | empty. Those were Spatie roles, so they attached to the *account*: one row
    | made a person a seller administrator on every company they ever touched,
    | and a person who left a business kept the grant until somebody remembered
    | to uncheck it. Standing inside a company is held per company, in
    | `organization_memberships.role`, so that is what has to be read.
    |
    | `App\Identity\Models\User::hasPermissionTo()` asks this matrix for whatever the
    | active organization context says the user is, so a route guarded by
    | `permission:offers.create` is answered by the membership row and not by a
    | global role. A name that appears here must exist in `permissions` above;
    | tests/Feature/MembershipPermissionsTest.php asserts that, and asserts the
    | other direction as well: no platform permission is reachable this way.
    |
    | The platform-only names are absent by design and can never be added here:
    | `offers.moderate`, `community.moderate`, `organizations.verify`,
    | `disputes.resolve`, `ledger` as a platform book, `users.manage`,
    | `settings.manage`, `currencies.manage`. Approving a competitor's listing,
    | deciding whether a business may trade, ruling on a dispute between two
    | companies and editing the platform's own settings are not things a party to
    | the transaction may do — that is the separation the roles above existed to
    | express and did not enforce.
    |
    | `ledger.view` is the exception worth naming: the ledger routes read
    | `TenantContext`, so a company asking for its own books is a business
    | action, while the platform's view of every company's books comes from the
    | platform roles, which have no organization context.
    |
    */

    'membership_permissions' => [
        // Everything a company can do to itself and to its own trades. Owner is a
        // superset because ownership is the standing that can appoint the others.
        'owner' => [
            'offers.view', 'offers.create', 'offers.update', 'offers.delete',
            'offers.publish', 'offers.cancel', 'offers.like',
            'comments.create',
            'catalog.manage',
            'demands.view', 'demands.create', 'demands.quote',
            'requests.view', 'requests.create', 'requests.update', 'requests.cancel',
            'requests.approve', 'requests.reject',
            'deals.view', 'deals.create', 'deals.confirm', 'deals.dispatch',
            'deals.deliver', 'deals.dispute',
            'warehouses.view', 'warehouses.manage',
            'disputes.open',
            'organizations.manage', 'members.manage',
            'notifications.view',
            'reports.view', 'audit.view', 'ledger.view',
            'api.manage',
        ],

        // Runs the business day to day, including staffing it. Machine
        // credentials outlive any single decision, so issuing them stays with
        // the owner.
        'admin' => [
            'offers.view', 'offers.create', 'offers.update', 'offers.delete',
            'offers.publish', 'offers.cancel', 'offers.like',
            'comments.create',
            'catalog.manage',
            'demands.view', 'demands.create', 'demands.quote',
            'requests.view', 'requests.create', 'requests.update', 'requests.cancel',
            'requests.approve', 'requests.reject',
            'deals.view', 'deals.create', 'deals.confirm', 'deals.dispatch',
            'deals.deliver', 'deals.dispute',
            'warehouses.view', 'warehouses.manage',
            'disputes.open',
            'organizations.manage', 'members.manage',
            'notifications.view',
            'reports.view', 'audit.view', 'ledger.view',
        ],

        // Keeps the shelf, the warehouse and the paperwork moving. Cannot delete
        // a published listing, close out a deal's terms, edit the legal record or
        // change who else works there.
        'staff' => [
            'offers.view', 'offers.create', 'offers.update', 'offers.publish',
            'offers.like', 'comments.create',
            'catalog.manage',
            'demands.view', 'demands.create',
            'requests.view', 'requests.create', 'requests.update', 'requests.cancel',
            'requests.approve',
            'deals.view', 'deals.dispatch', 'deals.deliver', 'deals.dispute',
            'warehouses.view', 'warehouses.manage',
            'disputes.open',
            'notifications.view',
        ],

        // Money terms: confirms the price, sees the books and the reports, and can
        // open a dispute about the amounts. Does not move stock or run the catalog.
        'finance' => [
            'offers.view', 'demands.view', 'requests.view',
            'deals.view', 'deals.confirm', 'deals.dispute',
            'disputes.open',
            'reports.view', 'audit.view', 'ledger.view',
            'notifications.view',
            'comments.create', 'offers.like',
        ],

        // Internal review: reads, never acts. This is the standing that answers a
        // business needing somebody inside it who can see everything and change
        // nothing — the same reason `audit.view` is here and no write is.
        'auditor' => [
            'offers.view', 'demands.view', 'requests.view', 'deals.view',
            'warehouses.view',
            'reports.view', 'audit.view', 'ledger.view',
            'notifications.view',
        ],
    ],
];

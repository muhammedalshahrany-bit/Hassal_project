<?php

return [
    'permissions' => [
        'notifications.view', 'notifications.manage',
        'offers.create', 'offers.view', 'offers.update', 'offers.delete', 'offers.publish', 'offers.cancel',
        'demands.create', 'demands.view', 'demands.match', 'demands.quote',
        'requests.create', 'requests.view', 'requests.update', 'requests.cancel', 'requests.approve', 'requests.reject',
        'deals.create', 'deals.view', 'deals.confirm', 'deals.dispatch', 'deals.deliver', 'deals.dispute', 'deals.manage',
        'ledger.view', 'ledger.export', 'ledger.manage',
        'disputes.open', 'disputes.resolve', 'disputes.manage',
        'organizations.manage', 'members.manage',
        'users.manage', 'settings.manage', 'audit.view', 'reports.view', 'api.manage',
        'warehouses.view', 'warehouses.manage', 'catalog.manage', 'currencies.manage',
    ],

    'roles' => [
        'SuperAdmin' => '*',
        'SellerAdmin' => [
            'notifications.view', 'notifications.manage',
            'offers.create', 'offers.view', 'offers.update', 'offers.delete', 'offers.publish', 'offers.cancel',
            'demands.view', 'demands.match', 'demands.quote',
            'requests.create', 'requests.view', 'requests.update', 'requests.approve', 'requests.reject',
            'deals.create', 'deals.view', 'deals.confirm', 'deals.dispatch', 'deals.deliver', 'deals.manage',
            'ledger.view', 'ledger.export', 'ledger.manage',
            'disputes.open', 'disputes.resolve', 'disputes.manage',
            'organizations.manage', 'members.manage', 'users.manage', 'reports.view',
            'warehouses.view', 'warehouses.manage', 'catalog.manage', 'currencies.manage',
        ],
        'SellerStaff' => [
            'notifications.view',
            'offers.create', 'offers.view', 'offers.update', 'offers.publish',
            'requests.view', 'requests.approve',
            'deals.view', 'deals.dispatch', 'deals.deliver',
            'warehouses.view', 'warehouses.manage', 'catalog.manage',
        ],
        'BuyerAdmin' => [
            'notifications.view', 'notifications.manage',
            'offers.view', 'demands.create', 'demands.view', 'demands.match',
            'requests.create', 'requests.view', 'requests.update', 'requests.cancel', 'requests.approve', 'requests.reject',
            'deals.create', 'deals.view', 'deals.confirm', 'deals.deliver', 'deals.dispute',
            'ledger.view', 'ledger.export', 'ledger.manage',
            'disputes.open', 'disputes.resolve', 'disputes.manage',
            'organizations.manage', 'members.manage', 'reports.view',
        ],
        'BuyerStaff' => [
            'notifications.view',
            'offers.view', 'demands.create', 'demands.view',
            'requests.create', 'requests.view', 'requests.update', 'requests.cancel',
            'deals.view', 'deals.deliver', 'deals.dispute',
        ],
        'Intermediary' => [
            'notifications.view',
            'offers.view', 'offers.create',
            'demands.view', 'demands.match',
            'requests.create', 'requests.view',
            'deals.view', 'deals.manage',
            'ledger.view', 'ledger.export',
            'warehouses.view', 'catalog.manage',
        ],
        'Auditor' => [
            'notifications.view',
            'offers.view', 'requests.view', 'demands.view', 'deals.view', 'ledger.view', 'ledger.export', 'audit.view', 'reports.view',
        ],
    ],
];

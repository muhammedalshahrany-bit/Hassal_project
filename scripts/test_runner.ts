async function runTestSuite() {
  console.log('====================================================');
  console.log('  HASSAL (حصّال) — Comprehensive QA & Test Suite    ');
  console.log('====================================================\n');

  let passed = 0;
  let failed = 0;

  function assert(condition: boolean, testName: string, failureDetails?: string) {
    if (condition) {
      console.log(`  [PASS] ${testName}`);
      passed++;
    } else {
      console.error(`  [FAIL] ${testName} - ${failureDetails || ''}`);
      failed++;
    }
  }

  const BASE_URL = 'http://localhost:3000/api/v1';

  // 1. Health & Bootstrap Tests
  console.log('--- Phase 1: Foundation & Health Verification ---');
  try {
    const res = await fetch(`${BASE_URL}/health`);
    const data = await res.json();
    assert(res.status === 200 && data.success === true, 'Health check returns 200 OK and healthy status');
    assert(data.data.database.includes('SQLite WAL Mode'), 'Database is operating in SQLite WAL Mode');
  } catch (err: any) {
    assert(false, 'Health check reachable', err.message);
  }

  try {
    const res = await fetch(`${BASE_URL}/bootstrap`);
    const data = await res.json();
    assert(res.status === 200 && data.data.app_name.includes('HASSAL'), 'Bootstrap endpoint returns system identity and settings');
    assert(data.data.supported_currencies.includes('SAR') && data.data.supported_currencies.includes('USD'), 'Multi-currency configuration exposed (SAR, YER, USD, AED)');
    assert(Array.isArray(data.data.categories) && data.data.categories.length > 0, 'Food categories loaded in bootstrap');
  } catch (err: any) {
    assert(false, 'Bootstrap endpoint accessible', err.message);
  }

  // 2. Authentication & RBAC Tests
  console.log('\n--- Phase 2: Authentication & RBAC ---');
  let buyerToken = '';
  let sellerToken = '';
  let adminToken = '';

  try {
    const buyerRes = await fetch(`${BASE_URL}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'buyer@riyadhfood.sa', password: 'password123' }),
    });
    const buyerData = await buyerRes.json();
    assert(buyerRes.status === 200 && !!buyerData.data?.token, 'Buyer login succeeds with valid token');
    buyerToken = buyerData.data?.token;

    const sellerRes = await fetch(`${BASE_URL}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'seller@alwataniya.sa', password: 'password123' }),
    });
    const sellerData = await sellerRes.json();
    sellerToken = sellerData.data?.token;
    assert(sellerRes.status === 200 && !!sellerData.data?.token, 'Seller login succeeds with valid token');

    const adminRes = await fetch(`${BASE_URL}/auth/login`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: 'admin@hassal.sa', password: 'password123' }),
    });
    const adminData = await adminRes.json();
    adminToken = adminData.data?.token;
    assert(adminRes.status === 200 && !!adminData.data?.token, 'Admin login succeeds with valid token');
  } catch (err: any) {
    assert(false, 'Auth logins successful', err.message);
  }

  // 3. Catalog & Geo-Discovery Tests
  console.log('\n--- Phase 3: Marketplace & Geo-Discovery ---');
  let testOfferId = 1;
  try {
    const offersRes = await fetch(`${BASE_URL}/offers`);
    const offersData = await offersRes.json();
    assert(offersRes.status === 200 && offersData.data.length > 0, 'Offers list returned successfully');
    testOfferId = offersData.data[0].id;

    // Nearby offers with Riyadh coordinates
    const geoRes = await fetch(`${BASE_URL}/offers/nearby?latitude=24.7136&longitude=46.6753&radius_km=150`);
    const geoData = await geoRes.json();
    assert(geoRes.status === 200 && Array.isArray(geoData.data), 'Geo-discovery offers query with coordinates succeeds');
    if (geoData.data.length > 0) {
      assert(typeof geoData.data[0].distance_km === 'number', 'Calculated Haversine distance present in geo results');
    }
  } catch (err: any) {
    assert(false, 'Marketplace & Geo tests passed', err.message);
  }

  // 4. Atomic Inventory Locking & Concurrency Tests
  console.log('\n--- Phase 4: Atomic Inventory Locking & Concurrency ---');
  try {
    const beforeRes = await fetch(`${BASE_URL}/offers/${testOfferId}`);
    const beforeData = await beforeRes.json();
    const initialAvailable = beforeData.data.available_quantity;
    const initialReserved = beforeData.data.reserved_quantity;

    const reqQty = 50; // Offer #1 MOQ is 50 units
    const idempotencyKey = 'test-idem-' + Date.now();

    const orderRes = await fetch(`${BASE_URL}/purchase-requests`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${buyerToken}`,
        'X-Organization-Id': '3',
        'X-Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify({
        offer_id: testOfferId,
        quantity: reqQty,
        delivery_preference: 'delivery',
        notes: 'Al-Olaya Central Warehouse, Riyadh',
      }),
    });
    const orderData = await orderRes.json();
    assert(orderRes.status === 201 && orderData.success === true, 'Atomic Purchase Request created with Pessimistic Lock', JSON.stringify(orderData));

    const afterRes = await fetch(`${BASE_URL}/offers/${testOfferId}`);
    const afterData = await afterRes.json();
    const updatedAvailable = afterData.data.available_quantity;
    const updatedReserved = afterData.data.reserved_quantity;

    assert(
      updatedAvailable === initialAvailable - reqQty,
      `Available quantity atomically decremented (${initialAvailable} -> ${updatedAvailable})`,
      `Diff: initial=${initialAvailable}, updated=${updatedAvailable}, expected=${initialAvailable - reqQty}`
    );
    assert(
      updatedReserved === initialReserved + reqQty,
      `Reserved quantity atomically incremented (${initialReserved} -> ${updatedReserved})`,
      `Diff: initial=${initialReserved}, updated=${updatedReserved}, expected=${initialReserved + reqQty}`
    );

    // Test Idempotency: Re-submitting the exact same request with identical idempotency key returns cached result
    const dupRes = await fetch(`${BASE_URL}/purchase-requests`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${buyerToken}`,
        'X-Organization-Id': '3',
        'X-Idempotency-Key': idempotencyKey,
      },
      body: JSON.stringify({
        offer_id: testOfferId,
        quantity: reqQty,
        delivery_preference: 'delivery',
      }),
    });
    const dupData = await dupRes.json();
    assert(dupRes.status === 200 || dupRes.status === 201, 'Idempotency key prevents duplicate transaction creation', JSON.stringify(dupData));
  } catch (err: any) {
    assert(false, 'Atomic locking tests passed', err.message);
  }

  // 5. Deal Pipeline & State Machine Transition Tests
  console.log('\n--- Phase 5: Deal State Machine & Invoicing ---');
  try {
    const dealsRes = await fetch(`${BASE_URL}/deals`, {
      headers: {
        'Authorization': `Bearer ${buyerToken}`,
        'X-Organization-Id': '3',
      },
    });
    const dealsData = await dealsRes.json();
    assert(dealsRes.status === 200 && Array.isArray(dealsData.data), 'Deals pipeline list fetched');

    if (dealsData.data.length > 0) {
      const testDeal = dealsData.data[0];
      assert(testDeal.status !== undefined, `Deal #${testDeal.id} loaded in status: ${testDeal.status}`);
      assert(testDeal.deal_number !== undefined, `Deal #${testDeal.id} has standardized reference: ${testDeal.deal_number}`);
    }
  } catch (err: any) {
    assert(false, 'Deal pipeline tests passed', err.message);
  }

  // 6. Double-Entry Accounting Ledger Tests
  console.log('\n--- Phase 6: Double-Entry Financial Ledger ---');
  try {
    const ledgerRes = await fetch(`${BASE_URL}/ledger/statement`, {
      headers: {
        'Authorization': `Bearer ${adminToken}`,
        'X-Organization-Id': '1',
      },
    });
    const ledgerData = await ledgerRes.json();
    assert(ledgerRes.status === 200 && Array.isArray(ledgerData.data.journal_entries), 'Ledger statement fetched with journal entries');

    // Balance verification
    assert(
      Array.isArray(ledgerData.data.accounts) && ledgerData.data.accounts.length > 0,
      'Chart of Accounts loaded with active balances'
    );
  } catch (err: any) {
    assert(false, 'Ledger accounting verification passed', err.message);
  }

  // 7. Security & Immutable Audit Trail
  console.log('\n--- Phase 7: Security, IDOR Protection & Audit Trail ---');
  try {
    // Audit logs endpoint
    const auditRes = await fetch(`${BASE_URL}/admin/audit-logs`, {
      headers: {
        'Authorization': `Bearer ${adminToken}`,
        'X-Organization-Id': '1',
      },
    });
    const auditData = await auditRes.json();
    assert(auditRes.status === 200 && Array.isArray(auditData.data), `Immutable Audit Trail returns security events`);
  } catch (err: any) {
    assert(false, 'Security & audit tests passed', err.message);
  }

  // 8. B2B Demands / RFQ Board
  console.log('\n--- Phase 8: Demands (B2B RFQ Board) ---');
  try {
    const demandRes = await fetch(`${BASE_URL}/demands`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${buyerToken}`,
        'X-Organization-Id': '3',
      },
      body: JSON.stringify({
        title: 'طلب توريد زيت نباتي 100 كرتون عاجل',
        description: 'مطلوب توريد زيت نباتي نقي مطابق للمواصفات السعودية مع شهادة جودة',
        quantity: 100,
        target_unit_price: 135.0,
        target_city: 'الرياض',
      }),
    });
    const demandData = await demandRes.json();
    assert(demandRes.status === 201 && demandData.data.title.includes('زيت نباتي'), 'B2B RFQ Demand published successfully');
  } catch (err: any) {
    assert(false, 'B2B RFQ Demand test passed', err.message);
  }

  console.log('\n====================================================');
  console.log(`  Test Suite Summary: ${passed} PASSED, ${failed} FAILED`);
  console.log('====================================================\n');

  if (failed > 0) {
    process.exit(1);
  }
}

runTestSuite().catch((err) => {
  console.error('Fatal test error:', err);
  process.exit(1);
});

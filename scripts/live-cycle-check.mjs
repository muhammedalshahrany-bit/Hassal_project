/**
 * live-cycle-check.mjs — the one commercial cycle, walked over HTTP against a
 * running backend, from a real offer to a closed order.
 *
 *   العرض → طلب شراء → قبول البائع → حجز الكمية → إنشاء أمر شراء
 *        → التسليم → مسح كود التسليم → تأكيد الاستلام → إغلاق الصفقة
 *
 * Why a script: every other check in this repository reads a file. This one
 * asks the server. It is the only place where a state-machine rule, a
 * permission, a reservation and a delivery code are all forced to agree in
 * front of one witness — which is exactly what §5 of the brief asked for
 * ("لا تكتفِ بتعديل الملفات؛ تأكد أن النظام يعمل فعليًا end-to-end").
 *
 * Three orders are raised, because three questions can only be asked by rows
 * that each sit somewhere different:
 *
 *   A — the whole cycle, and the case the scan exists for: delivered but
 *       unscanned, where neither closing step is allowed. Then scanned, and
 *       closed.
 *   B — another order's live code, refused at A's gate, and an order called off
 *       before the goods move: the code dies with it and the shelf takes the
 *       quantity back.
 *   C — a third order left delivered and unscanned, so that nothing written
 *       after it can be mistaken for the scan having happened.
 *
 * It is written to be re-run. Every row is raised from an offer that is still
 * on the market at the moment the script runs, so the seeded demo world is left
 * as `sandbox:verify` found it, only busier.
 *
 * Run:
 *   cd backend && php artisan serve --host=127.0.0.1 --port=8000
 *   HASSAL_SANDBOX_PASSWORD='…' node scripts/live-cycle-check.mjs
 *
 * Optional: HASSAL_API (default http://127.0.0.1:8000/api/v1). Point it at
 * production only if you intend the rows it writes to be real.
 */

import { api, login, CODE } from './lib/api.mjs';

// The one platform operator, needed for exactly one check: that a ruling is the
// platform's act and nobody else's. Read from the environment, never written
// into this file.
const ADMIN = process.env.HASSAL_ADMIN_PASSWORD ?? '';

let step = 0;
const fails = [];

function check(ok, label, detail = '') {
  step += 1;
  console.log(`${ok ? '  ok  ' : ' FAIL '}${String(step).padStart(2, '0')}. ${label}${detail ? `\n         ${detail}` : ''}`);
  if (!ok) fails.push(label);
}

function eq(actual, expected, label, extra = '') {
  check(String(actual) === String(expected), label, `expected ${expected}, got ${actual}${extra ? ` — ${extra}` : ''}`);
}

const code = (r) => r.json?.error?.code ?? r.json?.code ?? `HTTP ${r.status}`;
const msg = (r) => r.json?.error?.message ?? r.json?.message ?? '';
const key = (tag) => ({ 'X-Idempotency-Key': `${tag}-${Date.now()}-${Math.random().toString(36).slice(2, 8)}` });

// ── Sessions ────────────────────────────────────────────────────────────────

const seller = await login('amal.owner@hassal.test', CODE);        // org 1, seller
const buyer = await login('sultan.owner@hassal.test', CODE);      // org 4, buyer
const sellerStaff = await login('amal.staff@hassal.test', CODE);  // org 1, lacks deals.confirm
const outsider = await login('nakhil.staff@hassal.test', CODE);   // org 5, not a party of A

if (!ADMIN) { console.error('Set HASSAL_ADMIN_PASSWORD: the platform ruling is one of the checks.'); process.exit(1); }
console.log(`sessions: seller org ${seller.active?.id}, buyer org ${buyer.active?.id}\n`);

const S = (m, p, o = {}) => api(m, p, { token: seller.token, org: seller.active?.id, ...o });
const B = (m, p, o = {}) => api(m, p, { token: buyer.token, org: buyer.active?.id, ...o });

/** The seller's own walk of an order, one recorded step at a time. */
const sellerMove = (id, status, extra = {}, tag = '') =>
  S('POST', `/deals/${id}/transition`, { body: { status, ...extra }, headers: key(`${status}${tag}`) });

/** Ask → the seller holds it → the buyer opens an order against the hold. */
async function openOrder(offer, tag) {
  const ask = await B('POST', '/purchase-requests', {
    body: { offer_id: offer.id, requested_quantity: offer.min_order_quantity, delivery_preference: 'PICKUP', notes: 'دورة حيّة' },
    headers: key(`ask-${tag}`),
  });
  if (ask.status !== 201) return { failure: `ask: ${code(ask)} ${msg(ask)}` };

  const held = await S('POST', `/purchase-requests/${ask.json.data.id}/accept`, { headers: key(`acc-${tag}`) });
  const reservationId = held.json?.data?.reservation?.id;
  if (!reservationId) return { failure: `accept: ${code(held)} ${msg(held)}` };

  const deal = await B('POST', '/deals', {
    body: { reservation_id: reservationId, delivery_method: 'PICKUP', notes: 'دورة حيّة' },
    headers: key(`deal-${tag}`),
  });
  if (deal.status !== 201) return { failure: `deal: ${code(deal)} ${msg(deal)}` };

  return { request: ask.json.data, reservationId, deal: deal.json.data };
}

// ── What the market offers, and the two refusals at its door ────────────────

const offers = await B('GET', '/marketplace/offers?per_page=100');
const mine = (o) => Number(o.seller_organization?.id ?? o.seller_organization_id) === Number(seller.active?.id);
const shelf = (o) => Number(o.available_quantity);
const [offerA, offerB, offerC] = (offers.json?.data ?? []).filter(o => mine(o) && shelf(o) >= Number(o.min_order_quantity));

if (!offerC) {
  console.error('The seller needs three live offers for this check; seed the sandbox.');
  process.exit(1);
}

const selfAsk = await S('POST', '/purchase-requests', {
  body: { offer_id: offerA.id, requested_quantity: offerA.min_order_quantity }, headers: key('self'),
});
eq(code(selfAsk), 'SELF_PURCHASE_FORBIDDEN', 'a business cannot buy from itself');

const tooMuch = await B('POST', '/purchase-requests', {
  body: { offer_id: offerA.id, requested_quantity: shelf(offerA) + 1 }, headers: key('over'),
});
eq(code(tooMuch), 'QUANTITY_EXCEEEDS_AVAILABLE', 'an ask bigger than the shelf is refused at the door');

// ── Order A: the ask, the hold, the order ───────────────────────────────────

const asked = await B('POST', '/purchase-requests', {
  body: { offer_id: offerA.id, requested_quantity: offerA.min_order_quantity, delivery_preference: 'PICKUP', notes: 'دورة حيّة' },
  headers: key('askA'),
});
eq(asked.status, 201, 'the buyer raises an ask on a live offer', msg(asked));
const request = asked.json?.data;
eq(request?.status, 'REQUESTED', 'the ask arrives as REQUESTED');
check(!request?.reservation_id, 'asking itself holds nothing', 'the hold appears with the seller’s answer');

const notMine = await api('POST', `/purchase-requests/${request.id}/accept`, {
  token: outsider.token, org: outsider.active?.id, headers: key('notmine'),
});
// Party, not permission: `outsider` does hold requests.approve, in its own
// organization. That difference is the point of the row.
eq(code(notMine), 'SELLER_ACCEPT_DENIED', 'only the seller organization may accept');

const heldA = await S('POST', `/purchase-requests/${request.id}/accept`, { headers: key('accA') });
eq(heldA.status, 200, 'the seller accepts, and the quantity is held', msg(heldA));
const reservationId = heldA.json?.data?.reservation?.id;
check(Number(reservationId) > 0, 'the acceptance produced a hold', `reservation ${reservationId}`);

const answered = (await B('GET', `/purchase-requests/${request.id}`)).json?.data;
eq(answered?.status, 'ACCEPTED', 'the ask reads as ACCEPTED afterwards');

const holdRow = (await B('GET', '/marketplace/reservations?per_page=100')).json?.data?.find(r => r.id === Number(reservationId));
eq(holdRow?.status, 'ACTIVE', 'the buyer sees the hold as ACTIVE');

const shelfNow = (await B('GET', `/marketplace/offers/${offerA.id}`)).json?.data;
eq(Number(shelfNow?.available_quantity), shelf(offerA) - Number(offerA.min_order_quantity),
  'the shelf moved by exactly the held quantity', `${offerA.available_quantity} → ${shelfNow?.available_quantity}`);

const expiredHolds = await B('GET', '/marketplace/reservations?status=EXPIRED&per_page=100');
const expiredHold = (expiredHolds.json?.data ?? [])[0];
if (expiredHold) {
  const onExpired = await B('POST', '/deals', { body: { reservation_id: expiredHold.id, delivery_method: 'PICKUP' }, headers: key('exp') });
  eq(code(onExpired), 'RESERVATION_EXPIRED', 'a dead hold cannot open an order');
} else {
  check(true, 'no expired hold belongs to this buyer — RESERVATION_EXPIRED left untested live');
}

const openedA = await B('POST', '/deals', {
  body: { reservation_id: reservationId, delivery_method: 'PICKUP', notes: 'دورة حيّة' }, headers: key('dealA'),
});
eq(openedA.status, 201, 'the buyer converts the hold into an order', msg(openedA));
const orderId = openedA.json?.data?.id;
eq(openedA.json?.data?.status, 'PENDING_SELLER_APPROVAL', 'the order opens awaiting the seller');

const reusedHold = await B('POST', '/deals', { body: { reservation_id: reservationId, delivery_method: 'PICKUP' }, headers: key('reuse') });
check(reusedHold.status >= 400, 'one hold opens one order', `${reusedHold.status} ${code(reusedHold)}`);

const earlyHandover = await B('GET', `/deals/${orderId}/handover`);
eq(code(earlyHandover), 'HANDOVER_NOT_ISSUED', 'there is nothing to scan until the order is accepted');

const jump = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'COMPLETED' }, headers: key('jump') });
check(jump.status >= 400, 'an order cannot skip to closed', `${jump.status} ${code(jump)}`);

const bogus = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'SHIPPED' }, headers: key('bogus') });
eq(code(bogus), 'DEAL_STATE_UNKNOWN', 'a made-up state is refused as a made-up state');

const acceptedA = await sellerMove(orderId, 'ACCEPTED', {}, '-A');
eq(acceptedA.json?.data?.status, 'ACCEPTED', 'the seller confirms the order', msg(acceptedA));

const handover = (await B('GET', `/deals/${orderId}/handover`)).json?.data;
check(!!handover?.code, 'acceptance issued the delivery code', 'the read returned no object');
check(/^[23456789ACDEFGHJKLMNPQRTUVWXYZ]{16}$/.test(handover?.code ?? ''), 'the code is 16 characters from the unambiguous alphabet', handover?.code);
eq(handover?.qr_payload, `HASSAL:HANDOVER:${handover?.deal_number}:${handover?.code}`, 'the QR payload carries the order number and the code');
eq(handover?.status, 'ACTIVE', 'the code is ACTIVE');
eq(handover?.verifiable, false, 'it cannot be spent yet — the goods have not arrived');

const tooEarly = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: handover.code }, headers: key('early') });
eq(code(tooEarly), 'HANDOVER_NOT_AT_DELIVERY', 'scanning before delivery is refused as such');

for (const status of ['PROCESSING', 'READY_FOR_PICKUP', 'DELIVERED']) {
  const r = await sellerMove(orderId, status, {}, '-A');
  eq(r.json?.data?.status, status, `the seller records ${status}`, msg(r));
}

// ── Orders B and C, both walked to the same place ───────────────────────────

const second = await openOrder(offerB, 'b');
check(!!second.deal?.id, 'a second order opens against a second hold', second.failure ?? '');
const otherId = second.deal?.id;

const third = await openOrder(offerC, 'c');
check(!!third.deal?.id, 'a third order opens against a third hold', third.failure ?? '');
const thirdId = third.deal?.id;

// B stops one step short of arrival, because it is the order that gets called
// off; C goes all the way to DELIVERED and is never scanned, because it is the
// order that proves the gate is a rule and not one order's accident.
for (const status of ['ACCEPTED', 'PROCESSING', 'READY_FOR_PICKUP']) {
  const r = await sellerMove(otherId, status, {}, '-B');
  eq(r.json?.data?.status, status, `order B reaches ${status}`, msg(r));
}
for (const status of ['ACCEPTED', 'PROCESSING', 'READY_FOR_PICKUP', 'DELIVERED']) {
  const r = await sellerMove(thirdId, status, {}, '-C');
  eq(r.json?.data?.status, status, `order C reaches ${status}`, msg(r));
}

const otherHandover = (await B('GET', `/deals/${otherId}/handover`)).json?.data;
const thirdHandover = (await B('GET', `/deals/${thirdId}/handover`)).json?.data;
check(/^[23456789ACDEFGHJKLMNPQRTUVWXYZ]{16}$/.test(otherHandover?.code ?? ''), 'the second order holds its own delivery code', otherHandover?.code);
eq(thirdHandover?.status, 'ACTIVE', 'the third order’s code is issued, live, and untouched');

// ── The gate, before anything is scanned ────────────────────────────────────
//
// Two refusals a person at the phone has to be able to tell apart: the code is
// wrong, and the code is fine but nobody has scanned it yet.

const badCode = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: 'NOTAREALCODE1234' }, headers: key('bad') });
eq(code(badCode), 'HANDOVER_CODE_INVALID', 'a code that is not this order’s is refused');

const cross = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: otherHandover.code }, headers: key('cross') });
eq(code(cross), 'HANDOVER_CODE_INVALID', "another order's live code does not open this one",
  'refused as a bad code, deliberately: telling the two apart would hand a scanner a way to probe other orders');

const noCloseInspection = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'INSPECTION' }, headers: key('noscan-insp') });
eq(code(noCloseInspection), 'HANDOVER_NOT_VERIFIED', 'no scan, no inspection step', msg(noCloseInspection));

const noCloseCompleted = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'COMPLETED', notes: 'بلا مسح' }, headers: key('noscan-done') });
eq(code(noCloseCompleted), 'HANDOVER_NOT_VERIFIED', 'no scan, no closed order — receipt cannot be confirmed for what was never attested', msg(noCloseCompleted));

eq((await B('GET', `/deals/${orderId}`)).json?.data?.status, 'DELIVERED', 'and the order is still sitting at DELIVERED');

const noPermission = await api('POST', `/deals/${orderId}/transition`, {
  token: sellerStaff.token, org: sellerStaff.active?.id,
  body: { status: 'CANCELLED', notes: 'محاولة بلا صلاحية' }, headers: key('noperm'),
});
eq(code(noPermission), 'DEAL_TRANSITION_FORBIDDEN', 'a staffer without deals.confirm cannot close a deal');

const outsiderMove = await api('POST', `/deals/${orderId}/transition`, {
  token: outsider.token, org: outsider.active?.id, body: { status: 'INSPECTION' }, headers: key('outsider'),
});
eq(code(outsiderMove), 'DEAL_ACCESS_DENIED', 'a business that is not a party cannot touch the order');

// ── The scan, and what it changes ───────────────────────────────────────────

const atDelivery = (await B('GET', `/deals/${orderId}/handover`)).json?.data;
eq(atDelivery?.verifiable, true, 'at DELIVERED the code is spendable');

// The code is the buyer's to present and the seller's to spend: at the door the
// buyer shows what is on their order, and the party that brought the goods reads
// it and saves. A business that is neither still cannot spend it.
const outsiderScan = await api('POST', `/deals/${orderId}/handover/verify`, {
  token: outsider.token, org: outsider.active?.id, body: { code: handover.code }, headers: key('outsider-scan'),
});
eq(code(outsiderScan), 'DEAL_ACCESS_DENIED', 'a third party cannot spend someone else\'s delivery code');

const payload = await S('POST', `/deals/${orderId}/handover/verify`, {
  body: { code: `HASSAL:HANDOVER:${handover.deal_number}:${handover.code}`, notes: 'مسح البائع عند الباب' }, headers: key('scan'),
});
eq(payload.status, 201, 'the seller spends the buyer\'s code, printed QR payload and all', msg(payload));
eq(payload.json?.data?.deal_status, 'DELIVERED', 'the scan records arrival; it does not close the order');
eq(payload.json?.data?.next_step, 'buyer_confirms_receipt', 'and it says whose move is next');
eq(payload.json?.data?.status, 'USED', 'the code is spent');
eq(payload.json?.data?.verified_by_organization_id, seller.active?.id, 'and the record names the seller organization as the one that scanned');

const replay = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: handover.code }, headers: key('replay') });
eq(code(replay), 'HANDOVER_ALREADY_USED', 'the same code cannot be spent twice — not even by the other party');

const sameKey = key('idem');
const firstTry = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: handover.code }, headers: sameKey });
const secondTry = await B('POST', `/deals/${orderId}/handover/verify`, { body: { code: handover.code }, headers: sameKey });
eq(code(secondTry), code(firstTry), 'one idempotency key answers once', `${firstTry.status} then ${secondTry.status}`);

const toInspection = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'INSPECTION' }, headers: key('insp') });
eq(toInspection.json?.data?.status, 'INSPECTION', 'after the scan, the inspection step opens', msg(toInspection));

const closed = await B('POST', `/deals/${orderId}/transition`, { body: { status: 'COMPLETED', notes: 'استُلمت البضاعة' }, headers: key('done') });
eq(closed.json?.data?.status, 'COMPLETED', 'the buyer closes the order', msg(closed));

eq((await B('GET', `/deals/${orderId}`)).json?.data?.status, 'COMPLETED', 'and the order reads as closed on re-read');
eq((await B('GET', `/deals/${orderId}/handover`)).json?.data?.status, 'USED', 'the code is left as spent, not deleted');

// ── Order B: called off before the goods move ───────────────────────────────
//
// Cancellation is reached from READY_FOR_PICKUP, not from DELIVERED: once the
// goods have arrived, the dispute route — not the cancel button — is the way
// out. That is a decision, not an accident, and it is why B stops here.

const shelfBeforeCancel = Number((await B('GET', `/marketplace/offers/${offerB.id}`)).json?.data?.available_quantity);

const silentCancel = await sellerMove(otherId, 'CANCELLED', {}, '-B');
check(silentCancel.status >= 400, 'a cancellation with no word in it is refused', `${silentCancel.status} ${code(silentCancel)} ${msg(silentCancel)}`);

const cancel = await sellerMove(otherId, 'CANCELLED', { notes: 'إلغاء قبل الاستلام في الاختبار' }, '-B');
eq(cancel.json?.data?.status, 'CANCELLED', 'the order can be called off before it arrives', msg(cancel));

eq((await B('GET', `/deals/${otherId}/handover`)).json?.data?.status, 'INVALIDATED', 'cancelling retires its delivery code');
const spentCancelled = await B('POST', `/deals/${otherId}/handover/verify`, { body: { code: otherHandover.code }, headers: key('cancelled') });
eq(code(spentCancelled), 'HANDOVER_INVALIDATED', 'and the retired code cannot be spent');

const shelfAfterCancel = Number((await B('GET', `/marketplace/offers/${offerB.id}`)).json?.data?.available_quantity);
eq(shelfAfterCancel, shelfBeforeCancel + Number(offerB.min_order_quantity), 'the held quantity went back to the shelf', `${shelfBeforeCancel} → ${shelfAfterCancel}`);

const holdAfter = (await B('GET', '/marketplace/reservations?per_page=100')).json?.data?.find(r => r.id === Number(second.reservationId));
eq(holdAfter?.status, 'CONVERTED_TO_DEAL', 'the hold itself stays as it was: it was spent on the order, and the order — not the hold — was called off');

// An order that has arrived cannot be called off at all.
const cancelAfterArrival = await sellerMove(thirdId, 'CANCELLED', { notes: 'محاولة إلغاء بعد الوصول' }, '-C');
check(cancelAfterArrival.status >= 400, 'after arrival the way out is the dispute, not the cancel button',
  `${cancelAfterArrival.status} ${code(cancelAfterArrival)}`);

// ── Order C: the dispute, the ruling, and the scan that still has to happen ──

const cStaysOpen = await B('POST', `/deals/${thirdId}/transition`, { body: { status: 'COMPLETED', notes: 'بلا مسح' }, headers: key('c-noclose') });
eq(code(cStaysOpen), 'HANDOVER_NOT_VERIFIED', 'the third order refuses closure too — the gate is a rule, not one order’s accident');
eq((await B('GET', `/deals/${thirdId}/handover`)).json?.data?.status, 'ACTIVE', 'and its code is still unspent after the attempt');

const badReason = await B('POST', '/disputes', {
  body: { deal_id: thirdId, reason: 'DAMAGE', description: 'سبب غير معروف', claimed_amount: 1 }, headers: key('bad-reason'),
});
eq(code(badReason), 'VALIDATION_FAILED', 'a dispute must name one of the reasons the platform records');

const opened = await B('POST', '/disputes', {
  body: { deal_id: thirdId, reason: 'QUANTITY_MISMATCH', description: 'وصل عدد أقل من الموثق في الطلب', claimed_amount: 1000 },
  headers: key('dispute'),
});
eq(opened.status, 201, 'a party opens a dispute on the delivered order', msg(opened));
const dispute = opened.json?.data;
eq(dispute?.status, 'OPEN', 'the claim opens');
eq((await B('GET', `/deals/${thirdId}`)).json?.data?.status, 'DISPUTED', 'and the order moves to DISPUTED with it');

const secondClaim = await B('POST', '/disputes', {
  body: { deal_id: thirdId, reason: 'WRONG_ITEM', description: 'بلاغ ثانٍ على نفس الصفقة', claimed_amount: 500 },
  headers: key('twice'),
});
eq(code(secondClaim), 'DISPUTE_ALREADY_OPEN', 'one order carries one open claim at a time');

const businessRuling = await B('POST', `/disputes/${dispute.id}/resolve`, { body: { status: 'RESOLVED_RELEASE', notes: 'قرار من منشأة' }, headers: key('selfrule') });
// Refused by the permission gate before the controller's staff-role check is
// ever reached: a business session does not hold `disputes.resolve` at all, so
// the second door — SuperAdmin — is the one that catches a session that does.
eq(code(businessRuling), 'PERMISSION_DENIED', 'the platform decides; a party to the deal cannot rule on its own claim');

const admin = await login('alshhranymuha39@gmail.com', ADMIN);
const blankRuling = await api('POST', `/disputes/${dispute.id}/resolve`, {
  token: admin.token, body: { status: 'RESOLVED_RELEASE' }, headers: key('blank-rule'),
});
eq(code(blankRuling), 'VALIDATION_FAILED', 'a ruling with no reasons written is refused');

const ruling = await api('POST', `/disputes/${dispute.id}/resolve`, {
  token: admin.token, body: { status: 'RESOLVED_RELEASE', notes: 'بعد مراجعة الطرفين: الصفقة ماضية' }, headers: key('rule'),
});
eq(ruling.status, 200, 'the single platform operator rules on the claim', msg(ruling));
eq(ruling.json?.data?.status, 'RESOLVED_RELEASE', 'and the claim reads as ruled');
eq((await B('GET', `/deals/${thirdId}`)).json?.data?.status, 'RESOLVED', 'the order carries the decision, not the claim');

const stillNoClosure = await B('POST', `/deals/${thirdId}/transition`, { body: { status: 'COMPLETED', notes: 'قرار بلا مسح' }, headers: key('rule-noscan') });
eq(code(stillNoClosure), 'HANDOVER_NOT_VERIFIED', 'a ruling does not substitute for the scan: the order still cannot close unscanned', msg(stillNoClosure));

const cCode = (await B('GET', `/deals/${thirdId}/handover`)).json?.data;
eq(cCode?.verifiable, true, 'and its code is spendable at the gate the machine now allows');

// The server's gate is the code and the state, not the party: order A shows the
// seller spending the buyer's presented code, and this shows a scan from the
// buyer's own session still being accepted — a printed label is spent by
// whichever business is holding it, and `verified_by_organization_id` records
// whose hand did it rather than deciding in advance who was allowed to.
const cScan = await B('POST', `/deals/${thirdId}/handover/verify`, { body: { code: cCode.code }, headers: key('scan-c') });
eq(cScan.status, 201, 'so the scan goes through', msg(cScan));

const cClosed = await B('POST', `/deals/${thirdId}/transition`, { body: { status: 'COMPLETED', notes: 'بعد المسح والقرار' }, headers: key('close-c') });
eq(cClosed.json?.data?.status, 'COMPLETED', 'and only then does the order close', msg(cClosed));

// ── Report ──────────────────────────────────────────────────────────────────

console.log(`\n${fails.length === 0 ? 'OK' : 'FAILED'} — ${step} checks, ${fails.length} failing`);
for (const f of fails) console.log(`   · ${f}`);
process.exit(fails.length === 0 ? 0 : 1);

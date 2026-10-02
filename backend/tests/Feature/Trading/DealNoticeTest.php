<?php

namespace Tests\Feature\Trading;

use App\Trading\Models\Deal;
use App\Trading\Models\DealHandover;
use App\Notifications\Models\Notification;
use App\Identity\Models\User;
use App\Auth\Support\PreferenceCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * What a deal notice says, who it goes to, and what it points at.
 *
 * The complaint this suite answers was that a deal notification «لا توفر معلومات
 * كافية عن كل صفقة ومن البائع ولا توجهه نحو الصفقة المطلوبة». Three separate
 * failures hide in that sentence, and a test that only checks "a row was written"
 * would pass against all three:
 *
 *   1. *Content.* A notice that names only a deal number cannot be acted on,
 *      because the person has to open the app and find the row to learn what it
 *      is about. So the goods, the quantity, the money and the other party are
 *      asserted as text, in the body a shade actually renders.
 *   2. *Authorship.* A state change with no name attached leaves the reader
 *      guessing whether they caused it. The mover is named; a platform decision
 *      says it was the platform.
 *   3. *Routing.* The body is prose; the tap is `data`. A notice whose payload
 *      carries no `deal_id` opens a list, not the order, which is the second
 *      half of the complaint. Every notice here is read back as the array the
 *      client routes on, and `type` is asserted because that token is what the
 *      preference gate and the bell icon both read.
 *
 * Silence is tested too. The acting side is left out of its own news, an account
 * that muted deal traffic is not told anything, and a notice is written once per
 * event rather than once per member times the number of things that changed.
 */
class DealNoticeTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    /**
     * The deal notice one account holds about one order, newest first.
     *
     * `sole()` rather than `first()`: two rows for one event is the bug, not the
     * noise, and a helper that quietly returned one of them would let a
     * double-write through.
     */
    private function noticeFor(User $user, Deal $deal): Notification
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('data->deal_id', $deal->id)
            ->latest('id')
            ->firstOrFail();
    }

    /**
     * How many deal notices this account holds about this order.
     */
    private function noticeCount(User $user, Deal $deal): int
    {
        return Notification::query()
            ->where('user_id', $user->id)
            ->where('data->deal_id', $deal->id)
            ->count();
    }

    public function test_the_seller_is_told_what_is_being_asked_and_of_whom(): void
    {
        [, $buyer, $sellerUser, $seller, $offer, $deal] = $this->dealInFlight(40.0);

        $deal->load('items');
        $item = $deal->items->first();
        $this->assertNotNull($item, 'A deal without its snapshot line cannot be described.');

        $notice = $this->noticeFor($sellerUser, $deal);

        // The number is in the title because the shade shows the title. A
        // reference that only lives in the folded second line is a reference
        // nobody reads before deciding whether to open the app.
        $this->assertStringContainsString($deal->deal_number, $notice->title_ar);
        $this->assertStringContainsString('بانتظار موافقتك', $notice->title_ar);

        // What, how much, for how much, from whom, and what is being asked.
        $this->assertStringContainsString((string) $item->snapshot_product_name, $notice->body_ar);
        $this->assertStringContainsString('40 '.$item->snapshot_unit_name, $notice->body_ar);
        $this->assertStringContainsString('الإجمالي 1,000,000.00 '.$offer->currency_code, $notice->body_ar);
        $this->assertStringContainsString($buyer->name_ar, $notice->body_ar);
        $this->assertStringContainsString('الموافقة أو الرفض', $notice->body_ar);

        // The payload is the other half of the complaint: a notice that must be
        // hunted for in a list is a notice that does not direct anyone.
        $this->assertSame('DEAL', $notice->data['type']);
        $this->assertSame($deal->id, (int) $notice->data['deal_id']);
        $this->assertSame($deal->deal_number, $notice->data['deal_number']);
        $this->assertSame((int) $seller->id, (int) $notice->data['organization_id']);

        // Read on the seller's account: the notice belongs to the business that
        // must answer, not to whoever happened to press the button.
        $this->assertSame($sellerUser->id, $notice->user_id);
        $this->assertFalse((bool) $notice->is_read);
    }

    public function test_the_buyer_hears_that_their_order_left_the_handset(): void
    {
        [$buyerUser, $buyer, , $seller, , $deal] = $this->dealInFlight(40.0);

        $notice = $this->noticeFor($buyerUser, $deal);

        $this->assertStringContainsString($deal->deal_number, $notice->title_ar);
        $this->assertStringContainsString($seller->name_ar, $notice->body_ar);
        $this->assertStringContainsString('بانتظار ردّ البائع', $notice->body_ar);
        $this->assertSame('DEAL', $notice->data['type']);
        $this->assertSame($deal->id, (int) $notice->data['deal_id']);
        $this->assertSame((int) $buyer->id, (int) $notice->data['organization_id']);
    }

    public function test_a_move_is_announced_to_the_other_side_and_named_to_it(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $before = $this->noticeCount($sellerUser, $deal);

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        $notice = $this->noticeFor($buyerUser, $deal);

        $this->assertStringContainsString('الصفقة صارت مقبولة', $notice->title_ar);
        $this->assertStringContainsString($deal->deal_number, $notice->title_ar);
        $this->assertStringContainsString($seller->name_ar.' أجرت هذا التغيير', $notice->body_ar);
        $this->assertStringContainsString('حالتها الآن: مقبولة', $notice->body_ar);
        $this->assertSame('DEAL', $notice->data['type']);
        $this->assertSame($deal->id, (int) $notice->data['deal_id']);
        $this->assertSame((int) $buyer->id, (int) $notice->data['organization_id']);

        // The seller pressed the button a second ago; their own screen already
        // shows the order as accepted. Telling them what they just did is the
        // echo that trains a person to ignore the notices that are not.
        $this->assertSame($before, $this->noticeCount($sellerUser, $deal));
    }

    public function test_a_platform_decision_is_attributed_to_the_platform_and_reaches_both(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        $platform = $this->platformUser();

        // The platform acts without an acting organization of its own: it is the
        // one actor that is not a party, and the notice has to say so rather than
        // naming a business that did not move anything. The reason is required
        // text — cancelling an order without one is refused by the state machine
        // for every actor, the platform included.
        $this->move($deal->id, Deal::STATUS_CANCELLED, $platform, $buyer, [
            'notes' => 'البضاعة المعروضة لا تطابق ما استُلم',
        ])->assertOk();

        foreach ([$buyerUser, $sellerUser] as $recipient) {
            $notice = $this->noticeFor($recipient, $deal);

            $this->assertStringContainsString('ملغاة', $notice->title_ar);
            $this->assertStringContainsString('قرّرت المنصة هذا التغيير', $notice->body_ar);
            $this->assertSame('DEAL', $notice->data['type']);
        }
    }

    public function test_a_spent_delivery_code_is_announced_to_the_side_that_did_not_scan_it(): void
    {
        [$buyerUser, $buyer, $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_PROCESSING, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DISPATCHED, $sellerUser, $seller)->assertOk();
        $this->move($deal->id, Deal::STATUS_DELIVERED, $sellerUser, $seller)->assertOk();

        $code = DealHandover::query()->where('deal_id', $deal->id)->value('code');
        $this->assertNotNull($code);

        $staff = $this->memberOf($buyer, 'staff');
        $noticesBefore = $this->noticeCount($sellerUser, $deal);

        $this->scan($deal->id, $code, $staff, $buyer)->assertCreated();

        $notice = $this->noticeFor($sellerUser, $deal);

        $this->assertStringContainsString('تم التحقق من رمز التسليم', $notice->title_ar);
        $this->assertStringContainsString($deal->deal_number, $notice->title_ar);
        $this->assertStringContainsString($buyer->name_ar.' مسح الرمز', $notice->body_ar);
        $this->assertStringContainsString('تأكيد الاستلام', $notice->body_ar);
        $this->assertSame('DEAL', $notice->data['type']);
        $this->assertSame($deal->id, (int) $notice->data['deal_id']);

        // The scan is one event; the seller's account gains one row, whatever
        // number of people sit in that business.
        $this->assertSame($noticesBefore + 1, $this->noticeCount($sellerUser, $deal));
    }

    public function test_an_account_that_muted_deal_traffic_hears_nothing(): void
    {
        [$buyerUser, , $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->assertSame(1, $this->noticeCount($buyerUser, $deal), 'The creation notice is the baseline.');

        // Written through the endpoint the app uses, so the switch the test
        // mutes is the switch a person can actually reach.
        $this->putJson('/api/v1/auth/preferences', [
            'preferences' => [PreferenceCatalog::NOTIFY_DEALS => false],
        ])->assertOk();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        $this->assertSame(1, $this->noticeCount($buyerUser->fresh(), $deal));
    }

    public function test_an_account_that_muted_notifications_entirely_hears_nothing(): void
    {
        [$buyerUser, , $sellerUser, $seller, , $deal] = $this->dealInFlight();

        $this->putJson('/api/v1/auth/preferences', [
            'preferences' => [PreferenceCatalog::NOTIFICATIONS_ENABLED => false],
        ])->assertOk();

        $this->move($deal->id, Deal::STATUS_ACCEPTED, $sellerUser, $seller)->assertOk();

        $this->assertSame(1, $this->noticeCount($buyerUser->fresh(), $deal));
    }
}

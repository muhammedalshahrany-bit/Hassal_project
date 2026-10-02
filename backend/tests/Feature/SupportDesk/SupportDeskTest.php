<?php

namespace Tests\Feature\SupportDesk;

use App\Notifications\Models\Notification;
use App\SupportDesk\Models\SupportTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\HassalFixtures;
use Tests\TestCase;

/**
 * The desk a person reaches when the thing that broke is the platform.
 *
 * Nothing else in this schema can hold that sentence. A comment report is one
 * business objecting to another business's words; a dispute is two companies
 * disagreeing about a deal; both are answered by `community.moderate` and
 * `disputes.resolve`, and neither is where "the shelf screen lost my price"
 * belongs. So the four facts these tests hold down are the ones that make the
 * difference real rather than nominal:
 *
 *   * Filing asks for a session and no grant. A person whose business paperwork
 *     is stuck is exactly the person who must be able to complain about it.
 *   * One report is one report. The same words from the same person minutes
 *     apart are refused with the first report's number, so the queue counts
 *     problems rather than double-taps.
 *   * Only `support.manage` reads across reporters, and no business standing
 *     holds it — a competitor closing your report is not a support model.
 *   * `resolved` cannot be reached without saying something to the reporter, and
 *     the reporter is told when it is.
 */
class SupportDeskTest extends TestCase
{
    use HassalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatformDefaults();
    }

    private function report(\App\Identity\Models\User $user, array $attributes = []): \Illuminate\Testing\TestResponse
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/support/tickets', array_merge([
            'category' => SupportTicket::CATEGORY_FUNCTIONAL_BUG,
            'message'  => 'عند حفظ السعر في الرفّ يعود الحقل فارغًا رغم أن الطلب ينجح.',
            'screen'   => 'seller/shelf',
        ], $attributes));
    }

    public function test_any_signed_in_person_can_file_and_read_their_own_report(): void
    {
        [$buyer, $organization] = $this->accountWithOrganization(null, 'buyer', 'مشتري');

        // An owner with every business standing, and a plain account with none:
        // both file, and neither needed a grant to do it.
        $this->report($buyer)->assertCreated()
            ->assertJsonPath('data.status', SupportTicket::STATUS_OPEN)
            ->assertJsonPath('data.organization_id', $organization->id);

        $bare = $this->user('زائر بلا منشأة');
        $filed = $this->report($bare, ['category' => SupportTicket::CATEGORY_SUGGESTION])
            ->assertCreated()
            ->assertJsonPath('data.organization_id', null)
            ->json('data');

        $ticket = SupportTicket::find($filed['id']);
        $this->assertSame($bare->id, $ticket->user_id);
        $this->assertMatchesRegularExpression('/^SUP-\d{5}$/', $ticket->reference);
        $this->assertSame($ticket->reference, SupportTicket::referenceFor($ticket->id), 'The number a person is handed is the row they can quote.');

        Sanctum::actingAs($bare);
        $own = $this->getJson('/api/v1/support/tickets')->assertOk()->json('data');
        $this->assertCount(1, $own, 'A person reads their own reports, not the platform’s inbox.');
    }

    public function test_the_same_report_twice_is_one_report_with_a_number(): void
    {
        [$user] = $this->accountWithOrganization(null, 'seller', 'بائع');

        $first = $this->report($user)->assertCreated()->json('data.reference');
        $again = $this->report($user)->assertStatus(409)
            ->assertJsonPath('error.code', 'DUPLICATE_REPORT')
            ->assertJsonPath('error.details.reference', $first);
        unset($again);

        $this->assertSame(1, SupportTicket::count());

        // The refusal is about the *report*, not the person: a different problem
        // from the same handset five minutes later is a second report.
        $this->report($user, [
            'category' => SupportTicket::CATEGORY_WRONG_DATA,
            'message'  => 'اسم المدينة في بطاقة العرض لا يطابق ما أدخلته في المستودع.',
        ])->assertCreated();
        $this->assertSame(2, SupportTicket::count());
    }

    public function test_the_queue_is_the_platforms_and_reading_another_report_is_not(): void
    {
        [$seller, $sellerOrg] = $this->accountWithOrganization(null, 'seller', 'بائع');
        [$other, $otherOrg] = $this->accountWithOrganization(null, 'buyer', 'مشترٍ آخر');

        $this->report($seller)->assertCreated();
        $this->report($other, ['message' => 'البحث القريب لا يعرض أي شيء رغم أن الموقع مسموح.'])
            ->assertCreated();

        // An owner — the highest standing a company can hold — sees only their
        // own row, and cannot enter the desk at all.
        Sanctum::actingAs($seller);
        $this->getJson('/api/v1/support/tickets')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/support/queue')->assertForbidden();
        $this->putJson('/api/v1/support/tickets/1/answer', ['status' => 'resolved', 'reply' => 'تم'],
            ['Idempotency-Key' => 'not-the-platform'])->assertForbidden();

        // The second reporter's own list holds their own report and nothing of
        // the first one's: scoping is by who filed, not by who is asking nicely.
        Sanctum::actingAs($other);
        $rows = $this->getJson('/api/v1/support/tickets')->assertOk()->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('البحث القريب لا يعرض أي شيء رغم أن الموقع مسموح.', $rows[0]['message']);

        unset($sellerOrg, $otherOrg);
    }

    public function test_a_platform_agent_reads_the_queue_and_answers_with_a_reply(): void
    {
        [$reporter] = $this->accountWithOrganization(null, 'seller', 'بائع');
        $reference = $this->report($reporter)->assertCreated()->json('data.reference');
        $ticket = SupportTicket::sole();

        $admin = $this->accountWithOrganization('SuperAdmin', 'buyer', 'الدعم')[0];
        Sanctum::actingAs($admin);

        $queue = $this->getJson('/api/v1/support/queue')->assertOk();
        $queue->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', $reference);
        // Where it happened is what makes the queue sortable by screen, which is
        // the difference between "users are unhappy" and "the shelf loses prices".
        $queue->assertJsonPath('data.0.screen', 'seller/shelf');

        // And *who* said it: an answer has to reach a named person, and a queue
        // that cannot trace two reports back to one account is a queue that
        // answers them twice. The staff side reads the author; the reporter's
        // own list does not carry the column at all (asserted at the end).
        $queue->assertJsonPath('data.0.filed_by', $reporter->name);

        $this->getJson('/api/v1/support/summary')->assertOk()
            ->assertJsonPath('data.by_status.open', 1)
            ->assertJsonPath('data.awaiting_attention', 1);

        // Claiming is allowed without an answer — it says somebody is on it.
        $this->putJson("/api/v1/support/tickets/{$ticket->id}/answer", ['status' => 'in_review'],
            ['Idempotency-Key' => 'claim-1'])->assertOk()
            ->assertJsonPath('data.status', 'in_review');

        // Closing without saying anything is not closing.
        $this->putJson("/api/v1/support/tickets/{$ticket->id}/answer", ['status' => 'resolved'],
            ['Idempotency-Key' => 'silent-close'])->assertStatus(422)
            ->assertJsonPath('error.code', 'REPLY_REQUIRED');

        $this->assertSame(0, Notification::where('user_id', $reporter->id)->where('type', 'SUPPORT')->count());

        $this->putJson("/api/v1/support/tickets/{$ticket->id}/answer", [
            'status' => 'resolved',
            'reply'  => 'صحّحنا الحقل في البناء الأخير؛ أعد المحاولة وأبلغنا إن بقي.',
        ], ['Idempotency-Key' => 'close-1'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.answered_by', $admin->name);

        $this->assertNotNull($ticket->fresh()->answered_at);

        // The reporter is told, and is told in a shape their client can open.
        $notice = Notification::where('user_id', $reporter->id)->where('type', 'SUPPORT')->sole();
        $this->assertStringContainsString($reference, $notice->title_ar);
        $this->assertSame($ticket->id, $notice->data['support_ticket_id']);

        // And the answer is readable from their own side of the door.
        Sanctum::actingAs($reporter);
        $this->getJson('/api/v1/support/tickets')->assertOk()
            ->assertJsonPath('data.0.status', 'resolved')
            ->assertJsonPath('data.0.reply', 'صحّحنا الحقل في البناء الأخير؛ أعد المحاولة وأبلغنا إن بقي.')
            // Who answered is the platform's own record, not part of what a
            // reporter is owed.
            ->assertJsonPath('data.0.answered_by', null)
            // Neither is their own name: the reporter knows who they are, and
            // the column exists so the *desk* can address somebody.
            ->assertJsonPath('data.0.filed_by', null)
            ->assertJsonPath('data.0.organization_name', null);
    }

    public function test_what_the_client_says_about_itself_is_limited_to_labels(): void
    {
        [$user] = $this->accountWithOrganization(null, 'seller', 'بائع');

        $this->report($user, [
            'context' => [
                'platform'    => 'android',
                'app_version' => '1.0.0+1',
                'token'       => 'a-bearer-token-does-not-belong-here',
                'email'       => 'someone@example.test',
            ],
        ])->assertCreated();

        $context = SupportTicket::sole()->context;
        $this->assertSame(['platform' => 'android', 'app_version' => '1.0.0+1'], $context);
    }
}

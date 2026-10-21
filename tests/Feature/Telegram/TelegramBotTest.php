<?php

namespace Tests\Feature\Telegram;

use App\Filament\Pages\Telegram;
use App\Models\TelegramLink;
use App\Models\TelegramLinkCode;
use App\Models\TelegramUpdate;
use App\Models\User;
use App\Services\Telegram\Bot;
use App\Services\Telegram\Linker;
use App\Support\Access;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramBotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['telegram.bot_token' => '123456:TEST', 'telegram.bot_username' => 'hijaz_test_bot', 'portal.second_factor_required' => false]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1, 'username' => 'hijaz_test_bot']])]);
    }

    private function update(int $id, int $chat, string $text, array $from = []): array
    {
        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $id,
                'chat' => ['id' => $chat, 'type' => 'private'],
                'from' => array_merge(['id' => $chat, 'first_name' => 'Ali', 'username' => 'ali'], $from),
                'text' => $text,
            ],
        ];
    }

    private function deliver(array $update, ?string $secret = null)
    {
        return $this->postJson('/api/telegram/webhook', $update, [
            'X-Telegram-Bot-Api-Secret-Token' => $secret ?? Bot::webhookSecret(),
        ]);
    }

    private function sentTexts(): array
    {
        $texts = [];
        Http::assertSent(function ($request) use (&$texts): bool {
            if (str_ends_with($request->url(), '/sendMessage')) {
                $texts[] = $request['text'];
            }

            return true;
        });

        return $texts;
    }

    public function test_the_webhook_is_off_without_a_token(): void
    {
        config(['telegram.bot_token' => null]);
        $this->deliver($this->update(1, 10, '/help'))->assertNotFound();
    }

    public function test_the_webhook_refuses_the_wrong_secret(): void
    {
        $this->deliver($this->update(1, 10, '/help'), 'wrong')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_a_stranger_is_told_how_to_link_once_a_day(): void
    {
        $this->deliver($this->update(1, 10, 'hello'))->assertOk();
        $this->deliver($this->update(2, 10, 'hello again'))->assertOk();

        $texts = $this->sentTexts();
        $this->assertCount(1, $texts);
        $this->assertStringContainsString('sign in to the portal', $texts[0]);
    }

    public function test_a_retried_delivery_is_answered_once(): void
    {
        $this->deliver($this->update(7, 10, 'hello'))->assertOk();
        $this->deliver($this->update(7, 10, 'hello'))->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, TelegramUpdate::count());
        $this->assertCount(1, $this->sentTexts());
    }

    public function test_a_code_from_the_portal_links_the_chat_to_the_account(): void
    {
        $user = User::factory()->create(['name' => 'Aminath'])->assignRole(Access::OPERATIONS);
        $code = app(Linker::class)->issueCode($user);

        $this->deliver($this->update(1, 555, "/start {$code}"))->assertOk();

        $link = TelegramLink::active()->where('chat_id', 555)->firstOrFail();
        $this->assertTrue($link->user->is($user));
        $this->assertSame('ali', $link->telegram_username);
        $this->assertStringContainsString('Linked to <b>Aminath</b>', $this->sentTexts()[0]);

        // Used once.
        $this->deliver($this->update(2, 556, "/start {$code}"))->assertOk();
        $this->assertFalse(TelegramLink::active()->where('chat_id', 556)->exists());
    }

    public function test_an_expired_code_or_an_inactive_account_does_not_link(): void
    {
        $user = User::factory()->create()->assignRole(Access::OPERATIONS);
        $code = app(Linker::class)->issueCode($user);
        $this->travel(11)->minutes();

        $this->deliver($this->update(1, 555, "/start {$code}"))->assertOk();
        $this->assertSame(0, TelegramLink::count());

        $this->travelBack();
        $inactive = User::factory()->create(['is_active' => false])->assignRole(Access::OPERATIONS);
        $code = app(Linker::class)->issueCode($inactive);
        $this->deliver($this->update(2, 555, "/start {$code}"))->assertOk();
        $this->assertSame(0, TelegramLink::count());
    }

    public function test_a_new_link_replaces_the_old_one_for_that_account_and_that_chat(): void
    {
        $linker = app(Linker::class);
        $a = User::factory()->create()->assignRole(Access::OPERATIONS);
        $b = User::factory()->create()->assignRole(Access::FINANCE);

        $linker->redeem($linker->issueCode($a), 100);
        $linker->redeem($linker->issueCode($a), 200); // same account, new phone
        $linker->redeem($linker->issueCode($b), 200); // same phone, other account

        $this->assertSame(1, TelegramLink::active()->count());
        $this->assertTrue(TelegramLink::active()->first()->user->is($b));
    }

    public function test_a_linked_chat_gets_help_me_and_today(): void
    {
        $user = User::factory()->create(['name' => 'Hassan', 'email' => 'hassan@example.com'])->assignRole(Access::FINANCE);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 900);

        $this->deliver($this->update(1, 900, '/help'))->assertOk();
        $this->deliver($this->update(2, 900, '/me'))->assertOk();
        $this->deliver($this->update(3, 900, '/today'))->assertOk();
        $this->deliver($this->update(4, 900, '/whatever'))->assertOk();

        $texts = $this->sentTexts();
        $this->assertStringContainsString('/today', $texts[0]);
        $this->assertStringContainsString('hassan@example.com', $texts[1]);
        $this->assertStringContainsString('Role: Finance', $texts[1]);
        $this->assertStringContainsString('Good morning, Hassan', $texts[2]);
        $this->assertStringContainsString("don't know that one", $texts[3]);
    }

    public function test_unlink_stops_the_chat_acting_as_the_account(): void
    {
        $user = User::factory()->create()->assignRole(Access::OPERATIONS);
        app(Linker::class)->redeem(app(Linker::class)->issueCode($user), 900);

        $this->deliver($this->update(1, 900, '/unlink'))->assertOk();

        $this->assertSame(0, TelegramLink::active()->count());
        $this->deliver($this->update(2, 900, '/help'))->assertOk();
        $this->assertStringContainsString('sign in to the portal', $this->sentTexts()[1]);
    }

    public function test_the_daily_summary_goes_to_linked_active_staff_only(): void
    {
        $linker = app(Linker::class);
        $active = User::factory()->create()->assignRole(Access::OPERATIONS);
        $gone = User::factory()->create()->assignRole(Access::OPERATIONS);
        $linker->redeem($linker->issueCode($active), 1);
        $linker->redeem($linker->issueCode($gone), 2);
        $gone->update(['is_active' => false]);

        $this->artisan('telegram:daily')->expectsOutputToContain('Sent 1 summaries')->assertSuccessful();
    }

    public function test_the_webhook_command_registers_this_site_with_the_secret(): void
    {
        config(['app.url' => 'https://hijaz.rihla.mv']);

        $this->artisan('telegram:webhook')->assertSuccessful();

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/setWebhook')
            && $request['url'] === 'https://hijaz.rihla.mv/api/telegram/webhook'
            && $request['secret_token'] === Bot::webhookSecret());
    }

    public function test_the_webhook_command_is_a_no_op_without_a_token(): void
    {
        config(['telegram.bot_token' => null]);
        $this->artisan('telegram:webhook')->assertSuccessful();
        Http::assertNothingSent();
    }

    public function test_the_portal_page_issues_a_code_and_a_deep_link(): void
    {
        $user = User::factory()->create()->assignRole(Access::REGISTRATION);

        $this->actingAs($user)->get('/portal/telegram')->assertOk()->assertSee('Get a link code');

        Livewire::actingAs($user)->test(Telegram::class)
            ->callAction('issue')
            ->assertSee('https://t.me/hijaz_test_bot?start=');

        $this->assertSame(1, TelegramLinkCode::where('user_id', $user->id)->count());
    }

    public function test_the_portal_page_says_so_when_the_bot_is_off(): void
    {
        config(['telegram.bot_token' => null]);
        $user = User::factory()->create()->assignRole(Access::REGISTRATION);

        $this->actingAs($user)->get('/portal/telegram')->assertOk()->assertSee('not set up yet');
    }
}

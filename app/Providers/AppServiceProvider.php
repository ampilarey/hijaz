<?php

namespace App\Providers;

use App\Models\Account;
use App\Models\Agreement;
use App\Models\Booking;
use App\Models\BookingLine;
use App\Models\BookingPilgrim;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Customer;
use App\Models\Discount;
use App\Models\Document;
use App\Models\ExchangeRate;
use App\Models\ExcursionBooking;
use App\Models\ExcursionParticipant;
use App\Models\Incident;
use App\Models\Island;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentSchedule;
use App\Models\Pilgrim;
use App\Models\PortalAccess;
use App\Models\PriceTier;
use App\Models\RollCall;
use App\Models\RollCallMark;
use App\Models\Room;
use App\Models\RoomAssignment;
use App\Models\Setting;
use App\Models\TelegramLink;
use App\Models\Trip;
use App\Models\TripCost;
use App\Models\TripExcursion;
use App\Models\TripFlight;
use App\Models\TripGroup;
use App\Models\TripHotel;
use App\Models\UsdAllocation;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\Access;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The customer portal's tighter limits each count on their own; Laravel's
        // inline "throttle:10,1" would share one counter with the page limit,
        // so a customer who had opened a few pages could not sign in.
        foreach (['my-login' => 10, 'my-sign' => 10, 'my-upload' => 20] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request): Limit => Limit::perMinute($perMinute)->by($name.'|'.$request->ip()));
        }

        // Admin may do everything, including verbs added by later phases
        // before anyone has thought about which role gets them.
        Gate::before(fn (User $user): ?bool => $user->hasRole(Access::ADMIN) ? true : null);

        Password::defaults(fn () => Password::min(8));

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }
    }

    /**
     * Models whose every change is written to the audit trail. Each phase
     * adds its own; a model holding money or identity is never left off.
     *
     * @var list<class-string<Model>>
     */
    public const AUDITED = [
        User::class,
        Account::class,
        ExchangeRate::class,
        Island::class,
        Setting::class,
        TelegramLink::class,
        Trip::class,
        TripFlight::class,
        TripHotel::class,
        TripExcursion::class,
        TripCost::class,
        PriceTier::class,
        Customer::class,
        Pilgrim::class,
        Document::class,
        Booking::class,
        BookingPilgrim::class,
        BookingLine::class,
        Discount::class,
        PaymentSchedule::class,
        Payment::class,
        PaymentAllocation::class,
        ExcursionBooking::class,
        ExcursionParticipant::class,
        BusGroup::class,
        TripGroup::class,
        Agreement::class,
        Room::class,
        RoomAssignment::class,
        Card::class,
        LedgerCategory::class,
        LedgerEntry::class,
        UsdAllocation::class,
        RollCall::class,
        RollCallMark::class,
        Incident::class,
        PortalAccess::class,
    ];
}

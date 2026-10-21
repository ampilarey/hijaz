<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Trip;
use App\Observers\AuditObserver;
use App\Services\Finance\ReportDocument;
use App\Services\Finance\Reports;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** One route for every report: which one, its filters, and PDF or CSV. */
class ReportController extends Controller
{
    public function __construct(private readonly Reports $reports) {}

    public function show(Request $request): Response|StreamedResponse
    {
        abort_unless($request->user()?->can('report.view'), 403);

        $kind = (string) $request->query('report', '');
        abort_unless(array_key_exists($kind, Reports::KINDS), 404);

        $report = self::build($this->reports, $kind, $request->query());
        $format = (string) $request->query('format', 'pdf');
        AuditObserver::record($request->user(), AuditLog::DOWNLOADED, null, ['report' => $kind, 'format' => $format, 'filters' => $request->only(['trip', 'year', 'account', 'from', 'to'])]);

        if ($format === 'csv') {
            return response()->streamDownload(fn () => print $report->csv(), $report->filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return $this->reports->pdf($report, letter: $kind === 'usd_list')->stream($report->filename.'.pdf');
    }

    /**
     * @param  array<string, mixed>  $q
     */
    public static function build(Reports $reports, string $kind, array $q): ReportDocument
    {
        $trip = filled($q['trip'] ?? null) ? Trip::query()->findOrFail($q['trip']) : null;
        $from = filled($q['from'] ?? null) ? Carbon::parse($q['from']) : null;
        $to = filled($q['to'] ?? null) ? Carbon::parse($q['to']) : null;
        $year = (int) ($q['year'] ?? now()->year);
        $needsTrip = fn (): Trip => $trip ?? abort(422, 'Pick a trip for this report.');

        return match ($kind) {
            'trip_pnl' => $reports->tripProfit($needsTrip()),
            'trip_summary' => $reports->tripSummary($needsTrip()),
            'yearly' => $reports->yearly($year),
            'cash_book' => $reports->cashBook(Account::query()->findOrFail($q['account'] ?? abort(422, 'Pick an account.')), $from, $to),
            'purchases' => $reports->currencyPurchases($from, $to),
            'outstanding' => $reports->outstanding($trip),
            'collections' => $reports->collections($trip, $from, $to),
            'discounts' => $reports->discounts($trip, $from, $to),
            'documents' => $reports->documentsStatus($needsTrip()),
            'excursions' => $reports->excursions($needsTrip()),
            'demographics' => $reports->demographics($trip),
            'verifications' => $reports->verifications($trip, $from, $to),
            'usd_list' => $reports->usdList($needsTrip()),
            default => abort(404),
        };
    }
}

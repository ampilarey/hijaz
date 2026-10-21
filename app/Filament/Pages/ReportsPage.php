<?php

namespace App\Filament\Pages;

use App\Filament\Support\TripOptions;
use App\Http\Controllers\ReportController;
use App\Models\Account;
use App\Services\Finance\ReportDocument;
use App\Services\Finance\Reports;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UnitEnum;

/**
 * Every report, with its filters, shown on the page and sent as PDF or
 * CSV. The same report code serves all three.
 *
 * @property-read Schema $form
 */
class ReportsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reports';

    protected static UnitEnum|string|null $navigationGroup = 'Money';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'reports';

    protected string $view = 'filament.pages.reports';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        return (bool) Filament::auth()->user()?->can('report.view');
    }

    public function getTitle(): string|Htmlable
    {
        return 'Reports';
    }

    public function mount(): void
    {
        $this->form->fill([
            'report' => request()->query('report', 'outstanding'),
            'trip' => request()->query('trip'),
            'year' => (int) request()->query('year', now()->year),
            'account' => request()->query('account'),
            'from' => request()->query('from'),
            'to' => request()->query('to'),
        ]);

    }

    public function form(Schema $schema): Schema
    {
        $needsTrip = ['trip_pnl', 'trip_summary', 'documents', 'excursions', 'usd_list'];
        $mayTrip = ['outstanding', 'collections', 'discounts', 'demographics', 'verifications'];
        $hasPeriod = ['cash_book', 'purchases', 'collections', 'discounts', 'verifications'];

        return $schema->statePath('data')->columns(['default' => 1, 'md' => 3])->components([
            Select::make('report')->options(Reports::KINDS)->required()->native(false)->live()->afterStateUpdated(fn () => $this->run()),
            Select::make('trip')->label('Trip')->native(false)->searchable()->options(fn (): array => TripOptions::all())
                ->visible(fn (Get $get): bool => in_array($get('report'), array_merge($needsTrip, $mayTrip), true))
                ->required(fn (Get $get): bool => in_array($get('report'), $needsTrip, true))
                ->live()->afterStateUpdated(fn () => $this->run()),
            TextInput::make('year')->integer()->minValue(2020)->maxValue(2100)->visible(fn (Get $get): bool => $get('report') === 'yearly')->live(onBlur: true)->afterStateUpdated(fn () => $this->run()),
            Select::make('account')->native(false)->options(fn (): array => Account::query()->orderBy('sort_order')->get()->mapWithKeys(fn (Account $a): array => [$a->id => $a->name.' ('.$a->currency.')'])->all())
                ->visible(fn (Get $get): bool => $get('report') === 'cash_book')->required(fn (Get $get): bool => $get('report') === 'cash_book')->live()->afterStateUpdated(fn () => $this->run()),
            DatePicker::make('from')->native(false)->visible(fn (Get $get): bool => in_array($get('report'), $hasPeriod, true))->live()->afterStateUpdated(fn () => $this->run()),
            DatePicker::make('to')->native(false)->visible(fn (Get $get): bool => in_array($get('report'), $hasPeriod, true))->live()->afterStateUpdated(fn () => $this->run()),
        ]);
    }

    /** The report is built on every render from the filters; a Livewire property cannot hold it. */
    public function run(): void
    {
        // Nothing to do: changing a filter re-renders, and render builds the report.
    }

    /** @return array{report: ?ReportDocument, problem: ?string} */
    private function build(): array
    {
        $q = $this->filters();

        if (($q['report'] ?? '') === '') {
            return ['report' => null, 'problem' => null];
        }

        try {
            return ['report' => ReportController::build(app(Reports::class), (string) $q['report'], $q), 'problem' => null];
        } catch (HttpException $e) {
            return ['report' => null, 'problem' => $e->getMessage() ?: 'Pick what the report needs.'];
        }
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return $this->build();
    }

    public function url(string $format): string
    {
        return route('reports.show', array_filter($this->filters() + ['format' => $format], fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string, mixed> */
    private function filters(): array
    {
        $d = $this->data;

        return ['report' => $d['report'] ?? null, 'trip' => $d['trip'] ?? null, 'year' => $d['year'] ?? null, 'account' => $d['account'] ?? null, 'from' => $d['from'] ?? null, 'to' => $d['to'] ?? null];
    }
}

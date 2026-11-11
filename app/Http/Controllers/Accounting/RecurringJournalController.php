<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\RecurringJournalRequest;
use App\Models\Account;
use App\Models\CostCenter;
use App\Models\RecurringJournal;
use App\Services\Accounting\RecurringJournalService;
use App\Support\QueueHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Templat jurnal berulang (izin accounting.recurring.*). Jurnalnya dibuat scheduler setiap hari. */
class RecurringJournalController extends Controller
{
    public function __construct(private readonly RecurringJournalService $recurring) {}

    public function index(): View
    {
        return view('accounting.recurring.index', [
            'templates' => RecurringJournal::query()->with('lines')->withCount('journals')->orderByDesc('is_active')->orderBy('name')->paginate(20),
            'schedulerAlive' => QueueHealth::schedulerAlive(),
        ]);
    }

    public function create(): View
    {
        return view('accounting.recurring.form', $this->formData(new RecurringJournal([
            'interval_months' => 1,
            'day_of_month' => 1,
            'start_on' => now()->addMonthNoOverflow()->startOfMonth(),
            'auto_post' => true,
            'is_active' => true,
        ])));
    }

    public function store(RecurringJournalRequest $request): RedirectResponse
    {
        $template = $this->recurring->save($request->validated(), $request->user());

        return redirect()->route('recurring-journals.show', $template)->with('success', "Jurnal berulang {$template->name} tersimpan. {$template->statusLabel()}.");
    }

    public function show(RecurringJournal $recurringJournal): View
    {
        return view('accounting.recurring.show', [
            'template' => $recurringJournal->load(['lines.account', 'lines.costCenter', 'creator']),
            'journals' => $recurringJournal->journals()->latest('journal_date')->latest('id')->paginate(12),
            'schedulerAlive' => QueueHealth::schedulerAlive(),
        ]);
    }

    public function edit(RecurringJournal $recurringJournal): View
    {
        return view('accounting.recurring.form', $this->formData($recurringJournal->load('lines')));
    }

    public function update(RecurringJournalRequest $request, RecurringJournal $recurringJournal): RedirectResponse
    {
        $template = $this->recurring->save($request->validated(), $request->user(), $recurringJournal);

        return redirect()->route('recurring-journals.show', $template)->with('success', "Jurnal berulang {$template->name} diperbarui. {$template->statusLabel()}.");
    }

    public function destroy(RecurringJournal $recurringJournal): RedirectResponse
    {
        $this->recurring->delete($recurringJournal);

        return redirect()->route('recurring-journals.index')->with('success', "Jurnal berulang {$recurringJournal->name} dihapus. Jurnal yang sudah dibuat tetap ada.");
    }

    /** Buat sekarang jurnal untuk jadwal yang sudah tiba (tanpa menunggu scheduler). */
    public function run(Request $request, RecurringJournal $recurringJournal): RedirectResponse
    {
        $created = $this->recurring->run($recurringJournal);
        $recurringJournal->refresh();

        if ($recurringJournal->last_error) {
            return redirect()->route('recurring-journals.show', $recurringJournal)->with('error', "Jurnal belum bisa dibuat: {$recurringJournal->last_error}");
        }

        return redirect()->route('recurring-journals.show', $recurringJournal)->with('success', $created === []
            ? 'Belum ada jadwal yang tiba. '.$recurringJournal->statusLabel().'.'
            : count($created).' jurnal dibuat: '.collect($created)->pluck('number')->join(', ').'.');
    }

    /** @return array<string, mixed> */
    private function formData(RecurringJournal $template): array
    {
        $used = $template->exists ? $template->lines->pluck('cost_center_id')->filter()->all() : [];

        return [
            'template' => $template,
            'accounts' => Account::query()->postable()->ordered()->get(['id', 'code', 'name', 'type']),
            'costCenters' => CostCenter::query()->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $used ?: [0]))->ordered()->get(['id', 'code', 'name']),
        ];
    }
}

<?php

namespace App\Http\Controllers;

use App\Enums\WorkflowStage;
use App\Models\ItRequest;
use App\Services\ReportingService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV export of the request register.
 *
 * WHY THIS IS A CONTROLLER AND NOT A LIVEWIRE ACTION
 *
 * A Livewire action returns a component re-render. An export returns bytes with a
 * `Content-Disposition` header, and the browser has to treat it as a download rather
 * than as a rendered response — which a plain controller route does and a component
 * cannot.
 *
 * WHY IT STREAMS
 *
 * The register grows without limit. Building the whole CSV in memory is fine at fifty
 * rows and fatal at fifty thousand, and this host has no worker process to move the
 * work to. Streaming costs nothing here and removes the ceiling.
 *
 * WHY THE FILTERS COME FROM THE QUERYSTRING
 *
 * The export must produce the SAME set the screen showed. It therefore accepts exactly
 * the parameters the report puts in its URL and applies them through the same
 * `applyFilters()` the screen uses — so the two cannot disagree. An export with its own
 * filtering logic would be a second definition of "the filtered set", and the recipient
 * of the file has no way to know which is wrong.
 */
class ReportExportController extends Controller
{
    /*
     * The base `Controller` in Laravel's slim skeleton does NOT include
     * `AuthorizesRequests`, so `$this->authorize()` is an undefined method — a 500
     * rather than a 403. The trait has to be pulled in explicitly.
     */
    use AuthorizesRequests;

    public function __invoke(Request $request, ReportingService $reporting): StreamedResponse
    {
        $this->authorize('viewAny', ItRequest::class);

        $filters = [
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'status' => $request->query('status'),
            'stage' => $request->query('stage'),
            'tier_id' => $request->query('tier_id'),
            'classification_id' => $request->query('classification'),
            'governance_route_id' => $request->query('route'),
            'department_id' => $request->query('department'),
            'project_owner_id' => $request->query('owner'),
            'search' => $request->query('q'),
        ];

        // `visibleTo()` is applied inside query(), so the export cannot contain a
        // request the person downloading it could not open on screen.
        $query = $reporting->query(auth()->user(), $filters)
            ->with(['requestor:id,name', 'department:id,name', 'tier:id,name', 'classification:id,name'])
            ->orderBy('request_date')
            ->orderBy('id');

        $filename = 'it-requests-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query, $reporting) {
            $out = fopen('php://output', 'w');

            /*
             * A UTF-8 BOM.
             *
             * Without it, Excel on Windows reads the file as the system codepage and
             * every accented name arrives mangled. Two people who open this file will
             * not notice it is broken; they will notice the names look wrong and blame
             * the data.
             */
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'Request number',
                'Title',
                'Requestor',
                'Department',
                'Tier',
                'Classification',
                'Status',
                'Current stage',
                'Raised',
                'Submitted',
                'Closed',
                'Outcome',
                'Budget (RM)',
                'Age (working days)',
            ]);

            // Iterated in chunks. `cursor()` would hold the whole result set open, and
            // `get()` would materialise it in memory.
            $query->chunk(500, function ($requests) use ($out, $reporting) {
                foreach ($requests as $request) {
                    /*
                     * Money as a plain 2-decimal number.
                     *
                     * `number_format` with a thousands separator would write `16,500.00`,
                     * which a spreadsheet reads as TEXT — so a column of figures would
                     * not sum, and the first person to try would conclude the export is
                     * broken. Unpriced rows get a BLANK, never `0.00`: a zero in a
                     * budget column is a figure somebody will report.
                     */
                    $budget = $request->budget_amount !== null
                        ? number_format((float) $request->budget_amount, 2, '.', '')
                        : '';

                    fputcsv($out, [
                        $request->request_no,
                        $request->title,
                        $request->requestor?->name ?? '',
                        $request->department?->name ?? '',
                        $request->tier?->name ?? '',
                        $request->classification?->name ?? '',
                        // LABELS, not the stored values. A code in a spreadsheet is a
                        // code somebody has to look up.
                        $request->statusEnum()->label(),
                        $request->current_stage
                            ? (WorkflowStage::tryFrom($request->current_stage)?->label() ?? $request->current_stage)
                            : '',
                        $request->request_date?->format('Y-m-d') ?? '',
                        $request->submitted_at?->format('Y-m-d H:i') ?? '',
                        $request->closed_at?->format('Y-m-d H:i') ?? '',
                        $request->outcome ?? '',
                        $budget,
                        $request->statusEnum()->isOpen()
                            ? $reporting->businessDaysSince($request)
                            : '',
                    ]);
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}

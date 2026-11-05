<?php

namespace Modules\Fees\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Academic\Models\Section;
use Modules\Academic\Support\Csv;
use Modules\Fees\Models\FeePayment;
use Modules\Fees\Services\FeeReports;

class ReportController extends \Modules\Academic\Http\Controllers\Controller
{
    public function __construct(private readonly FeeReports $reports)
    {
    }

    public function outstanding(Request $r)
    {
        $asOn = $r->input('as_on', today()->format('Y-m-d'));
        $rows = $this->reports->outstanding($asOn, $r->input('section'));
        if ($r->input('format') === 'csv') {
            return Csv::download('fees-outstanding-'.$asOn.'.csv', ['Admission no', 'Student', 'Class', 'Guardian', 'Phone', ...array_values(FeeReports::BUCKETS), 'Total'],
                $rows->map(fn ($x) => [$x['student']->admission_no, $x['student']->fullName(), $x['student']->currentEnrollment?->section?->label(),
                    $x['student']->primaryGuardian()?->name, $x['student']->primaryGuardian()?->phone,
                    ...array_map(fn ($k) => number_format($x[$k] / 100, 2, '.', ''), array_keys(FeeReports::BUCKETS)), number_format($x['total'] / 100, 2, '.', '')])->all());
        }

        return view('fees::reports.outstanding', ['rows' => $rows, 'asOn' => $asOn, 'sections' => Section::with('level')->where('session_id', $this->session()->id)->get()]);
    }

    public function collections(Request $r)
    {
        $from = $r->input('from', today()->startOfMonth()->format('Y-m-d'));
        $to = $r->input('to', today()->format('Y-m-d'));
        $data = $this->reports->collections($from, $to);
        if ($r->input('format') === 'csv') {
            return Csv::download("fee-collections-{$from}-to-{$to}.csv", ['Date', 'Receipt', 'Admission no', 'Student', 'Mode', 'Reference', 'Amount', 'Refunded', 'Status'],
                $data['list']->map(fn ($p) => [$p->date->format('Y-m-d'), $p->number, $p->student->admission_no, $p->student->fullName(), FeePayment::MODES[$p->mode],
                    $p->reference, number_format($p->amount / 100, 2, '.', ''), number_format($p->refunded / 100, 2, '.', ''), $p->status])->all());
        }

        return view('fees::reports.collections', $data + ['from' => $from, 'to' => $to]);
    }

    public function concessions()
    {
        return view('fees::reports.concessions', ['rows' => $this->reports->concessions()]);
    }
}

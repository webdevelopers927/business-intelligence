<?php

namespace App\Domain\Reports\Types;

use App\Domain\Reports\ReportColumn;
use App\Domain\Reports\ReportParameters;
use App\Domain\Reports\ReportType;
use Illuminate\Support\Facades\DB;

/** Headcount, hires, exits and payroll per department. */
final class WorkforceOverviewReport extends ReportType
{
    public function key(): string
    {
        return 'workforce_overview';
    }

    public function label(): string
    {
        return 'Workforce overview';
    }

    public function description(): string
    {
        return 'Headcount at the end of the period, hires and exits during it, and monthly payroll by department.';
    }

    public function columns(): array
    {
        return [
            new ReportColumn('department', 'Department'),
            new ReportColumn('headcount', 'Headcount', 'number'),
            new ReportColumn('hires', 'Hires', 'number'),
            new ReportColumn('exits', 'Exits', 'number'),
            new ReportColumn('monthly_payroll', 'Monthly payroll', 'currency'),
            new ReportColumn('average_salary', 'Average salary', 'currency'),
        ];
    }

    public function defaultSort(): array
    {
        return ['headcount', 'desc'];
    }

    public function filters(): array
    {
        return ['branches'];
    }

    public function note(): string
    {
        return 'With a branch filter, head-office staff (no branch) are excluded.';
    }

    protected function rows(ReportParameters $p): array
    {
        $from = $p->range->from->toDateString();
        $to = $p->range->to->toDateString();
        $active = 'e.hired_on <= ? AND (e.terminated_on IS NULL OR e.terminated_on > ?)';

        return DB::table('departments as d')
            ->leftJoin('employees as e', function ($join) use ($p) {
                $join->on('e.department_id', '=', 'd.id')->whereNull('e.deleted_at');
                if ($p->filters->branchIds !== []) {
                    $join->whereIn('e.branch_id', $p->filters->branchIds);
                }
            })
            ->where('d.organization_id', $this->tenant->id())
            ->groupBy('d.id', 'd.name')
            ->selectRaw("d.name AS department,
                SUM(CASE WHEN {$active} THEN 1 ELSE 0 END) AS headcount,
                SUM(CASE WHEN e.hired_on BETWEEN ? AND ? THEN 1 ELSE 0 END) AS hires,
                SUM(CASE WHEN e.terminated_on BETWEEN ? AND ? THEN 1 ELSE 0 END) AS exits,
                SUM(CASE WHEN {$active} THEN COALESCE(e.monthly_salary, 0) ELSE 0 END) AS payroll", [$to, $to, $from, $to, $from, $to, $to, $to])
            ->get()
            ->map(fn ($r) => [
                'department' => $r->department,
                'headcount' => (int) $r->headcount,
                'hires' => (int) $r->hires,
                'exits' => (int) $r->exits,
                'monthly_payroll' => (float) $r->payroll,
                'average_salary' => (int) $r->headcount > 0 ? round((float) $r->payroll / (int) $r->headcount, 2) : null,
            ])
            ->all();
    }
}

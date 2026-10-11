<?php

namespace App\Http\Controllers;

use App\Services\Accounting\ChartOfAccounts;

/** The Chart of Accounts page: every group and account with its type and balance. */
class ChartController extends Controller
{
    public function __invoke(ChartOfAccounts $chart)
    {
        $data = $chart->rows();

        return view('chart.index', [
            'rows' => $data['rows'], 'totals' => $chart->totals($data['rows']),
            'fyFrom' => $data['fy_from'], 'priorProfit' => $data['prior_profit'],
            'opDiff' => $chart->openingDifference(), 'booksClosed' => $chart->booksClosed(),
        ]);
    }
}

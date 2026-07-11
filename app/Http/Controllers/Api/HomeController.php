<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaleOrder;
use Illuminate\Http\Request;
use Carbon\Carbon;

class HomeController extends Controller
{
    public function index(Request $request)
    {
        $booker = $request->user();
        $today  = Carbon::today();

        return response()->json([
            'success' => true,
            'data' => [
                'booker_name'    => $booker->name,
                'assigned_area'  => $booker->assigned_area,
                'today_orders'   => SaleOrder::where('booker_id', $booker->id)->whereDate('booked_at', $today)->count(),
                'month_orders'   => SaleOrder::where('booker_id', $booker->id)->whereMonth('booked_at', $today->month)->whereYear('booked_at', $today->year)->count(),
                'pending_status' => SaleOrder::where('booker_id', $booker->id)->whereIn('status', ['draft', 'confirmed'])->count(),
                'last_sync_at'   => $booker->activityLogs()->where('activity_type', 'order_synced')->latest()->value('created_at'),
            ],
        ]);
    }
}
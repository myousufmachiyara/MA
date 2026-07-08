@extends('layouts.app')
@section('title', 'Sale Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

<div class="tabs">

    <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#sale_register" role="tab">Sale Register</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#dispatch_report" role="tab">Dispatch Reports</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#item_wise" role="tab">Item-wise Sale</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#customer_wise" role="tab">Customer-wise Sale</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sale_return" role="tab">Sale Return</a></li>
    </ul>

    <div class="tab-content mt-3" id="reportTabsContent">

        {{-- ══════════════════ TAB 1: SALE REGISTER ══════════════════ --}}
        <div class="tab-pane fade show active" id="sale_register" role="tabpanel">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="sale_register">
                <div class="col-md-2"><input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control"></div>
                <div class="col-md-2"><input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control"></div>
                <div class="col-md-3">
                    <select name="customer_id" class="form-control select2-js">
                        <option value="">All Customers</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="source" class="form-control">
                        <option value="">All Sources</option>
                        <option value="manual" {{ request('source') === 'manual' ? 'selected' : '' }}>Manual / Direct</option>
                        <option value="trip" {{ request('source') === 'trip' ? 'selected' : '' }}>Dispatch Trip</option>
                    </select>
                </div>
                <div class="col-md-1"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i></button></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('sale_register', 'Sale Register')"><i class="fas fa-file-pdf"></i> PDF</button></div>
            </form>

            <div class="table-responsive" id="report-table-sale_register">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice #</th><th>Customer</th><th>Source</th>
                            <th class="text-end">Net</th><th class="text-end">GST</th><th class="text-end">Total</th>
                            <th class="text-end">Paid</th><th class="text-end">Due</th><th class="no-print">Print</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['sale_register'] as $inv)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d-M-Y') }}</td>
                            <td>SI-{{ $inv->invoice_no }}</td>
                            <td>{{ $inv->customer->name ?? 'N/A' }}</td>
                            <td>
                                @if($inv->dispatch_trip_id)
                                    <span class="badge bg-info text-dark">Trip TR-{{ $inv->dispatchTrip->trip_no ?? '' }}</span>
                                @else
                                    <span class="badge bg-secondary">Manual</span>
                                @endif
                            </td>
                            <td class="text-end">{{ number_format($inv->net_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($inv->gst_amount, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($inv->total_amount, 2) }}</td>
                            <td class="text-end">{{ number_format($inv->paid_amount, 2) }}</td>
                            <td class="text-end {{ ($inv->total_amount - $inv->paid_amount) > 0 ? 'text-danger fw-bold' : '' }}">
                                {{ number_format($inv->total_amount - $inv->paid_amount, 2) }}
                            </td>
                            <td class="no-print"><a href="{{ route('sale_invoices.print', $inv->id) }}" target="_blank" class="ref-link"><i class="fas fa-print"></i></a></td>
                        </tr>
                        @empty
                            <tr><td colspan="10" class="text-center text-muted py-3">No sale invoices found in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['sale_register']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Total:</td>
                            <td class="text-end">{{ number_format($reports['sale_register']->sum('net_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['sale_register']->sum('gst_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['sale_register']->sum('total_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['sale_register']->sum('paid_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['sale_register']->sum(fn($i) => $i->total_amount - $i->paid_amount), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 2: DISPATCH REPORT ══════════════════ --}}
        <div class="tab-pane fade" id="dispatch_report" role="tabpanel">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="dispatch_report">
                <div class="col-md-2"><input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control"></div>
                <div class="col-md-2"><input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control"></div>
                <div class="col-md-3">
                    <select name="status" class="form-control">
                        <option value="">All Statuses</option>
                        <option value="planned" {{ request('status') === 'planned' ? 'selected' : '' }}>Planned</option>
                        <option value="dispatched" {{ request('status') === 'dispatched' ? 'selected' : '' }}>Dispatched</option>
                        <option value="settled" {{ request('status') === 'settled' ? 'selected' : '' }}>Settled</option>
                        <option value="cancelled" {{ request('status') === 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('dispatch_report', 'Dispatch Report')"><i class="fas fa-file-pdf"></i> PDF</button></div>
            </form>

            <div class="table-responsive" id="report-table-dispatch_report">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Trip #</th><th>Vehicle</th><th>Delivery Manager</th>
                            <th class="text-end">Orders</th><th class="text-end">Invoices</th><th class="text-end">Amount</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['dispatch_report'] as $trip)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($trip->trip_date)->format('d-M-Y') }}</td>
                            <td>TR-{{ $trip->trip_no }}</td>
                            <td>{{ $trip->vehicle_no }}</td>
                            <td>{{ $trip->deliveryManager->name ?? 'N/A' }}</td>
                            <td class="text-end">{{ $trip->total_orders }}</td>
                            <td class="text-end">{{ $trip->invoices->count() }}</td>
                            <td class="text-end fw-bold">{{ number_format($trip->total_amount, 2) }}</td>
                            <td>
                                @php $badge = ['planned'=>'secondary','dispatched'=>'primary','settled'=>'success','cancelled'=>'danger'][$trip->status] ?? 'secondary'; @endphp
                                <span class="badge bg-{{ $badge }}">{{ ucfirst($trip->status) }}</span>
                            </td>
                        </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No dispatch trips found in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['dispatch_report']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">Total:</td>
                            <td class="text-end">{{ $reports['dispatch_report']->sum('total_orders') }}</td>
                            <td class="text-end">{{ $reports['dispatch_report']->sum(fn($t) => $t->invoices->count()) }}</td>
                            <td class="text-end">{{ number_format($reports['dispatch_report']->sum('total_amount'), 2) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 3: ITEM-WISE SALE ══════════════════ --}}
        <div class="tab-pane fade" id="item_wise" role="tabpanel">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="item_wise">
                <div class="col-md-2"><input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control"></div>
                <div class="col-md-2"><input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control"></div>
                <div class="col-md-3">
                    <select name="customer_id" class="form-control select2-js">
                        <option value="">All Customers</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('item_wise', 'Item-wise Sale')"><i class="fas fa-file-pdf"></i> PDF</button></div>
            </form>

            <div class="table-responsive" id="report-table-item_wise">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Item</th><th>Variation</th><th class="text-end">Qty Sold</th>
                            <th class="text-end">Revenue</th><th class="text-end">COGS</th><th class="text-end">Gross Profit</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['item_wise'] as $row)
                        <tr>
                            <td>{{ $row['item'] }}</td>
                            <td>{{ $row['variation'] }}</td>
                            <td class="text-end">{{ number_format($row['quantity'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['cogs'], 2) }}</td>
                            <td class="text-end fw-bold {{ $row['profit'] < 0 ? 'text-danger' : 'text-success' }}">{{ number_format($row['profit'], 2) }}</td>
                        </tr>
                        @empty
                            <tr><td colspan="6" class="text-center text-muted py-3">No sales found in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['item_wise']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="2" class="text-end">Total:</td>
                            <td class="text-end">{{ number_format($reports['item_wise']->sum('quantity'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['item_wise']->sum('revenue'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['item_wise']->sum('cogs'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['item_wise']->sum('profit'), 2) }}</td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 4: CUSTOMER-WISE SALE ══════════════════ --}}
        <div class="tab-pane fade" id="customer_wise" role="tabpanel">
            <form method="GET" action="{{ route('reports.sale') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="customer_wise">
                <div class="col-md-2"><input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control"></div>
                <div class="col-md-2"><input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control"></div>
                <div class="col-md-3">
                    <select name="customer_id" class="form-control select2-js">
                        <option value="">All Customers</option>
                        @foreach($customers as $c)
                            <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
                <div class="col-md-2"><button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('customer_wise', 'Customer-wise Sale')"><i class="fas fa-file-pdf"></i> PDF</button></div>
            </form>

            <div class="table-responsive" id="report-table-customer_wise">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Customer</th><th class="text-end">Invoices</th><th class="text-end">Qty</th>
                            <th class="text-end">Total</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th><th class="no-print">Ledger</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['customer_wise'] as $row)
                        <tr>
                            <td>{{ $row['customer']->name }}</td>
                            <td class="text-end">{{ $row['invoice_count'] }}</td>
                            <td class="text-end">{{ number_format($row['total_quantity'], 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row['total_amount'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['total_paid'], 2) }}</td>
                            <td class="text-end {{ $row['outstanding'] > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($row['outstanding'], 2) }}</td>
                            <td class="no-print">
                                <a href="{{ route('reports.accounts', ['tab' => 'party_ledger', 'account_id' => $row['customer']->id]) }}" target="_blank" class="ref-link">
                                    View <i class="fas fa-external-link-alt"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No customer sale activity in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['customer_wise']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Total:</td>
                            <td class="text-end">{{ $reports['customer_wise']->sum('invoice_count') }}</td>
                            <td class="text-end">{{ number_format($reports['customer_wise']->sum('total_quantity'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['customer_wise']->sum('total_amount'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['customer_wise']->sum('total_paid'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['customer_wise']->sum('outstanding'), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 5: SALE RETURN (pending finalization) ══════════════════ --}}
        <div class="tab-pane fade" id="sale_return" role="tabpanel">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-1"></i>
                Sale Return report will be finalized together with Purchase Return at the end of the build.
            </div>
        </div>

    </div>
</div>

<script>
function exportReportPDF(reportKey, reportLabel) {
    const tableEl = document.getElementById('report-table-' + reportKey);
    if (!tableEl) return;

    const clone = tableEl.cloneNode(true);
    clone.querySelectorAll('.no-print').forEach(e => e.remove());
    clone.querySelectorAll('a').forEach(a => a.replaceWith(document.createTextNode(a.textContent.trim())));

    const html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + reportLabel + '</title>'
        + '<style>body{font-family:Arial,sans-serif;font-size:11px;margin:20px}'
        + 'h2{font-size:14px;margin-bottom:10px}table{width:100%;border-collapse:collapse}'
        + 'th{background:#1a1a2e;color:#fff;padding:5px 7px;text-align:left}'
        + 'td{padding:4px 7px;border-bottom:0.5px solid #ddd}tr:nth-child(even) td{background:#f9f9f9}'
        + 'tfoot td{background:#e9ecef;font-weight:bold}.text-end{text-align:right}.fw-bold{font-weight:bold}</style></head><body>'
        + '<h2>' + reportLabel + '</h2>' + clone.innerHTML
        + '<script>window.onload=function(){window.print();}<\/script></body></html>';

    const win = window.open('', '_blank', 'width=900,height=700');
    win.document.write(html);
    win.document.close();
}

document.addEventListener('DOMContentLoaded', function () {
    $('.select2-js').select2({ width: '100%' });
    try {
        const urlParams = new URLSearchParams(window.location.search);
        let tab = urlParams.get('tab');
        if (tab) {
            const el = document.querySelector('.nav-link[href="#' + tab + '"]');
            if (el && typeof bootstrap !== 'undefined') new bootstrap.Tab(el).show();
        }
    } catch (e) { console.error('Tab error', e); }
});
</script>
@endsectiond
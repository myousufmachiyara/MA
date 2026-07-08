@extends('layouts.app')
@section('title', 'Purchase Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

<div class="tabs">

    <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="purchase_register-tab" data-bs-toggle="tab" href="#purchase_register" role="tab">Purchase Register</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="vendor_wise-tab" data-bs-toggle="tab" href="#vendor_wise" role="tab">Vendor-wise Purchase</a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="purchase_return-tab" data-bs-toggle="tab" href="#purchase_return" role="tab">Purchase Return</a>
        </li>
    </ul>

    <div class="tab-content mt-3" id="reportTabsContent">

        {{-- ══════════════════ TAB 1: PURCHASE REGISTER ══════════════════ --}}
        <div class="tab-pane fade show active" id="purchase_register" role="tabpanel">
            <form method="GET" action="{{ route('reports.purchase') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="purchase_register">
                <div class="col-md-2">
                    <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <select name="vendor_id" class="form-control select2-js">
                        <option value="">All Vendors</option>
                        @foreach($vendors as $v)
                            <option value="{{ $v->id }}" {{ request('vendor_id') == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('purchase_register', 'Purchase Register')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            <div class="table-responsive" id="report-table-purchase_register">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Invoice #</th><th>Vendor</th><th>Bill #</th>
                            <th class="text-end">Items</th><th class="text-end">Qty</th><th class="text-end">Amount</th>
                            <th class="no-print">Print</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['purchase_register'] as $inv)
                        <tr>
                            <td>{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d-M-Y') }}</td>
                            <td>PUR-{{ $inv->invoice_no }}</td>
                            <td>{{ $inv->vendor->name ?? 'N/A' }}</td>
                            <td>{{ $inv->bill_no ?? '—' }}</td>
                            <td class="text-end">{{ $inv->items->count() }}</td>
                            <td class="text-end">{{ number_format($inv->total_quantity, 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($inv->total_amount, 2) }}</td>
                            <td class="no-print">
                                <a href="{{ route('purchase_invoices.print', $inv->id) }}" target="_blank" class="ref-link"><i class="fas fa-print"></i></a>
                            </td>
                        </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-3">No purchase invoices found in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['purchase_register']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Total:</td>
                            <td class="text-end">{{ number_format($reports['purchase_register']->sum('total_quantity'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['purchase_register']->sum('total_amount'), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 2: VENDOR-WISE PURCHASE ══════════════════ --}}
        <div class="tab-pane fade" id="vendor_wise" role="tabpanel">
            <form method="GET" action="{{ route('reports.purchase') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="vendor_wise">
                <div class="col-md-2">
                    <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                </div>
                <div class="col-md-3">
                    <select name="vendor_id" class="form-control select2-js">
                        <option value="">All Vendors</option>
                        @foreach($vendors as $v)
                            <option value="{{ $v->id }}" {{ request('vendor_id') == $v->id ? 'selected' : '' }}>{{ $v->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('vendor_wise', 'Vendor-wise Purchase')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            <div class="table-responsive" id="report-table-vendor_wise">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Vendor</th><th class="text-end">Invoices</th><th class="text-end">Total Qty</th>
                            <th class="text-end">Total Amount</th><th class="no-print">Payables</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['vendor_wise'] as $row)
                        <tr>
                            <td>{{ $row['vendor']->name }}</td>
                            <td class="text-end">{{ $row['invoice_count'] }}</td>
                            <td class="text-end">{{ number_format($row['total_quantity'], 2) }}</td>
                            <td class="text-end fw-bold">{{ number_format($row['total_amount'], 2) }}</td>
                            <td class="no-print">
                                <a href="{{ route('reports.accounts', ['tab' => 'party_ledger', 'account_id' => $row['vendor']->id]) }}" class="ref-link" target="_blank">
                                    View Ledger <i class="fas fa-external-link-alt"></i>
                                </a>
                            </td>
                        </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-3">No vendor purchase activity in this period.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['vendor_wise']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td class="text-end">Total:</td>
                            <td class="text-end">{{ $reports['vendor_wise']->sum('invoice_count') }}</td>
                            <td class="text-end">{{ number_format($reports['vendor_wise']->sum('total_quantity'), 2) }}</td>
                            <td class="text-end">{{ number_format($reports['vendor_wise']->sum('total_amount'), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 3: PURCHASE RETURN (pending module) ══════════════════ --}}
        <div class="tab-pane fade" id="purchase_return" role="tabpanel">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-1"></i>
                Purchase Return report will populate once the Purchase Return module is finalized. Let's build that module next if you're ready.
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
@endsection
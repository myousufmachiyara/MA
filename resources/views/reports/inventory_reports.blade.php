@extends('layouts.app')
@section('title', 'Inventory Reports')

@section('content')
<style>
@media print { .no-print { display: none !important; } }
.ref-link { text-decoration: none; font-weight: 500; }
.ref-link:hover { text-decoration: underline; }
</style>

<div class="tabs">

    <ul class="nav nav-tabs" id="reportTabs" role="tablist">
        @foreach ([
            'stock_in_hand'     => 'Stock in Hand',
            'stock_movement'    => 'Stock Movement',
            'item_ledger'       => 'Item Ledger',
            'stock_by_location' => 'Stock by Location',
        ] as $key => $label)
            <li class="nav-item">
                <a class="nav-link {{ $loop->first ? 'active' : '' }}"
                   id="{{ $key }}-tab" data-bs-toggle="tab" href="#{{ $key }}" role="tab">{{ $label }}</a>
            </li>
        @endforeach
    </ul>

    <div class="tab-content mt-3" id="reportTabsContent">

        {{-- ══════════════════ TAB 1: STOCK IN HAND ══════════════════ --}}
        <div class="tab-pane fade show active" id="stock_in_hand" role="tabpanel">
            <form method="GET" action="{{ route('reports.inventory') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="stock_in_hand">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" placeholder="Search item name" value="{{ request('search') }}">
                </div>
                <div class="col-md-3">
                    <select name="category_id" class="form-control">
                        <option value="">All Categories</option>
                        @foreach($categories as $cat)
                            <option value="{{ $cat->id }}" {{ request('category_id') == $cat->id ? 'selected' : '' }}>{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="stock_status" class="form-control">
                        <option value="">All Stock Levels</option>
                        <option value="low" {{ request('stock_status') === 'low' ? 'selected' : '' }}>Low Stock (≤10)</option>
                        <option value="zero" {{ request('stock_status') === 'zero' ? 'selected' : '' }}>Out of Stock</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('stock_in_hand', 'Stock in Hand')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            <div class="table-responsive" id="report-table-stock_in_hand">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Item</th><th>Category</th><th>Variation</th>
                            <th class="text-end">Qty</th><th class="text-end">Cost Price</th><th class="text-end">Value</th>
                            <th class="no-print"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['stock_in_hand'] as $row)
                        <tr class="{{ $row['quantity'] <= 0 ? 'table-danger' : ($row['quantity'] <= 10 ? 'table-warning' : '') }}">
                            <td>{{ $row['item'] }}</td>
                            <td>{{ $row['category'] }}</td>
                            <td>{{ $row['variation'] }}</td>
                            <td class="text-end">{{ number_format($row['quantity'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['cost_price'], 2) }}</td>
                            <td class="text-end">{{ number_format($row['value'], 2) }}</td>
                            <td class="no-print"><a href="{{ $row['link'] }}" class="ref-link" title="View ledger"><i class="fas fa-list"></i></a></td>
                        </tr>
                        @empty
                            <tr><td colspan="7" class="text-center text-muted py-3">No items found.</td></tr>
                        @endforelse
                    </tbody>
                    @if($reports['stock_in_hand']->count() > 0)
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="5" class="text-end">Total Stock Value:</td>
                            <td class="text-end">{{ number_format($reports['stock_in_hand']->sum('value'), 2) }}</td>
                            <td class="no-print"></td>
                        </tr>
                    </tfoot>
                    @endif
                </table>
            </div>
        </div>

        {{-- ══════════════════ TAB 2: STOCK MOVEMENT ══════════════════ --}}
        <div class="tab-pane fade" id="stock_movement" role="tabpanel">
            <form method="GET" action="{{ route('reports.inventory') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="stock_movement">
                <div class="col-md-2">
                    <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <select name="item_id" class="form-control select2-js">
                        <option value="">All Items</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" {{ request('item_id') == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="direction" class="form-control">
                        <option value="">In &amp; Out</option>
                        <option value="in" {{ request('direction') === 'in' ? 'selected' : '' }}>In only</option>
                        <option value="out" {{ request('direction') === 'out' ? 'selected' : '' }}>Out only</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="reference_type" class="form-control">
                        <option value="">All Sources</option>
                        <option value="purchase_invoice" {{ request('reference_type') === 'purchase_invoice' ? 'selected' : '' }}>Purchase Invoice</option>
                        <option value="sale_invoice" {{ request('reference_type') === 'sale_invoice' ? 'selected' : '' }}>Sale Invoice</option>
                        <option value="sale_return" {{ request('reference_type') === 'sale_return' ? 'selected' : '' }}>Sale Return</option>
                        <option value="stock_adjustment" {{ request('reference_type') === 'stock_adjustment' ? 'selected' : '' }}>Stock Adjustment</option>
                        <option value="stock_transfer" {{ request('reference_type') === 'stock_transfer' ? 'selected' : '' }}>Stock Transfer</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="location_id" class="form-control">
                        <option value="">All Locations</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 mt-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                </div>
            </form>

            <div class="table-responsive" id="report-table-stock_movement">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr>
                            <th>Date</th><th>Item</th><th>Variation</th><th>Location</th><th>Direction</th>
                            <th class="text-end">Qty</th><th class="text-end">Balance</th><th>Source</th><th>By</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($reports['stock_movement'] as $m)
                        <tr>
                            <td class="text-nowrap">{{ $m->created_at->format('d-M-Y h:i A') }}</td>
                            <td>{{ $m->product->name ?? 'N/A' }}</td>
                            <td>{{ $m->variation->sku ?? '—' }}</td>
                            <td>{{ $m->location->name ?? '—' }}</td>
                            <td><span class="badge bg-{{ $m->direction === 'in' ? 'success' : 'danger' }}">{{ $m->direction === 'in' ? '+ In' : '− Out' }}</span></td>
                            <td class="text-end">{{ number_format($m->quantity, 2) }}</td>
                            <td class="text-end">{{ number_format($m->balance_after, 2) }}</td>
                            <td>
                                @if($m->reference_link)
                                    <a href="{{ $m->reference_link }}" target="_blank" class="ref-link">{{ $m->reference_label }}</a>
                                @else
                                    {{ $m->reference_label }}
                                @endif
                            </td>
                            <td>{{ $m->creator->name ?? '—' }}</td>
                        </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-3">No movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="no-print">{{ $reports['stock_movement']->appends(request()->query())->links() }}</div>
        </div>

        {{-- ══════════════════ TAB 3: ITEM LEDGER ══════════════════ --}}
        <div class="tab-pane fade" id="item_ledger" role="tabpanel">
            <form method="GET" action="{{ route('reports.inventory') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="item_ledger">
                <div class="col-md-3">
                    <select name="ledger_item_id" class="form-control select2-js" onchange="this.form.submit()">
                        <option value="">-- Select Item --</option>
                        @foreach($products as $p)
                            <option value="{{ $p->id }}" {{ request('ledger_item_id') == $p->id ? 'selected' : '' }}>{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Load</button>
                </div>
                @if(request('ledger_item_id'))
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('item_ledger', 'Item Ledger')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
                @endif
            </form>

            @if(!$reports['item_ledger'])
                <div class="text-muted text-center py-4">Select an item above to view its ledger.</div>

            @elseif($reports['item_ledger']['needs_variation'])
                <p class="mb-2">{{ $reports['item_ledger']['product']->name }} has multiple variations — select one:</p>
                <div class="row g-2">
                    @foreach($reports['item_ledger']['product']->variations as $v)
                    <div class="col-md-3">
                        <a href="{{ route('reports.inventory', array_merge(request()->except('page'), ['ledger_variation_id' => $v->id])) }}"
                           class="btn btn-outline-primary w-100">{{ $v->sku }}</a>
                    </div>
                    @endforeach
                </div>

            @else
                @php $ledger = $reports['item_ledger']; @endphp
                <p class="mb-2 fw-bold">
                    {{ $ledger['product']->name }}{{ $ledger['variation'] ? ' (' . $ledger['variation']->sku . ')' : '' }}
                </p>
                <div class="table-responsive" id="report-table-item_ledger">
                    <table class="table table-bordered table-striped align-middle table-sm">
                        <thead class="table-dark">
                            <tr><th>Date</th><th>Direction</th><th class="text-end">Qty</th><th class="text-end">Balance</th><th>Location</th><th>Source</th><th>By</th></tr>
                        </thead>
                        <tbody>
                            <tr class="table-secondary fw-bold">
                                <td colspan="3">Opening Balance</td><td class="text-end">{{ number_format($ledger['opening'], 2) }}</td><td colspan="3"></td>
                            </tr>
                            @forelse($ledger['movements'] as $m)
                            <tr>
                                <td class="text-nowrap">{{ $m->created_at->format('d-M-Y h:i A') }}</td>
                                <td><span class="badge bg-{{ $m->direction === 'in' ? 'success' : 'danger' }}">{{ $m->direction === 'in' ? '+ In' : '− Out' }}</span></td>
                                <td class="text-end">{{ number_format($m->quantity, 2) }}</td>
                                <td class="text-end">{{ number_format($m->balance_after, 2) }}</td>
                                <td>{{ $m->location->name ?? '—' }}</td>
                                <td>
                                    @if($m->reference_link)
                                        <a href="{{ $m->reference_link }}" target="_blank" class="ref-link">{{ $m->reference_label }}</a>
                                    @else
                                        {{ $m->reference_label }}
                                    @endif
                                </td>
                                <td>{{ $m->creator->name ?? '—' }}</td>
                            </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted">No movements in this range.</td></tr>
                            @endforelse
                            <tr class="table-secondary fw-bold">
                                <td colspan="3">Closing Balance</td><td class="text-end">{{ number_format($ledger['closing'], 2) }}</td><td colspan="3"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- ══════════════════ TAB 4: STOCK BY LOCATION ══════════════════ --}}
        <div class="tab-pane fade" id="stock_by_location" role="tabpanel">
            <form method="GET" action="{{ route('reports.inventory') }}" class="row g-2 mb-3 no-print">
                <input type="hidden" name="tab" value="stock_by_location">
                <div class="col-md-3">
                    <select name="location_id" class="form-control">
                        <option value="">All Locations</option>
                        @foreach($locations as $loc)
                            <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" placeholder="Search item name" value="{{ request('search') }}">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button>
                </div>
                <div class="col-md-2">
                    <button type="button" class="btn btn-danger w-100" onclick="exportReportPDF('stock_by_location', 'Stock by Location')">
                        <i class="fas fa-file-pdf"></i> Export PDF
                    </button>
                </div>
            </form>

            <div class="table-responsive" id="report-table-stock_by_location">
                <table class="table table-bordered table-striped align-middle table-sm">
                    <thead class="table-dark">
                        <tr><th>Location</th><th>Item</th><th>Variation</th><th class="text-end">Quantity</th></tr>
                    </thead>
                    <tbody>
                        @forelse($reports['stock_by_location'] as $s)
                        <tr>
                            <td>{{ $s->location->name ?? 'N/A' }}</td>
                            <td>{{ $s->product->name ?? 'N/A' }}</td>
                            <td>{{ $s->variation->sku ?? '—' }}</td>
                            <td class="text-end">{{ number_format($s->quantity, 2) }}</td>
                        </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-3">No stock records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
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
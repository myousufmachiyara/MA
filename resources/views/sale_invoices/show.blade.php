@extends('layouts.app')

@section('title', 'Sale Invoice SI-' . $invoice->invoice_no)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Sale Invoice SI-{{ $invoice->invoice_no }}</h2>
        <div>
          @php $voucher = $invoice->vouchers->sortByDesc('voucher_date')->first(); @endphp
          @if($voucher)
          <a href="{{ route('vouchers.print', ['type' => $voucher->voucher_type, 'id' => $voucher->id]) }}" target="_blank" class="btn btn-sm btn-outline-success" title="GL Impact">
            <i class="fas fa-book"></i> GL Impact
          </a>
          @endif
          <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="btn btn-sm btn-outline-primary" title="Print">
            <i class="fas fa-print"></i> Print
          </a>
          @if(!$invoice->dispatch_trip_id)
          <button type="button" class="btn btn-sm btn-outline-dark" onclick="printThermalReceiptFromUrl('{{ route('sale_invoices.thermalReceipt', $invoice->id) }}')" title="Print Thermal Receipt">
            <i class="fas fa-receipt"></i>
          </button>
          @endif
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-sm btn-default">Back</a>
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3">
            <strong>Customer:</strong><br>{{ $invoice->customer->name ?? 'N/A' }}
          </div>
          <div class="col-md-2">
            <strong>Invoice Date:</strong><br>{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d-M-Y') }}
          </div>
          <div class="col-md-2">
            <strong>Payment Terms:</strong><br>{{ ucfirst($invoice->payment_terms) }}
          </div>
          <div class="col-md-3">
            <strong>Source:</strong><br>
            @if($invoice->dispatch_trip_id)
              <span class="badge bg-info text-dark">Trip TR-{{ $invoice->dispatchTrip->trip_no ?? '' }}</span>
            @else
              <span class="badge bg-secondary">Manual</span>
            @endif
          </div>
          <div class="col-md-2">
            <strong>Tax Invoice:</strong><br>
            {{ $invoice->is_tax_invoice ? "Yes ({$invoice->gst_type}, {$invoice->gst_rate}%)" : 'No' }}
          </div>
        </div>

        <table class="table table-bordered table-sm">
          <thead class="table-dark">
            <tr>
              <th>Item</th><th>Variation</th><th class="text-end">Qty</th>
              <th class="text-end">Price</th><th class="text-end">Amount</th>
            </tr>
          </thead>
          <tbody>
            @foreach($invoice->items as $item)
            <tr>
              <td>{{ $item->product->name ?? 'N/A' }}</td>
              <td>{{ $item->variation->sku ?? '—' }}</td>
              <td class="text-end">{{ number_format($item->quantity, 2) }}</td>
              <td class="text-end">{{ number_format($item->price, 2) }}</td>
              <td class="text-end">{{ number_format($item->quantity * $item->price, 2) }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>

        <div class="row justify-content-end">
          <div class="col-md-4">
            <table class="table table-borderless table-sm mb-0">
              <tr><td>Net Amount</td><td class="text-end">{{ number_format($invoice->net_amount, 2) }}</td></tr>
              @if($invoice->is_tax_invoice)
              <tr><td>GST ({{ $invoice->gst_rate }}%)</td><td class="text-end">{{ number_format($invoice->gst_amount, 2) }}</td></tr>
              @endif
              @if($invoice->net_adjustment != 0)
              <tr><td>Adjustments (Debit/Credit Notes)</td><td class="text-end">{{ number_format($invoice->net_adjustment, 2) }}</td></tr>
              @endif
              <tr class="fw-bold border-top"><td>Total Amount</td><td class="text-end">{{ number_format($invoice->total_amount, 2) }}</td></tr>
              @if($invoice->wht_applicable)
              <tr><td>WHT ({{ $invoice->wht_rate }}%)</td><td class="text-end text-muted">{{ number_format($invoice->wht_amount, 2) }}</td></tr>
              @endif
              <tr><td>Paid Amount</td><td class="text-end">{{ number_format($invoice->paid_amount, 2) }}</td></tr>
              <tr class="fw-bold {{ $invoice->balance_due > 0 ? 'text-danger' : 'text-success' }}">
                <td>Balance Due</td><td class="text-end">{{ number_format($invoice->balance_due, 2) }}</td>
              </tr>
              @php $returnedValue = $invoice->returned_value; @endphp
              @if($returnedValue > 0)
                <tr class="text-danger">
                    <td>Less: Returns</td><td class="text-end">-{{ number_format($returnedValue, 2) }}</td>
                </tr>
                <tr class="fw-bold">
                    <td>Net After Return</td><td class="text-end">{{ number_format($invoice->net_after_return, 2) }}</td>
                </tr>
              @endif
            </table>
          </div>
        </div>

        @if($invoice->remarks)
        <div class="alert alert-light border mt-3">
          <strong>Remarks:</strong> {{ $invoice->remarks }}
        </div>
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
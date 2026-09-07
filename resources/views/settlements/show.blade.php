@extends('layouts.app')

@section('title', 'Settlement SET-' . $settlement->settlement_no)

@section('content')
<div class="row">
  <div class="col">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Settlement SET-{{ $settlement->settlement_no }} — TR-{{ $settlement->dispatchTrip->trip_no }}</h2>
        @if(!$settlement->cleared_to_office)
        <form action="{{ route('settlements.clear', $settlement->id) }}" method="POST">
          @csrf @method('PUT')
          <button class="btn btn-warning" onclick="return confirm('Confirm cash physically handed to office?')">Clear to Office</button>
        </form>
        @else
        <span class="badge bg-success">Cleared to Office — {{ $settlement->cleared_at->format('d-M-Y h:i A') }}</span>
        @endif
      </header>
      <div class="card-body">
        <p><strong>Delivery Manager:</strong> {{ $settlement->dispatchTrip->deliveryManager->name ?? 'N/A' }}</p>
        <p><strong>Total Cash:</strong> PKR {{ number_format($settlement->total_cash_received, 2) }}
           &nbsp;|&nbsp; <strong>Total Returns:</strong> PKR {{ number_format($settlement->total_returned_value, 2) }}
           &nbsp;|&nbsp; <strong>Total WHT:</strong> PKR {{ number_format($settlement->total_wht_amount, 2) }}</p>

        <table class="table table-bordered table-sm">
          <thead><tr><th>Invoice</th><th>Customer</th><th>WHT</th><th>Returned</th><th>Cash</th><th>Balance</th></tr></thead>
          <tbody>
            @foreach($settlement->allocations as $a)
            <tr>
              <td>SI-{{ $a->invoice->invoice_no }}</td>
              <td>{{ $a->invoice->customer->name ?? 'N/A' }}</td>
              <td>{{ number_format($a->wht_amount, 2) }}</td>
              <td>{{ number_format($a->returned_value, 2) }}</td>
              <td>{{ number_format($a->cash_allocated, 2) }}</td>
              <td class="{{ $a->balance_after > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($a->balance_after, 2) }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>

        {{-- ═══════════ On-Trip Sales processed as part of this trip ═══════════ --}}
        @if(isset($processedAdhocSales) && $processedAdhocSales->count() > 0)
        <h5 class="mt-4">On-Trip Sales (recorded by delivery manager)</h5>
        <table class="table table-bordered table-sm">
          <thead class="table-light"><tr><th>Customer</th><th>Items</th><th class="text-end">Amount</th><th>Resulting Invoice</th></tr></thead>
          <tbody>
            @foreach($processedAdhocSales as $adhoc)
            <tr>
              <td>{{ $adhoc->customer->name ?? 'N/A' }}</td>
              <td>
                @foreach($adhoc->items as $item)
                  {{ $item->product->name ?? 'N/A' }} — {{ number_format($item->quantity, 2) }} @ {{ number_format($item->price, 2) }}<br>
                @endforeach
              </td>
              <td class="text-end">{{ number_format($adhoc->items->sum(fn($i) => $i->quantity * $i->price), 2) }}</td>
              <td>
                @if($adhoc->processedInvoice)
                  <a href="{{ route('sale_invoices.show', $adhoc->processedInvoice->id) }}">SI-{{ $adhoc->processedInvoice->invoice_no }}</a>
                @else
                  —
                @endif
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Stock Adjustment #' . $adjustment->adjustment_no)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Stock Adjustment SA-{{ $adjustment->adjustment_no }}</h2>
        <a href="{{ route('stock_adjustments.index') }}" class="btn btn-default">Back</a>
      </header>
      <div class="card-body">
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($adjustment->adjustment_date)->format('d-M-Y') }}</p>
        <p><strong>Location:</strong> {{ $adjustment->location->name ?? '—' }}</p>
        <p><strong>Reason:</strong> {{ ucfirst(str_replace('_',' ',$adjustment->reason_type)) }}</p>
        <p><strong>Remarks:</strong> {{ $adjustment->remarks ?? '—' }}</p>
        <p><strong>Created By:</strong> {{ $adjustment->creator->name ?? 'N/A' }}</p>

        <table class="table table-bordered mt-3">
          <thead>
            <tr>
              <th>Item</th><th>Variation</th><th>Direction</th><th>Qty</th>
              <th>Unit Cost</th><th>Value</th><th>Stock Before</th><th>Stock After</th><th>Remarks</th>
            </tr>
          </thead>
          <tbody>
            @foreach($adjustment->items as $item)
            <tr>
              <td>{{ $item->product->name ?? 'N/A' }}</td>
              <td>{{ $item->variation->sku ?? '—' }}</td>
              <td>
                <span class="badge bg-{{ $item->direction === 'increase' ? 'success' : 'danger' }}">
                  {{ $item->direction === 'increase' ? '+ Increase' : '− Decrease' }}
                </span>
              </td>
              <td>{{ number_format($item->quantity, 2) }}</td>
              <td>{{ number_format($item->unit_cost, 2) }}</td>
              <td>{{ number_format($item->quantity * $item->unit_cost, 2) }}</td>
              <td>{{ number_format($item->stock_before, 2) }}</td>
              <td>{{ number_format($item->stock_after, 2) }}</td>
              <td>{{ $item->remarks ?? '—' }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Transfer ST-' . $transfer->transfer_no)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Transfer ST-{{ $transfer->transfer_no }}</h2>
        <a href="{{ route('stock_transfer.index') }}" class="btn btn-default btn-sm">Back</a>
      </header>
      <div class="card-body">
        <p><strong>From:</strong> {{ $transfer->fromLocation->name ?? 'N/A' }} &nbsp;→&nbsp; <strong>To:</strong> {{ $transfer->toLocation->name ?? 'N/A' }}</p>
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($transfer->transfer_date)->format('d-M-Y') }}</p>
        <p><strong>Remarks:</strong> {{ $transfer->remarks ?? '—' }}</p>
        <table class="table table-bordered mt-3">
          <thead><tr><th>Item</th><th>Variation</th><th>Quantity</th></tr></thead>
          <tbody>
            @foreach($transfer->items as $item)
            <tr>
              <td>{{ $item->product->name ?? 'N/A' }}</td>
              <td>{{ $item->variation->sku ?? '—' }}</td>
              <td>{{ number_format($item->quantity, 2) }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Sale Return SR-' . $return->id)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Sale Return SR-{{ $return->id }}</h2>
        <div>
          @php $voucher = $return->vouchers->sortByDesc('voucher_date')->first(); @endphp
          @if($voucher)
          <a href="{{ route('vouchers.print', ['type' => $voucher->voucher_type, 'id' => $voucher->id]) }}" target="_blank" class="btn btn-sm btn-outline-success" title="GL Impact">
            <i class="fas fa-book"></i> GL Impact
          </a>
          @endif
          <a href="{{ route('sale_return.index') }}" class="btn btn-sm btn-default">Back</a>
        </div>
      </header>

      <div class="card-body">
        <div class="row mb-3">
          <div class="col-md-3">
            <strong>Customer:</strong><br>{{ $return->customer->name ?? 'N/A' }}
          </div>
          <div class="col-md-2">
            <strong>Return Date:</strong><br>{{ \Carbon\Carbon::parse($return->return_date)->format('d-M-Y') }}
          </div>
          <div class="col-md-3">
            <strong>Against Invoice:</strong><br>SI-{{ $return->sale_invoice_no ?? '—' }}
          </div>
          <div class="col-md-2">
            <strong>Created By:</strong><br>{{ $return->creator->name ?? 'N/A' }}
          </div>
        </div>

        <table class="table table-bordered table-sm">
          <thead class="table-dark">
            <tr>
              <th>Item</th><th>Variation</th><th class="text-end">Qty</th>
              <th class="text-end">Price</th><th class="text-end">Value</th>
            </tr>
          </thead>
          <tbody>
            @forelse($return->items as $item)
            <tr>
              <td>{{ $item->product->name ?? 'N/A' }}</td>
              <td>{{ $item->variation->sku ?? '—' }}</td>
              <td class="text-end">{{ number_format($item->qty ?? 0, 2) }}</td>
              <td class="text-end">{{ number_format($item->price ?? 0, 2) }}</td>
              <td class="text-end">{{ number_format($item->line_value ?? (($item->quantity ?? 0) * ($item->price ?? 0)), 2) }}</td>
            </tr>
            @empty
              <tr><td colspan="5" class="text-center text-muted py-3">No items on this return.</td></tr>
            @endforelse
          </tbody>
          @if($return->items->count() > 0)
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="4" class="text-end">Total</td>
              <td class="text-end">{{ number_format($return->items->sum(fn($i) => $i->line_value ?? (($i->quantity ?? 0) * ($i->price ?? 0))), 2) }}</td>
            </tr>
          </tfoot>
          @endif
        </table>

        @if($return->remarks)
        <div class="alert alert-light border mt-3">
          <strong>Remarks:</strong> {{ $return->remarks }}
        </div>
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
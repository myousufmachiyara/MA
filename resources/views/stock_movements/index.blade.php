@extends('layouts.app')

@section('title', 'Stock In / Out')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Stock In / Out</h2></header>

      <div class="card-body">
        <form method="GET" class="row mb-3">
          <div class="col-md-3">
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
          <div class="col-md-2"><input type="date" name="date_from" class="form-control" value="{{ request('date_from') }}"></div>
          <div class="col-md-2"><input type="date" name="date_to" class="form-control" value="{{ request('date_to') }}"></div>
          <div class="col-md-1"><button type="submit" class="btn btn-primary">Go</button></div>
        </form>

        <table class="table table-bordered table-striped">
          <thead>
            <tr><th>Date</th><th>Item</th><th>Variation</th><th>Direction</th><th>Qty</th><th>Balance After</th><th>Source</th><th>By</th></tr>
          </thead>
          <tbody>
            @forelse($movements as $m)
            <tr>
              <td class="text-nowrap">{{ $m->created_at->format('d-M-Y h:i A') }}</td>
              <td><a href="{{ route('stock_movements.show', $m->item_id) }}">{{ $m->product->name ?? 'N/A' }}</a></td>
              <td>{{ $m->variation->sku ?? '—' }}</td>
              <td><span class="badge bg-{{ $m->direction === 'in' ? 'success' : 'danger' }}">{{ $m->direction === 'in' ? '+ In' : '− Out' }}</span></td>
              <td>{{ number_format($m->quantity, 2) }}</td>
              <td>{{ number_format($m->balance_after, 2) }}</td>
              <td>
                @if($m->reference_link)
                  <a href="{{ $m->reference_link }}" target="_blank">{{ $m->reference_label }}</a>
                @else
                  {{ $m->reference_label }}
                @endif
              </td>
              <td>{{ $m->creator->name ?? '—' }}</td>
            </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted">No movements found.</td></tr>
            @endforelse
          </tbody>
        </table>
        {{ $movements->links() }}
      </div>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('.select2-js').select2({ width: '100%' }));</script>
@endsection
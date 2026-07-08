@extends('layouts.app')

@section('title', 'Stock Log — ' . $product->name)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">{{ $product->name }}{{ $variation ? ' (' . $variation->sku . ')' : '' }}</h2>
        <a href="{{ route('stock_movements.index') }}" class="btn btn-default btn-sm">Back</a>
      </header>

      <div class="card-body">
        <form method="GET" class="row mb-3">
          @if($variation)<input type="hidden" name="variation_id" value="{{ $variation->id }}">@endif
          <div class="col-md-3"><input type="date" name="date_from" class="form-control" value="{{ $dateFrom }}"></div>
          <div class="col-md-3"><input type="date" name="date_to" class="form-control" value="{{ $dateTo }}"></div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="{{ route('stock_movements.show', array_filter(['itemId' => $product->id, 'variation_id' => $variation->id ?? null])) }}" class="btn btn-default">Reset</a>
          </div>
        </form>

        <table class="table table-bordered">
          <thead><tr><th>Date</th><th>Direction</th><th>Qty</th><th>Balance</th><th>Source</th><th>By</th></tr></thead>
          <tbody>
            <tr class="table-light fw-bold">
              <td colspan="3">Opening Balance</td><td>{{ number_format($opening, 2) }}</td><td colspan="2"></td>
            </tr>
            @forelse($movements as $m)
            <tr>
              <td class="text-nowrap">{{ $m->created_at->format('d-M-Y h:i A') }}</td>
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
              <tr><td colspan="6" class="text-center text-muted">No movements in this range.</td></tr>
            @endforelse
            <tr class="table-light fw-bold">
              <td colspan="3">Closing Balance</td><td>{{ number_format($closing, 2) }}</td><td colspan="2"></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
@endsection
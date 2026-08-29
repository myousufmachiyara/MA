@extends('layouts.app')
@section('title', 'Settlement Report')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Settlement Summary</h2></header>
      <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
          <div class="col-md-2"><input type="date" name="from_date" value="{{ request('from_date', $from) }}" class="form-control"></div>
          <div class="col-md-2"><input type="date" name="to_date" value="{{ request('to_date', $to) }}" class="form-control"></div>
          <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
        </form>

        <table class="table table-bordered table-striped table-sm">
          <thead class="table-dark">
            <tr>
              <th>Settlement #</th><th>Date</th><th>Trip</th><th>Delivery Manager</th>
              <th class="text-end">Cash</th><th class="text-end">Returned Value</th>
              <th class="text-end">WHT</th><th>Cleared</th><th></th>
            </tr>
          </thead>
          <tbody>
            @forelse($settlements as $s)
            <tr>
              <td>ST-{{ $s->settlement_no }}</td>
              <td>{{ \Carbon\Carbon::parse($s->settlement_date)->format('d-M-Y') }}</td>
              <td>TR-{{ $s->dispatchTrip->trip_no ?? '—' }}</td>
              <td>{{ $s->dispatchTrip->deliveryManager->name ?? 'N/A' }}</td>
              <td class="text-end">{{ number_format($s->allocations->sum('cash_allocated'), 2) }}</td>
              <td class="text-end">{{ number_format($s->allocations->sum('returned_value'), 2) }}</td>
              <td class="text-end">{{ number_format($s->allocations->sum('wht_amount'), 2) }}</td>
              <td><span class="badge bg-{{ $s->cleared_to_office ? 'success' : 'secondary' }}">{{ $s->cleared_to_office ? 'Yes' : 'No' }}</span></td>
              <td><a href="{{ route('settlements.show', $s->id) }}" class="text-primary"><i class="fas fa-eye"></i></a></td>
            </tr>
            @empty
              <tr><td colspan="9" class="text-center text-muted py-3">No settlements found in this period.</td></tr>
            @endforelse
          </tbody>
          @if($settlements->count() > 0)
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="4" class="text-end">Total:</td>
              <td class="text-end">{{ number_format($settlements->sum(fn($s) => $s->allocations->sum('cash_allocated')), 2) }}</td>
              <td class="text-end">{{ number_format($settlements->sum(fn($s) => $s->allocations->sum('returned_value')), 2) }}</td>
              <td class="text-end">{{ number_format($settlements->sum(fn($s) => $s->allocations->sum('wht_amount')), 2) }}</td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
          @endif
        </table>
      </div>
    </section>
  </div>
</div>
@endsection
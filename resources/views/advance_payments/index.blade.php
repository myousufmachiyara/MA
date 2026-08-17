@extends('layouts.app')

@section('title', 'Advance Payments')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Advance Payments</h2>
        @can('advance_payments.create')
        <a href="{{ route('advance_payments.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Advance</a>
        @endcan
      </header>

      <div class="card-body">
        <form method="GET" class="row mb-3">
          <div class="col-md-3">
            <select name="party_type" class="form-control" onchange="this.form.submit()">
              <option value="">All Parties</option>
              <option value="customer" {{ request('party_type') === 'customer' ? 'selected' : '' }}>Customers</option>
              <option value="vendor" {{ request('party_type') === 'vendor' ? 'selected' : '' }}>Vendors</option>
            </select>
          </div>
        </form>

        <table class="table table-bordered table-striped" id="advanceTable">
          <thead>
            <tr>
              <th>Advance #</th><th>Date</th><th>Party</th><th>Type</th>
              <th class="text-end">Amount</th><th class="text-end">Remaining</th><th></th>
            </tr>
          </thead>
          <tbody>
            @forelse($advances as $adv)
            <tr>
              <td>ADV-{{ $adv->advance_no }}</td>
              <td>{{ \Carbon\Carbon::parse($adv->payment_date)->format('d-M-Y') }}</td>
              <td>{{ $adv->party->name ?? 'N/A' }}</td>
              <td><span class="badge bg-{{ $adv->party_type === 'customer' ? 'info' : 'secondary' }}">{{ ucfirst($adv->party_type) }}</span></td>
              <td class="text-end">{{ number_format($adv->amount, 2) }}</td>
              <td class="text-end {{ $adv->remaining_amount > 0 ? 'text-success fw-bold' : 'text-muted' }}">{{ number_format($adv->remaining_amount, 2) }}</td>
              <td><a href="{{ route('advance_payments.show', $adv->id) }}" class="text-primary" title="View"><i class="fas fa-eye"></i></a></td>
            </tr>
            @empty
              <tr><td colspan="7" class="text-center text-muted py-3">No advance payments recorded.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('#advanceTable').DataTable({ pageLength: 50, order: [[0, 'desc']] }));</script>
@endsection
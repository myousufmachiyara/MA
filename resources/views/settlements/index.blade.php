@extends('layouts.app')

@section('title', 'Settlements')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Trip Settlements</h2></header>
      <div class="card-body">
        <table class="table table-bordered table-striped" id="setTable">
          <thead><tr><th>#</th><th>Date</th><th>Settlement #</th><th>Trip</th><th>Delivery Manager</th><th>Cash</th><th>Returns</th><th>WHT</th><th>Cleared?</th><th></th></tr></thead>
          <tbody>
            @foreach ($settlements as $i => $s)
            <tr>
              <td>{{ $i + 1 }}</td>
              <td>{{ \Carbon\Carbon::parse($s->settlement_date)->format('d-M-Y') }}</td>
              <td>SET-{{ $s->settlement_no }}</td>
              <td>TR-{{ $s->dispatchTrip->trip_no ?? '—' }}</td>
              <td>{{ $s->dispatchTrip->deliveryManager->name ?? 'N/A' }}</td>
              <td>{{ number_format($s->total_cash_received, 2) }}</td>
              <td>{{ number_format($s->total_returned_value, 2) }}</td>
              <td>{{ number_format($s->total_wht_amount, 2) }}</td>
              <td>
                @if($s->cleared_to_office)
                  <span class="badge bg-success">Cleared</span>
                @else
                  <form action="{{ route('settlements.clear', $s->id) }}" method="POST" class="d-inline">
                    @csrf @method('PUT')
                    <button class="btn btn-sm btn-warning" onclick="return confirm('Confirm cash physically handed to office?')">Clear Now</button>
                  </form>
                @endif
              </td>
              <td><a href="{{ route('settlements.show', $s->id) }}"><i class="fas fa-eye"></i></a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('#setTable').DataTable({ pageLength: 50, order: [[0,'desc']] }));</script>
@endsection
@extends('layouts.app')

@section('title', 'Stock | Transfers')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Stock Transfers</h2>
        @can('stock_transfer.create')
        <a href="{{ route('stock_transfer.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Transfer</a>
        @endcan
      </header>

      <div class="card-body">
        <table class="table table-bordered table-striped" id="transferTable">
          <thead><tr><th>#</th><th>Date</th><th>Transfer #</th><th>From</th><th>To</th><th>By</th><th></th></tr></thead>
          <tbody>
            @foreach($transfers as $i => $t)
            <tr>
              <td>{{ $i + 1 }}</td>
              <td>{{ \Carbon\Carbon::parse($t->transfer_date)->format('d-M-Y') }}</td>
              <td>ST-{{ $t->transfer_no }}</td>
              <td>{{ $t->fromLocation->name ?? 'N/A' }}</td>
              <td>{{ $t->toLocation->name ?? 'N/A' }}</td>
              <td>{{ $t->creator->name ?? '—' }}</td>
              <td><a href="{{ route('stock_transfer.show', $t->id) }}"><i class="fas fa-eye"></i></a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('#transferTable').DataTable({ pageLength: 50, order: [[0,'desc']] }));</script>
@endsection
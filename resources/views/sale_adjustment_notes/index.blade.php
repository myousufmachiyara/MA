@extends('layouts.app')

@section('title', 'Debit / Credit Notes')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">Debit / Credit Notes</h2>
        @can('sale_adjustment_notes.create')
        <a href="{{ route('sale_adjustment_notes.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Note</a>
        @endcan
      </header>

      <div class="card-body">
        <table class="table table-bordered table-striped" id="notesTable">
          <thead>
            <tr>
              <th>Note #</th><th>Type</th><th>Date</th><th>Invoice</th><th>Customer</th>
              <th class="text-end">Amount</th><th>Reason</th><th></th>
            </tr>
          </thead>
          <tbody>
            @forelse($notes as $note)
            <tr>
              <td>{{ strtoupper($note->note_type[0]) }}N-{{ $note->note_no }}</td>
              <td><span class="badge bg-{{ $note->note_type === 'credit' ? 'success' : 'warning text-dark' }}">{{ ucfirst($note->note_type) }}</span></td>
              <td>{{ \Carbon\Carbon::parse($note->note_date)->format('d-M-Y') }}</td>
              <td>SI-{{ $note->invoice->invoice_no ?? '—' }}</td>
              <td>{{ $note->invoice->customer->name ?? 'N/A' }}</td>
              <td class="text-end">{{ number_format($note->amount, 2) }}</td>
              <td>{{ $note->reason }}</td>
              <td><a href="{{ route('sale_adjustment_notes.show', $note->id) }}" class="text-primary" title="View"><i class="fas fa-eye"></i></a></td>
            </tr>
            @empty
              <tr><td colspan="8" class="text-center text-muted py-3">No adjustment notes recorded.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('#notesTable').DataTable({ pageLength: 50, order: [[2, 'desc']] }));</script>
@endsection
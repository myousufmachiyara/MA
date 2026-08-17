@extends('layouts.app')

@section('title', 'Note Details')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">{{ ucfirst($note->note_type) }} Note {{ strtoupper($note->note_type[0]) }}N-{{ $note->note_no }}</h2>
        <a href="{{ route('sale_adjustment_notes.index') }}" class="btn btn-default btn-sm">Back</a>
      </header>
      <div class="card-body">
        <p><strong>Against Invoice:</strong> SI-{{ $note->invoice->invoice_no ?? '—' }}</p>
        <p><strong>Customer:</strong> {{ $note->invoice->customer->name ?? 'N/A' }}</p>
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($note->note_date)->format('d-M-Y') }}</p>
        <p><strong>Type:</strong> <span class="badge bg-{{ $note->note_type === 'credit' ? 'success' : 'warning text-dark' }}">{{ ucfirst($note->note_type) }} Note</span></p>
        <p><strong>Amount:</strong> PKR {{ number_format($note->amount, 2) }}</p>
        <p><strong>Reason:</strong> {{ $note->reason }}</p>
        <p><strong>Remarks:</strong> {{ $note->remarks ?? '—' }}</p>

        @if($note->invoice)
        <div class="alert alert-light border mt-3">
          <strong>Invoice Total:</strong> PKR {{ number_format($note->invoice->total_amount, 2) }} &nbsp;|&nbsp;
          <strong>Current Balance Due (after this note):</strong> PKR {{ number_format($note->invoice->balance_due, 2) }}
        </div>
        @endif
      </div>
    </section>
  </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Advance ADV-' . $advance->advance_no)

@section('content')
<div class="row">
  <div class="col">
    @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Advance Payment ADV-{{ $advance->advance_no }}</h2>
        <a href="{{ route('advance_payments.index') }}" class="btn btn-default btn-sm">Back</a>
      </header>
      <div class="card-body">
        <p><strong>Party:</strong> {{ $advance->party->name ?? 'N/A' }} ({{ ucfirst($advance->party_type) }})</p>
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($advance->payment_date)->format('d-M-Y') }}</p>
        <p><strong>Total Amount:</strong> PKR {{ number_format($advance->amount, 2) }}</p>
        <p><strong>Remaining (Unadjusted):</strong> <span class="fw-bold {{ $advance->remaining_amount > 0 ? 'text-success' : 'text-muted' }}">PKR {{ number_format($advance->remaining_amount, 2) }}</span></p>
        <p><strong>Remarks:</strong> {{ $advance->remarks ?? '—' }}</p>

        <h2 class="card-title mt-4">Adjustment History </h2>
        <table class="table table-bordered table-sm mb-4">
          <thead><tr><th>Date</th><th>Invoice</th><th class="text-end">Amount Adjusted</th></tr></thead>
          <tbody>
            @forelse($advance->adjustments as $adj)
            <tr>
              <td>{{ \Carbon\Carbon::parse($adj->adjustment_date)->format('d-M-Y') }}</td>
              <td>
                {{ $adj->invoice_type === 'sale_invoice' ? 'Sale' : 'Purchase' }} Invoice #{{ $adj->invoice_id }}
              </td>
              <td class="text-end">{{ number_format($adj->amount_adjusted, 2) }}</td>
            </tr>
            @empty
              <tr><td colspan="3" class="text-center text-muted">No adjustments made yet.</td></tr>
            @endforelse
          </tbody>
        </table>

        @if($advance->remaining_amount > 0)
        <h2 class="card-title">Adjust Against Invoice</h2>
        <form action="{{ route('advance_payments.adjust', $advance->id) }}" method="POST" class="row g-2 align-items-end" id="adjustForm">
          @csrf
          <input type="hidden" name="invoice_type" id="adjust_invoice_type">
          <div class="col-md-5">
            <label>Invoice</label>
            <select name="invoice_id" id="adjust_invoice_id" class="form-control select2-js" required>
              <option value="">Loading...</option>
            </select>
          </div>
          <div class="col-md-3">
            <label>Amount to Adjust</label>
            <input type="number" name="amount" class="form-control" step="any" min="0.01" max="{{ $advance->remaining_amount }}" value="{{ $advance->remaining_amount }}" required>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Adjust</button>
          </div>
        </form>
        @endif
      </div>
    </section>
  </div>
</div>

<script>
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    fetch("{{ route('advance_payments.openInvoices', $advance->id) }}")
      .then(res => res.json())
      .then(data => {
        if (!data.success) return;
        $('#adjust_invoice_type').val(data.invoice_type);
        const select = $('#adjust_invoice_id');
        select.html('<option value="">Select Invoice</option>');
        data.invoices.forEach(inv => select.append(`<option value="${inv.id}">${inv.label}</option>`));
        select.trigger('change');
      });
  });
</script>
@endsection
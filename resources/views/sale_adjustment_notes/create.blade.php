@extends('layouts.app')

@section('title', 'Debit / Credit Notes | New')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_adjustment_notes.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">New Debit / Credit Note</h2></header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Note Type <span class="text-danger">*</span></label>
              <select name="note_type" class="form-control" required>
                <option value="credit">Credit Note (reduces amount owed)</option>
                <option value="debit">Debit Note (increases amount owed)</option>
              </select>
            </div>

            <div class="col-md-5">
              <label>Invoice <span class="text-danger">*</span></label>
              <select id="invoice_picker" name="sale_invoice_id" class="form-control" style="width:100%" required></select>
            </div>

            <div class="col-md-2">
              <label>Note Date <span class="text-danger">*</span></label>
              <input type="date" name="note_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>

            <div class="col-md-2">
              <label>Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="any" min="0.01" required>
            </div>

            <div class="col-md-6 mt-3">
              <label>Reason <span class="text-danger">*</span></label>
              <input type="text" name="reason" class="form-control" placeholder="e.g. Pricing error, damaged goods discount" required>
            </div>

            <div class="col-md-6 mt-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>

          @if($invoice)
          <div class="alert alert-info mt-2">
            Selected: SI-{{ $invoice->invoice_no }} — {{ $invoice->customer->name ?? 'N/A' }} — Current Due: PKR {{ number_format($invoice->balance_due, 2) }}
          </div>
          @endif
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Note</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  $(document).ready(function () {
    $('#invoice_picker').select2({
      width: '100%',
      placeholder: 'Search by invoice # or customer name',
      minimumInputLength: 1,
      ajax: {
        url: "{{ route('sale_adjustment_notes.searchInvoices') }}",
        dataType: 'json',
        delay: 300,
        data: params => ({ term: params.term }),
        processResults: data => ({ results: data })
      }
    });

    @if($invoice)
      const preselected = new Option("SI-{{ $invoice->invoice_no }} — {{ $invoice->customer->name ?? 'N/A' }}", "{{ $invoice->id }}", true, true);
      $('#invoice_picker').append(preselected).trigger('change');
    @endif
  });
</script>
@endsection
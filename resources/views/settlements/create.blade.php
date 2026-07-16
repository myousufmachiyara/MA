@extends('layouts.app')

@section('title', 'Settle Trip TR-' . $trip->trip_no)

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('dispatch_trips.settle.store', $trip->id) }}" method="POST">      
      @csrf
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Settle Trip TR-{{ $trip->trip_no }} — {{ $trip->deliveryManager->name }}</h2>
        </header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Settlement Date</label>
              <input type="date" name="settlement_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3">
              <label>Total Cash Handed Over</label>
              <input type="number" name="total_cash_received" id="total_cash_received" class="form-control" step="any" required>
            </div>
            <div class="col-md-6">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" placeholder="e.g. Customer B short paid due to damaged carton">
            </div>
          </div>

          @foreach($trip->invoices as $invoice)
          <div class="card mb-3">
            <div class="card-header bg-light">
              <strong>SI-{{ $invoice->invoice_no }}</strong> — {{ $invoice->customer->name ?? 'N/A' }}
              — Total: PKR {{ number_format($invoice->total_amount, 2) }}
              @if($invoice->wht_applicable)
                <span class="badge bg-warning text-dark">WHT {{ $invoice->wht_rate }}% = {{ number_format($invoice->wht_amount, 2) }}</span>
              @endif
            </div>
            <div class="card-body">
              <table class="table table-bordered table-sm mb-2">
                <thead><tr><th>Item</th><th>Invoiced Qty</th><th>Price</th><th>Returned Qty</th></tr></thead>
                <tbody>
                  @foreach($invoice->items as $item)
                  <tr>
                    <td>{{ $item->product->name ?? 'N/A' }} @if($item->variation)({{ $item->variation->sku }})@endif</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ number_format($item->price, 2) }}</td>
                    <td>
                      <input type="number" name="returns[{{ $item->id }}]" class="form-control return-qty"
                             data-invoice="{{ $invoice->id }}" data-price="{{ $item->price }}" data-gst-rate="{{ $invoice->gst_rate ?? 0 }}"
                             value="0" min="0" max="{{ $item->quantity }}" step="any" onchange="recalc({{ $invoice->id }})">
                    </td>
                  </tr>
                  @endforeach
                </tbody>
              </table>

              <div class="row">
                <div class="col-md-3"><strong>Returned Value:</strong> PKR <span id="returned_{{ $invoice->id }}">0.00</span></div>
                <div class="col-md-3"><strong>WHT Deducted:</strong> PKR {{ number_format($invoice->wht_applicable ? $invoice->wht_amount : 0, 2) }}</div>
                <div class="col-md-3"><strong>Balance Due (after WHT/returns):</strong> PKR <span id="due_{{ $invoice->id }}">{{ number_format($invoice->total_amount - ($invoice->wht_applicable ? $invoice->wht_amount : 0), 2) }}</span></div>
                <div class="col-md-3">
                  <label>Cash Received for this Invoice</label>
                  <input type="number" name="cash[{{ $invoice->id }}]" id="cash_{{ $invoice->id }}" class="form-control cash-input"
                         value="{{ number_format($invoice->total_amount - ($invoice->wht_applicable ? $invoice->wht_amount : 0), 2, '.', '') }}"
                         step="any" min="0" data-invoice="{{ $invoice->id }}" onchange="sumTotal()">
                </div>
              </div>
            </div>
          </div>
          @endforeach

        </div>
        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Settlement</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  const invoiceData = {
    @foreach($trip->invoices as $invoice)
      {{ $invoice->id }}: {
        total: {{ $invoice->total_amount }},
        wht: {{ $invoice->wht_applicable ? $invoice->wht_amount : 0 }},
      },
    @endforeach
  };

  function recalc(invoiceId) {
    let returnedValue = 0;
    $(`.return-qty[data-invoice="${invoiceId}"]`).each(function () {
      const qty = parseFloat($(this).val()) || 0;
      const price = parseFloat($(this).data('price')) || 0;
      const gstRate = parseFloat($(this).data('gst-rate')) || 0;
      const net = qty * price;
      const gst = net * gstRate / 100;
      returnedValue += net + gst;
    });

    $(`#returned_${invoiceId}`).text(returnedValue.toFixed(2));

    const data = invoiceData[invoiceId];
    const due = Math.max(0, data.total - data.wht - returnedValue);
    $(`#due_${invoiceId}`).text(due.toFixed(2));
    $(`#cash_${invoiceId}`).val(due.toFixed(2));

    sumTotal();
  }

  function sumTotal() {
    let sum = 0;
    $('.cash-input').each(function () { sum += parseFloat($(this).val()) || 0; });
    $('#total_cash_received').val(sum.toFixed(2));
  }

  $(document).ready(sumTotal);
</script>
@endsection
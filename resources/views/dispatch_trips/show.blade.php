@extends('layouts.app')

@section('title', 'Trip TR-' . $trip->trip_no)

@section('content')
<div class="row">
  <div class="col">
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Trip TR-{{ $trip->trip_no }} — {{ $trip->vehicle_no }} — {{ $trip->deliveryManager->name }}</h2>
        <span class="bg-{{ ['planned'=>'secondary','dispatched'=>'primary','settled'=>'success','cancelled'=>'danger'][$trip->status] }}">{{ ucfirst($trip->status) }}</span>
      </header>

      <div class="card-body">
        <p><strong>Date:</strong> {{ \Carbon\Carbon::parse($trip->trip_date)->format('d-M-Y') }}</p>

        @if($trip->status === 'planned')
        {{-- ── Add Orders ─────────────────────────────────────── --}}
        <form action="{{ route('dispatch_trips.addOrders', $trip->id) }}" method="POST" class="mb-4">
          @csrf
          <h5>Available Orders (confirmed, not yet merged)</h5>
          <table class="table table-bordered table-sm">
            <thead><tr><th></th><th>Order #</th><th>Customer</th><th>Booker</th><th>Amount</th></tr></thead>
            <tbody>
              @forelse($availableOrders as $order)
              <tr>
                <td><input type="checkbox" name="order_ids[]" value="{{ $order->id }}"></td>
                <td>SO-{{ $order->order_no }}</td>
                <td>{{ $order->customer->name ?? 'N/A' }}</td>
                <td>{{ $order->booker->name ?? 'N/A' }}</td>
                <td>{{ number_format($order->total_amount, 2) }}</td>
              </tr>
              @empty
              <tr><td colspan="5" class="text-center text-muted">No confirmed orders waiting.</td></tr>
              @endforelse
            </tbody>
          </table>
          <button type="submit" class="btn btn-outline-primary btn-sm">Add Selected to Trip</button>
        </form>
        @endif

        {{-- ── Orders in this trip ────────────────────────────── --}}
        <h5>Orders in this Trip ({{ $trip->orders->count() }})</h5>
        <table class="table table-bordered table-sm mb-4">
          <thead><tr><th>Order #</th><th>Customer</th><th class="text-end">Qty</th><th class="text-end">Amount</th><th>Terms</th><th>Status</th>@if($trip->status === 'planned')<th>Action</th>@endif</tr></thead>
          <tbody>
            @foreach($trip->orders as $order)
            <tr>
              <td>SO-{{ $order->order_no }}</td>
              <td>{{ $order->customer->name ?? 'N/A' }}</td>
              <td class="text-end">{{ number_format($order->items->sum('quantity'), 2) }}</td>
              <td class="text-end">{{ number_format($order->total_amount, 2) }}</td>
              <td>{{ ucfirst($order->payment_terms) }}</td>
              <td>{{ ucfirst($order->status) }}</td>
              @if($trip->status === 'planned')
              <td>
                <form action="{{ route('dispatch_trips.removeOrder', [$trip->id, $order->id]) }}" method="POST">
                  @csrf @method('DELETE')
                  <button class="btn btn-link p-0 text-danger" onclick="return confirm('Remove this order from the trip?')"><i class="fa fa-times"></i></button>
                </form>
              </td>
              @endif
            </tr>
            @endforeach
          </tbody>
          @if($trip->orders->count() > 0)
          <tfoot class="table-light fw-bold">
            <tr>
              <td colspan="2" class="text-end">Trip Total:</td>
              <td class="text-end">{{ number_format($trip->orders->sum(fn($o) => $o->items->sum('quantity')), 2) }}</td>
              <td class="text-end">{{ number_format($trip->orders->sum('total_amount'), 2) }}</td>
              <td colspan="{{ $trip->status === 'planned' ? 3 : 2 }}"></td>
            </tr>
          </tfoot>
          @endif
        </table>

        {{-- ── Stock check ─────────────────────────────────────── --}}
        @if($trip->status === 'planned' && count($requirements))
        <h5>Stock Check</h5>
        <table class="table table-bordered table-sm mb-4">
          <thead><tr><th>Item</th><th>Required</th><th>Available</th><th>Status</th></tr></thead>
          <tbody>
            @foreach($requirements as $req)
            <tr class="{{ $req['required'] > $req['available'] ? 'table-danger' : '' }}">
              <td>{{ $req['name'] }}</td>
              <td>{{ $req['required'] }}</td>
              <td>{{ $req['available'] }}</td>
              <td>{{ $req['required'] > $req['available'] ? '⚠ Short' : '✓ OK' }}</td>
            </tr>
            @endforeach
          </tbody>
        </table>

        {{-- ── Dispatch action ─────────────────────────────────── --}}
        <form action="{{ route('dispatch_trips.dispatch', $trip->id) }}" method="POST" onsubmit="return confirm('Dispatch this trip? Invoices will be generated and stock deducted. This cannot be undone.');">
          @csrf
          <div class="row align-items-end">
            <div class="col-md-2">
              <label>Apply GST?</label>
              <select name="apply_gst" class="form-control" id="applyGst" onchange="toggleGst()">
                <option value="0">No</option>
                <option value="1">Yes</option>
              </select>
            </div>
            <div class="col-md-2" id="gstTypeWrap" style="display:none;">
              <label>GST Type</label>
              <select name="gst_type" class="form-control">
                <option value="exclusive">Exclusive</option>
                <option value="inclusive">Inclusive</option>
              </select>
            </div>
            <div class="col-md-2" id="gstRateWrap" style="display:none;">
              <label>GST Rate (%)</label>
              <input type="number" name="gst_rate" class="form-control" step="0.01" value="18">
            </div>
            <div class="col-md-3">
              <button type="submit" class="btn btn-success"><i class="fas fa-truck"></i> Dispatch Trip</button>
            </div>
          </div>
        </form>
        @endif

        {{-- ── Generated invoices (after dispatch) ──────────────── --}}
        @if($trip->invoices->count())
        <h5 class="mt-4">Generated Invoices</h5>
        <table class="table table-bordered table-sm">
          <thead><tr><th>Invoice #</th><th>Customer</th><th>Net</th><th>GST</th><th>Total</th><th>WHT (info)</th><th></th></tr></thead>
          <tbody>
            @foreach($trip->invoices as $inv)
            <tr>
              <td>SI-{{ $inv->invoice_no }}</td>
              <td>{{ $inv->customer->name ?? 'N/A' }}</td>
              <td>{{ number_format($inv->net_amount, 2) }}</td>
              <td>{{ number_format($inv->gst_amount, 2) }}</td>
              <td>{{ number_format($inv->total_amount, 2) }}</td>
              <td>{{ $inv->wht_applicable ? number_format($inv->wht_amount, 2) : '—' }}</td>
              <td><a href="{{ route('sale_invoices.print', $inv->id) }}" target="_blank"><i class="fas fa-print"></i></a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
        @if($trip->status === 'dispatched')
            <div class="mt-3">
                <a href="{{ route('settlements.create', $trip->id) }}" class="btn btn-warning">
                    <i class="fas fa-hand-holding-usd"></i> Settle Trip (Record Payment & Returns)
                </a>
            </div>
        @endif
        @endif

      </div>
    </section>
  </div>
</div>
<script>
function toggleGst() {
    const on = $('#applyGst').val() === '1';
    $('#gstTypeWrap, #gstRateWrap').toggle(on);
}
</script>
@endsection
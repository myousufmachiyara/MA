@extends('layouts.app')

@section('title', 'Sale | All Invoices')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>{{ session('error') }}</div>@endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Sale Invoices</h2>
        @can('sale_invoices.create')
        <a href="{{ route('sale_invoices.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Invoice</a>
        @endcan
      </header>

      <div class="card-body">
        <form method="GET" class="row g-2 mb-3">
          <div class="col-md-3">
            <select name="customer_id" class="form-control select2-js" onchange="this.form.submit()">
              <option value="">All Customers</option>
              @foreach($customers ?? [] as $c)
                <option value="{{ $c->id }}" {{ request('customer_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
              @endforeach
            </select>
          </div>
          <div class="col-md-2">
            <select name="source" class="form-control" onchange="this.form.submit()">
              <option value="">All Sources</option>
              <option value="manual" {{ request('source') === 'manual' ? 'selected' : '' }}>Manual / Direct</option>
              <option value="trip" {{ request('source') === 'trip' ? 'selected' : '' }}>Dispatch Trip</option>
            </select>
          </div>
        </form>

        <div class="table-responsive">
          <table class="table table-bordered table-striped mb-0" id="invoicesTable">
            <thead>
              <tr>
                <th>Invoice #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Source</th>
                <th>Terms</th>
                <th class="text-end">Net</th>
                <th class="text-end">GST</th>
                <th class="text-end">Total</th>
                <th class="text-end">Paid</th>
                <th class="text-end">Due</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @forelse($invoices as $invoice)
              <tr>
                <td><a href="{{ route('sale_invoices.show', $invoice->id) }}">SI-{{ $invoice->invoice_no }}</a></td>
                <td>{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('d-M-Y') }}</td>
                <td>{{ $invoice->customer->name ?? 'N/A' }}</td>
                <td>
                  @if($invoice->dispatch_trip_id)
                    <span class="badge bg-info text-dark">Trip TR-{{ $invoice->dispatchTrip->trip_no ?? '' }}</span>
                  @else
                    <span class="badge bg-secondary">Manual</span>
                  @endif
                </td>
                <td><span class="badge bg-{{ $invoice->payment_terms === 'cash' ? 'success' : 'warning' }}">{{ ucfirst($invoice->payment_terms) }}</span></td>
                <td class="text-end">{{ number_format($invoice->net_amount, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->gst_amount, 2) }}</td>
                <td class="text-end fw-bold">{{ number_format($invoice->total_amount, 2) }}</td>
                <td class="text-end">{{ number_format($invoice->paid_amount, 2) }}</td>
                <td class="text-end {{ $invoice->balance_due > 0 ? 'text-danger fw-bold' : '' }}">{{ number_format($invoice->balance_due, 2) }}</td>
                <td class="text-nowrap">
                  <a href="{{ route('sale_invoices.show', $invoice->id) }}" class="text-secondary me-1" title="View"><i class="fas fa-eye"></i></a>
                  <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="text-primary me-1" title="Print"><i class="fas fa-print"></i></a>
                  <button type="button" class="btn btn-link p-0 m-0 text-dark me-1" onclick="printThermalReceiptFromUrl('{{ route('sale_invoices.thermalReceipt', $invoice->id) }}')" title="Thermal Receipt">
                    <i class="fas fa-receipt"></i>
                  </button>
                  @php $voucher = $invoice->vouchers->sortByDesc('voucher_date')->first(); @endphp
                  @if($voucher)
                  <a href="{{ route('vouchers.print', ['type' => $voucher->voucher_type, 'id' => $voucher->id]) }}" target="_blank" class="text-success me-1" title="GL Impact">
                    <i class="fas fa-book"></i>
                  </a>
                  @endif
                  @if(!$invoice->dispatch_trip_id)
                    @can('sale_invoices.edit')
                    <a href="{{ route('sale_invoices.edit', $invoice->id) }}" class="text-primary me-1" title="Edit"><i class="fas fa-edit"></i></a>
                    @endcan
                    @can('sale_invoices.delete')
                    <form action="{{ route('sale_invoices.destroy', $invoice->id) }}" method="POST" class="d-inline">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-link p-0 m-0 text-danger" onclick="return confirm('Delete this invoice?')" title="Delete"><i class="fas fa-trash-alt"></i></button>
                    </form>
                    @endcan
                  @endif
                </td>
              </tr>
              @empty
                <tr><td colspan="11" class="text-center text-muted py-4">No sale invoices found.</td></tr>
              @endforelse
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</div>

<script>
  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    $('#invoicesTable').DataTable({ pageLength: 50, order: [[1, 'desc']], searching: true });
  });
</script>
@endsection
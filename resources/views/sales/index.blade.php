@extends('layouts.app')

@section('title', 'Sale Invoices')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if (session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
      @elseif (session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
      @endif

      <header class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title">All Sale Invoices</h2>
        <a href="{{ route('sale_invoices.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Sale Invoice</a>
      </header>

      <div class="card-body">
        <div class="table-responsive">
          <table class="table table-bordered table-striped table-hover datatable">
            <thead class="thead-dark">
              <tr>
                <th>#</th>
                <th>Date</th>
                <th>Account</th>
                <th>Type</th>
                <th>Total Amount</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
            @foreach ($invoices as $invoice)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $invoice->date }}</td>
                <td>{{ $invoice->account->name ?? 'POS Customer' }}</td>
                <td>
                    <span class="badge {{ $invoice->type === 'credit' ? 'bg-warning' : 'bg-success' }}">{{ ucfirst($invoice->type) }}</span>
                </td>
                <td>
                  {{ number_format(
                      $invoice->items->sum(function($item) {
                          $disc = $item->discount ?? 0;
                          $discountedPrice = $item->sale_price - ($item->sale_price * $disc / 100);
                          return $discountedPrice * $item->quantity;
                      })
                      - $invoice->discount
                  , 2) }}
                </td>
                <td>
                  <a href="{{ route('sale_invoices.edit', $invoice->id) }}" class="text-primary"><i class="fas fa-edit"></i></a>
                  <a href="{{ route('sale_invoices.print', $invoice->id) }}" target="_blank" class="text-success"><i class="fas fa-print"></i></a>
                  <form action="{{ route('sale_invoices.destroy', $invoice->id) }}" method="POST" style="display:inline;">
                    @csrf
                    @method('DELETE')
                    <button class="text-danger" style="border:none" onclick="return confirm('Are you sure?')"><i class="fas fa-trash"></i></button>
                  </form>
                </td>
            </tr>
            @endforeach
            </tbody>

          </table>
        </div>
      </div>
    </section>
  </div>
</div>
<script>
  $(document).ready(function () {
    $('.datatable').DataTable(); // if you are using DataTables
  });
</script>
@endsection

@extends('layouts.app')

@section('title', 'Purchases | All Orders')

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
                <h2 class="card-title">Purchase Orders</h2>
                @can('purchase_orders.create')
                <a href="{{ route('purchase_orders.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Order
                </a>
                @endcan
            </header>

            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="poTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Order Date</th>
                                <th>Order #</th>
                                <th>Vendor</th>
                                <th>Total Amount</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $index => $order)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($order->order_date)->format('d-M-Y') }}</td>
                                <td>PO-{{ $order->order_no }}</td>
                                <td>{{ $order->vendor->name ?? 'N/A' }}</td>
                                <td>{{ number_format($order->total_amount, 2) }}</td>
                                <td>
                                    @php
                                        $badge = ['pending'=>'secondary','partial'=>'warning','completed'=>'success','cancelled'=>'danger'][$order->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $badge }}">{{ ucfirst($order->status) }}</span>
                                </td>
                                <td>
                                    @can('purchase_orders.edit')
                                        @if($order->status === 'pending')
                                        <a href="{{ route('purchase_orders.edit', $order->id) }}" class="text-primary me-1" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        @endif
                                    @endcan

                                    @can('purchase_invoices.create')
                                        @if(in_array($order->status, ['pending','partial']))
                                        <a href="{{ route('purchase_invoices.create', ['po_id' => $order->id]) }}"
                                           class="text-success me-1" title="Create Invoice from this Order">
                                            <i class="fas fa-file-invoice"></i>
                                        </a>
                                        @endif
                                    @endcan

                                    @can('purchase_orders.delete')
                                        @if($order->status === 'pending')
                                        <form action="{{ route('purchase_orders.destroy', $order->id) }}" method="POST" class="d-inline">
                                            @csrf @method('DELETE')
                                            <button class="btn btn-link p-0 text-danger" onclick="return confirm('Delete this order?')" title="Delete">
                                                <i class="fa fa-trash-alt"></i>
                                            </button>
                                        </form>
                                        @elseif($order->status !== 'cancelled')
                                        <form action="{{ route('purchase_orders.cancel', $order->id) }}" method="POST" class="d-inline">
                                            @csrf @method('PUT')
                                            <button class="btn btn-link p-0 text-warning" onclick="return confirm('Cancel this order?')" title="Cancel">
                                                <i class="fa fa-ban"></i>
                                            </button>
                                        </form>
                                        @endif
                                    @endcan
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
    $(document).ready(function() {
        $('#poTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
    });
</script>
@endsection
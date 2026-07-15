@extends('layouts.app')

@section('title', 'Sales | Booked Orders')

@section('content')
<div class="row">
    <div class="col">
        <section class="card">
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <header class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title">Booked Orders</h2>
                @can('sale_orders.create')
                <a href="{{ route('sale_orders.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> Book Order (Walk-in)</a>
                @endcan
            </header>
            <div class="card-body">
                <form method="GET" class="row mb-3">
                    <div class="col-md-3">
                        <select name="status" class="form-control" onchange="this.form.submit()">
                            <option value="all">All Statuses</option>
                            <option value="confirmed" {{ request('status') == 'confirmed' ? 'selected' : '' }}>Confirmed (ready to merge)</option>
                            <option value="merged" {{ request('status') == 'merged' ? 'selected' : '' }}>Merged into Trip</option>
                            <option value="invoiced" {{ request('status') == 'invoiced' ? 'selected' : '' }}>Invoiced</option>
                            <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Cancelled</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="booker_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Bookers</option>
                            @foreach($bookers as $booker)
                                <option value="{{ $booker->id }}" {{ request('booker_id') == $booker->id ? 'selected' : '' }}>{{ $booker->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="soTable">
                        <thead>
                            <tr>
                                <th>#</th><th>Order Date</th><th>Order #</th><th>Customer</th><th>Booker</th>
                                <th>Amount</th><th>Payment Terms</th><th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $index => $order)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($order->order_date)->format('d-M-Y') }}</td>
                                <td>SO-{{ $order->order_no ?? '—' }}</td>
                                <td>{{ $order->customer->name ?? 'N/A' }}</td>
                                <td>{{ $order->booker->name ?? 'N/A' }}</td>
                                <td>{{ number_format($order->total_amount, 2) }}</td>
                                <td><span class="badge bg-{{ $order->payment_terms === 'cash' ? 'success' : 'warning' }}">{{ ucfirst($order->payment_terms) }}</span></td>
                                <td>
                                    @php
                                        $badge = ['draft'=>'secondary','confirmed'=>'info','merged'=>'primary','invoiced'=>'success','cancelled'=>'danger'][$order->status] ?? 'secondary';
                                    @endphp
                                    <span class="badge bg-{{ $badge }}">{{ ucfirst($order->status) }}</span>
                                </td>
                                <td>
                                    @if(!in_array($order->status, ['merged','invoiced']))
                                        @can('sale_orders.edit')
                                        <a href="{{ route('sale_orders.edit', $order->id) }}" class="text-primary me-1" title="Edit"><i class="fas fa-edit"></i></a>
                                        <form action="{{ route('sale_orders.cancel', $order->id) }}" method="POST" class="d-inline">
                                            @csrf @method('PUT')
                                            <button class="btn btn-link p-0 text-danger" onclick="return confirm('Cancel this order?')" title="Cancel"><i class="fa fa-ban"></i></button>
                                        </form>
                                        @endcan
                                    @endif
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

<script>$(document).ready(() => $('#soTable').DataTable({ pageLength: 50, order: [[0, 'desc']] }));</script>
@endsection
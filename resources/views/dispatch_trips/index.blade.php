@extends('layouts.app')

@section('title', 'Sales | Dispatch Trips')

@section('content')
<div class="row">
    <div class="col">
        <section class="card">
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <header class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title">Dispatch Trips</h2>
                @can('dispatch_trips.create')
                <a href="{{ route('dispatch_trips.create') }}" class="btn btn-primary"><i class="fas fa-plus"></i> New Trip</a>
                @endcan
            </header>

            <div class="card-body">
                <table class="table table-bordered table-striped" id="tripTable">
                    <thead>
                        <tr>
                            <th>#</th><th>Date</th><th>Trip #</th><th>Vehicle</th><th>Delivery Manager</th>
                            <th>Orders</th><th>Amount</th><th>Status</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($trips as $index => $trip)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td>{{ \Carbon\Carbon::parse($trip->trip_date)->format('d-M-Y') }}</td>
                            <td>TR-{{ $trip->trip_no }}</td>
                            <td>{{ $trip->vehicle_no }}</td>
                            <td>{{ $trip->deliveryManager->name ?? 'N/A' }}</td>
                            <td>{{ $trip->total_orders }}</td>
                            <td>{{ number_format($trip->total_amount, 2) }}</td>
                            <td>
                                @php $badge = ['planned'=>'secondary','dispatched'=>'primary','settled'=>'success','cancelled'=>'danger'][$trip->status] ?? 'secondary'; @endphp
                                <span class="badge bg-{{ $badge }}">{{ ucfirst($trip->status) }}</span>
                            </td>
                            <td>
                                <a href="{{ route('dispatch_trips.show', $trip->id) }}" class="text-primary" title="Manage"><i class="fas fa-eye"></i></a>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<script>$(document).ready(() => $('#tripTable').DataTable({ pageLength: 50, order: [[0,'desc']] }));</script>
@endsection
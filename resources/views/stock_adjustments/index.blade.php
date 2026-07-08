@extends('layouts.app')

@section('title', 'Stock | Adjustments')

@section('content')
<div class="row">
    <div class="col">
        <section class="card">
            @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
            @if (session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

            <header class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title">Stock Adjustments</h2>
                @can('stock_adjustments.create')
                <a href="{{ route('stock_adjustments.create') }}" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Adjustment
                </a>
                @endcan
            </header>

            <div class="card-body">
                <form method="GET" class="row mb-3">
                    <div class="col-md-3">
                        <select name="reason_type" class="form-control" onchange="this.form.submit()">
                            <option value="all">All Reasons</option>
                            <option value="damage" {{ request('reason_type') === 'damage' ? 'selected' : '' }}>Damage</option>
                            <option value="loss" {{ request('reason_type') === 'loss' ? 'selected' : '' }}>Loss</option>
                            <option value="theft" {{ request('reason_type') === 'theft' ? 'selected' : '' }}>Theft</option>
                            <option value="stock_take_correction" {{ request('reason_type') === 'stock_take_correction' ? 'selected' : '' }}>Stock-take Correction</option>
                            <option value="other" {{ request('reason_type') === 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select name="location_id" class="form-control" onchange="this.form.submit()">
                            <option value="">All Locations</option>
                            @foreach($locations as $loc)
                                <option value="{{ $loc->id }}" {{ request('location_id') == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="saTable">
                        <thead>
                            <tr>
                                <th>#</th><th>Date</th><th>Adj. #</th><th>Location</th><th>Reason</th>
                                <th>Increase Value</th><th>Decrease Value</th><th>Created By</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($adjustments as $index => $adj)
                            <tr>
                                <td>{{ $index + 1 }}</td>
                                <td>{{ \Carbon\Carbon::parse($adj->adjustment_date)->format('d-M-Y') }}</td>
                                <td>SA-{{ $adj->adjustment_no }}</td>
                                <td>{{ $adj->location->name ?? '—' }}</td>
                                <td><span class="badge bg-secondary">{{ ucfirst(str_replace('_',' ',$adj->reason_type)) }}</span></td>
                                <td class="text-success">{{ $adj->total_increase_value > 0 ? '+' . number_format($adj->total_increase_value, 2) : '—' }}</td>
                                <td class="text-danger">{{ $adj->total_decrease_value > 0 ? '-' . number_format($adj->total_decrease_value, 2) : '—' }}</td>
                                <td>{{ $adj->creator->name ?? 'N/A' }}</td>
                                <td>
                                    <a href="{{ route('stock_adjustments.show', $adj->id) }}" class="text-primary" title="View"><i class="fas fa-eye"></i></a>
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
        $('#saTable').DataTable({ pageLength: 50, order: [[0, 'desc']] });
    });
</script>
@endsection
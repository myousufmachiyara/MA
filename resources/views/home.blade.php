@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
	<div class="mb-3">
		<h2 class="text-dark"><strong id="currentDate"></strong></h2>
	</div>

	{{-- ══════════════════ FINANCIAL SNAPSHOT ══════════════════ --}}
	@if(isset($todaySales) || isset($todayPurchases) || isset($receivables) || isset($payables))
	<div class="row">
		@can('sale_invoices.index')
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-success">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Today's Sales</strong></h3>
					<h2 class="amount m-0 text-success">
						<strong>{{ number_format($todaySales, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a class="text-success text-uppercase" href="{{ route('reports.sale') }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-primary">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Month's Sales</strong></h3>
					<h2 class="amount m-0 text-primary">
						<strong>{{ number_format($monthSales, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a class="text-primary text-uppercase" href="{{ route('reports.sale') }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		@endcan

		@can('reports.accounts')
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-danger">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Receivables</strong></h3>
					<h2 class="amount m-0 text-danger">
						<strong>{{ number_format($receivables, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a class="text-danger text-uppercase" href="{{ route('reports.accounts', ['tab' => 'receivables']) }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-tertiary">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Payables</strong></h3>
					<h2 class="amount m-0 text-tertiary">
						<strong>{{ number_format($payables, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a class="text-tertiary text-uppercase" href="{{ route('reports.accounts', ['tab' => 'payables']) }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		@endcan
	</div>
	@endif

	{{-- ══════════════════ PURCHASE SNAPSHOT ══════════════════ --}}
	@can('purchase_invoices.index')
	<div class="row">
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-warning">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Today's Purchases</strong></h3>
					<h2 class="amount m-0" style="color:#c07800">
						<strong>{{ number_format($todayPurchases, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a href="{{ route('reports.purchase') }}" style="color:#c07800" class="text-uppercase">View Details</a>
					</div>
				</div>
			</section>
		</div>
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-warning">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Month's Purchases</strong></h3>
					<h2 class="amount m-0" style="color:#c07800">
						<strong>{{ number_format($monthPurchases, 2) }}</strong>
						<span class="title text-end text-dark h6"> PKR</span>
					</h2>
					<div class="summary-footer">
						<a href="{{ route('reports.purchase') }}" style="color:#c07800" class="text-uppercase">View Details</a>
					</div>
				</div>
			</section>
		</div>

		@can('sale_orders.index')
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-primary">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Pending Orders</strong></h3>
					<h2 class="amount m-0 text-primary"><strong>{{ $pendingOrders ?? 0 }}</strong></h2>
					<div class="summary-footer">
						<a class="text-primary text-uppercase" href="{{ route('sale_orders.index') }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		@endcan

		@can('dispatch_trips.index')
		<div class="col-12 col-md-3 mb-2">
			<section class="card card-featured-left card-featured-danger">
				<div class="card-body icon-container data-container">
					<h3 class="amount text-dark"><strong>Trips Awaiting Settlement</strong></h3>
					<h2 class="amount m-0 text-danger"><strong>{{ $tripsAwaitingSettlement ?? 0 }}</strong></h2>
					<div class="summary-footer">
						<a class="text-danger text-uppercase" href="{{ route('dispatch_trips.index') }}">View Details</a>
					</div>
				</div>
			</section>
		</div>
		@endcan
	</div>
	@endcan

	{{-- ══════════════════ STOCK ALERTS + WORKFORCE ══════════════════ --}}
	@if(isset($lowStockItems) || isset($activeBookers))
	<div class="row">

		@can('reports.inventory')
		<div class="col-12 col-md-6 mb-3 d-flex">
			<section class="card flex-fill">
				<header class="card-header">
					<h2 class="card-title">Low Stock Alerts (≤10 units)</h2>
				</header>
				<div class="card-body scrollable-div">
					<table class="table table-responsive-md table-striped mb-0">
						<thead class="sticky-tbl-header">
							<tr><th>Item</th><th>Variation</th><th class="text-end">Qty</th></tr>
						</thead>
						<tbody>
							@forelse($lowStockItems as $row)
							<tr class="{{ $row->total_qty <= 0 ? 'table-danger' : 'table-warning' }}">
								<td>{{ $row->product->name ?? 'N/A' }}</td>
								<td>{{ $row->variation->sku ?? '—' }}</td>
								<td class="text-end">{{ number_format($row->total_qty, 2) }}</td>
							</tr>
							@empty
							<tr><td colspan="3" class="text-center text-muted py-3">No low-stock items right now.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
				<footer class="card-footer text-end">
					<a href="{{ route('reports.inventory') }}" class="btn btn-sm btn-outline-primary">Full Inventory Report</a>
				</footer>
			</section>
		</div>
		@endcan

		@can('mobile_users.index')
		<div class="col-12 col-md-6 mb-3 d-flex">
			<section class="card flex-fill">
				<header class="card-header">
					<h2 class="card-title">Mobile Workforce</h2>
				</header>
				<div class="card-body">
					<div class="row text-center">
						<div class="col-6">
							<h2 class="text-primary">{{ $activeBookers ?? 0 }}</h2>
							<p class="text-muted mb-0">Active Order Bookers</p>
						</div>
						<div class="col-6">
							<h2 class="text-success">{{ $activeDrivers ?? 0 }}</h2>
							<p class="text-muted mb-0">Active Delivery Managers</p>
						</div>
					</div>
				</div>
				<footer class="card-footer text-end">
					<a href="{{ route('mobile_users.index') }}" class="btn btn-sm btn-outline-primary">Manage Mobile Users</a>
				</footer>
			</section>
		</div>
		@endcan

	</div>
	@endif

	{{-- ══════════════════ RECENT ACTIVITY ══════════════════ --}}
	@if(isset($recentSales) || isset($recentPurchases))
	<div class="row">

		@can('sale_invoices.index')
		<div class="col-12 col-md-6 mb-3 d-flex">
			<section class="card flex-fill">
				<header class="card-header"><h2 class="card-title">Recent Sale Invoices</h2></header>
				<div class="card-body scrollable-div">
					<table class="table table-responsive-md table-striped mb-0">
						<thead class="sticky-tbl-header">
							<tr><th>Invoice #</th><th>Date</th><th>Customer</th><th class="text-end">Amount</th></tr>
						</thead>
						<tbody>
							@forelse($recentSales as $inv)
							<tr>
								<td><a href="{{ route('sale_invoices.show', $inv->id) }}">SI-{{ $inv->invoice_no }}</a></td>
								<td>{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d-M-Y') }}</td>
								<td>{{ $inv->customer->name ?? 'N/A' }}</td>
								<td class="text-end">{{ number_format($inv->total_amount, 2) }}</td>
							</tr>
							@empty
							<tr><td colspan="4" class="text-center text-muted py-3">No sale invoices yet.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</section>
		</div>
		@endcan

		@can('purchase_invoices.index')
		<div class="col-12 col-md-6 mb-3 d-flex">
			<section class="card flex-fill">
				<header class="card-header"><h2 class="card-title">Recent Purchase Invoices</h2></header>
				<div class="card-body scrollable-div">
					<table class="table table-responsive-md table-striped mb-0">
						<thead class="sticky-tbl-header">
							<tr><th>Invoice #</th><th>Date</th><th>Vendor</th><th class="text-end">Amount</th></tr>
						</thead>
						<tbody>
							@forelse($recentPurchases as $inv)
							<tr>
								<td><a href="{{ route('purchase_invoices.print', $inv->id) }}" target="_blank">PUR-{{ $inv->invoice_no }}</a></td>
								<td>{{ \Carbon\Carbon::parse($inv->invoice_date)->format('d-M-Y') }}</td>
								<td>{{ $inv->vendor->name ?? 'N/A' }}</td>
								<td class="text-end">{{ number_format($inv->total_amount, 2) }}</td>
							</tr>
							@empty
							<tr><td colspan="4" class="text-center text-muted py-3">No purchase invoices yet.</td></tr>
							@endforelse
						</tbody>
					</table>
				</div>
			</section>
		</div>
		@endcan

	</div>
	@endif

	@if(!isset($todaySales) && !isset($todayPurchases) && !isset($receivables) && !isset($lowStockItems) && !isset($activeBookers))
	<div class="alert alert-info">
		<i class="fas fa-info-circle me-1"></i>
		No dashboard widgets are available for your current role. Contact your administrator if you believe this is incorrect.
	</div>
	@endif

	<script>
		$(document).ready(function() {
			const now = new Date();
			const day = getDaySuffix(now.getDate());
			const formattedDate = `${now.toLocaleString('en-GB', { weekday: 'long' })}, ${day} ${now.toLocaleString('en-GB', { month: 'long' })} ${now.getFullYear()}`;
			document.getElementById('currentDate').innerText = formattedDate;
		});

		function getDaySuffix(day) {
			if (day >= 11 && day <= 13) return day + 'th';
			switch (day % 10) {
				case 1: return day + 'st';
				case 2: return day + 'nd';
				case 3: return day + 'rd';
				default: return day + 'th';
			}
		}
	</script>
@endsection
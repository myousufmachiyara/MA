@extends('layouts.app')

@section('title', 'Sales | New Dispatch Trip')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('dispatch_trips.store') }}" method="POST">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">New Dispatch Trip</h2></header>
        <div class="card-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label>Trip Date</label>
              <input type="date" name="trip_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Vehicle No. (Suzuki)</label>
              <input type="text" name="vehicle_no" class="form-control" placeholder="e.g. ABC-123" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Delivery Manager</label>
              <select name="delivery_manager_id" class="form-control select2-js" required>
                <option value="">Select</option>
                @foreach($deliveryManagers as $dm)
                  <option value="{{ $dm->id }}">{{ $dm->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3 mb-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>
        </div>
        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success">Create Trip & Add Orders</button>
        </footer>
      </section>
    </form>
  </div>
</div>
<script>$(document).ready(() => $('.select2-js').select2({ width: '100%' }));</script>
@endsection
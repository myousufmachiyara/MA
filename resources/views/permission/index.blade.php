@extends('layouts.app')

@section('title', 'All Permissions')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">All Permissions</h2></header>
      <div class="card-body">
        @foreach($grouped as $module => $permissions)
          <h5 class="mt-3">{{ ucwords(str_replace('_', ' ', $module)) }}</h5>
          <div class="d-flex flex-wrap gap-2 mb-2">
            @foreach($permissions as $p)
              <span class="badge bg-secondary">{{ $p->name }}</span>
            @endforeach
          </div>
        @endforeach
      </div>
    </section>
  </div>
</div>
@endsection
@extends('layouts.app')

@section('title', 'Activity Log — ' . $user->name)

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Activity Log — {{ $user->name }} ({{ $user->phone }})</h2>
        <a href="{{ route('mobile_users.index') }}" class="btn btn-default">Back</a>
      </header>
      <div class="card-body">
        <table class="table table-bordered table-striped">
          <thead>
            <tr>
              <th>Date/Time</th>
              <th>Activity</th>
              <th>Description</th>
              <th>IP</th>
              <th>Device</th>
              <th>App Version</th>
            </tr>
          </thead>
          <tbody>
            @forelse($logs as $log)
              <tr>
                <td>{{ $log->created_at->format('d-m-Y h:i A') }}</td>
                <td><span class="badge bg-primary">{{ ucfirst(str_replace('_',' ',$log->activity_type)) }}</span></td>
                <td>{{ $log->description ?? '—' }}</td>
                <td>{{ $log->ip_address ?? '—' }}</td>
                <td>{{ $log->device_id ?? '—' }}</td>
                <td>{{ $log->app_version ?? '—' }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="text-center text-muted">No activity recorded yet.</td></tr>
            @endforelse
          </tbody>
        </table>
        {{ $logs->links() }}
      </div>
    </section>
  </div>
</div>
@endsection
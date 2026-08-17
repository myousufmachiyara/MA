@extends('layouts.app')

@section('title', 'Settings | Account Mapping')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif

      <header class="card-header">
        <h2 class="card-title">System Account Mapping</h2>
        <p class="text-muted mb-0">These accounts are used automatically whenever Purchase, Sale, Settlement, or Stock Adjustment posts an accounting entry.</p>
      </header>

      <form action="{{ route('settings.accountMappings.update') }}" method="POST">
        @csrf @method('PUT')
        <div class="card-body">
          <table class="table table-bordered align-middle">
            <thead class="table-dark">
              <tr><th>Role</th><th>Description</th><th style="width:300px">Mapped Account</th></tr>
            </thead>
            <tbody>
              @foreach($mappings as $m)
              <tr class="{{ !$m->account_id ? 'table-warning' : '' }}">
                <td class="fw-bold">{{ $m->label }}</td>
                <td class="text-muted small">{{ $m->description }}</td>
                <td>
                  <select name="mappings[{{ $m->key }}]" class="form-control select2-js" required>
                    <option value="">-- Not Configured --</option>
                    @foreach($accounts as $acc)
                      <option value="{{ $acc->id }}" {{ $m->account_id == $acc->id ? 'selected' : '' }}>
                        [{{ $acc->account_code }}] {{ $acc->name }}
                      </option>
                    @endforeach
                  </select>
                  @if(!$m->account_id)
                    <small class="text-danger">Not configured — controllers using this will throw an error until set.</small>
                  @endif
                </td>
              </tr>
              @endforeach
            </tbody>
          </table>
        </div>
        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-primary">Save Mappings</button>
        </footer>
      </form>
    </section>
  </div>
</div>
<script>$(document).ready(() => $('.select2-js').select2({ width: '100%' }));</script>
@endsection
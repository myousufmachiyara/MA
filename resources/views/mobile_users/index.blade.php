@extends('layouts.app')

@section('title', 'Order Bookers')

@section('content')

@if (session('success'))
    <div class="alert alert-success alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('success') }}
    </div>
@elseif (session('error'))
    <div class="alert alert-danger alert-dismissible">
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        {{ session('error') }}
    </div>
@endif

<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Order Bookers (Mobile Users)</h2>
        @can('mobile_users.create')
         <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
            <i class="fas fa-plus"></i> Add Booker
        </button>
        @endcan
      </header>

      <div class="card-body">
        <div class="modal-wrapper table-scroll">
          <table class="table table-bordered table-striped mb-0" id="bookers-datatable">
            <thead>
              <tr>
                <th>#</th>
                <th>Name</th>
                <th>Phone</th>
                <th>Employee Code</th>
                <th>Area</th>
                <th>Device Linked</th>
                <th>Last Active</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              @foreach($bookers as $index => $booker)
                <tr>
                  <td>{{ $index + 1 }}</td>
                  <td>{{ $booker->name }}</td>
                  <td>{{ $booker->phone }}</td>
                  <td>{{ $booker->employee_code ?? '—' }}</td>
                  <td>{{ $booker->assigned_area ?? '—' }}</td>
                  <td>
                    @if($booker->device_id)
                      <span class="badge bg-info">Linked</span>
                    @else
                      <span class="badge bg-secondary">Not linked</span>
                    @endif
                  </td>
                  <td>{{ $booker->last_active_at ? $booker->last_active_at->diffForHumans() : '—' }}</td>
                  <td>
                    <span class="badge {{ $booker->is_active ? 'bg-success' : 'bg-secondary' }}">
                        {{ $booker->is_active ? 'Active' : 'Inactive' }}
                    </span>
                  </td>
                  <td class="actions">
                    @can('mobile_users.edit')
                    <a href="javascript:void(0)" class="text-primary me-1" onclick="openEditModal({{ $booker->id }})" title="Edit">
                      <i class="fa fa-edit"></i>
                    </a>
                    <form action="{{ route('mobile_users.toggleActive', $booker->id) }}" method="POST" class="d-inline">
                      @csrf @method('PUT')
                      <button type="submit" class="btn btn-link p-0 m-0 me-1" title="{{ $booker->is_active ? 'Deactivate' : 'Activate' }}">
                        <i class="fa fa-toggle-{{ $booker->is_active ? 'on text-success' : 'off text-muted' }}"></i>
                      </button>
                    </form>
                    <form action="{{ route('mobile_users.resetDevice', $booker->id) }}" method="POST" class="d-inline"
                          onsubmit="return confirm('Unlink this booker\'s device? They can log in fresh on a new phone.')">
                      @csrf @method('PUT')
                      <button type="submit" class="btn btn-link p-0 m-0 me-1 text-warning" title="Reset Device">
                        <i class="fa fa-mobile-alt"></i>
                      </button>
                    </form>
                    @endcan
                    @can('mobile_users.index')
                    <a href="{{ route('mobile_users.activity', $booker->id) }}" class="text-secondary me-1" title="Activity Log">
                      <i class="fa fa-history"></i>
                    </a>
                    @endcan
                    @can('mobile_users.delete')
                    <form action="{{ route('mobile_users.destroy', $booker->id) }}" method="POST" class="d-inline"
                          onsubmit="return confirm('Delete {{ addslashes($booker->name) }}? This cannot be undone.')">
                      @csrf @method('DELETE')
                      <button type="submit" class="btn btn-link p-0 m-0 text-danger" title="Delete">
                        <i class="fas fa-trash-alt"></i>
                      </button>
                    </form>
                    @endcan
                  </td>
                </tr>
              @endforeach
            </tbody>
          </table>
        </div>
      </div>
    </section>

    {{-- ── ADD MODAL ─────────────────────────────────────── --}}
    @can('mobile_users.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form action="{{ route('mobile_users.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
          @csrf
          <header class="card-header"><h2 class="card-title">Add Order Booker</h2></header>
          <div class="card-body">
            <div class="mb-2">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="mb-2">
              <label>Phone <span class="text-danger">*</span></label>
              <input type="text" name="phone" class="form-control" placeholder="03XXXXXXXXX" required>
            </div>
            <div class="mb-2">
              <label>Role <span class="text-danger">*</span></label>
              <select name="mobile_role" class="form-control" required>
                <option value="booker">Order Booker</option>
                <option value="delivery_manager">Delivery Manager</option>
              </select>
            </div>
            <div class="mb-2">
              <label>Username <small class="text-muted">(optional — defaults to phone)</small></label>
              <input type="text" name="username" class="form-control">
            </div>
            <div class="mb-2">
              <label>Password <span class="text-danger">*</span></label>
              <input type="password" name="password" class="form-control" required autocomplete="new-password">
            </div>
            <div class="mb-2">
              <label>Confirm Password <span class="text-danger">*</span></label>
              <input type="password" name="password_confirmation" class="form-control" required autocomplete="new-password">
            </div>
            <div class="mb-2">
              <label>Employee Code <small class="text-muted">(Company's booker ID)</small></label>
              <input type="text" name="employee_code" class="form-control">
            </div>
            <div class="mb-2">
              <label>Assigned Area / Route</label>
              <input type="text" name="assigned_area" class="form-control">
            </div>
            <div class="mb-2">
              <label>CNIC</label>
              <input type="text" name="cnic" class="form-control">
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Create Booker</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    {{-- ── EDIT MODAL ────────────────────────────────────── --}}
    @can('mobile_users.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form id="editBookerForm" method="POST" onkeydown="return event.key != 'Enter';">
          @csrf @method('PUT')
          <header class="card-header"><h2 class="card-title">Edit Booker</h2></header>
          <div class="card-body">
            <div class="mb-2">
              <label>Name <span class="text-danger">*</span></label>
              <input type="text" name="name" id="edit_name" class="form-control" required>
            </div>
            <div class="mb-2">
              <label>Phone <span class="text-danger">*</span></label>
              <input type="text" name="phone" id="edit_phone" class="form-control" required>
            </div>
            <div class="mb-2">
              <label>Role <span class="text-danger">*</span></label>
              <select name="mobile_role" id="edit_mobile_role" class="form-control" required>
                <option value="booker">Order Booker</option>
                <option value="delivery_manager">Delivery Manager</option>
              </select>
            </div>
            <div class="mb-2">
              <label>Employee Code</label>
              <input type="text" name="employee_code" id="edit_employee_code" class="form-control">
            </div>
            <div class="mb-2">
              <label>Assigned Area / Route</label>
              <input type="text" name="assigned_area" id="edit_assigned_area" class="form-control">
            </div>
            <div class="mb-2">
              <label>CNIC</label>
              <input type="text" name="cnic" id="edit_cnic" class="form-control">
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update Booker</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

  </div>
</div>

<script>
function openEditModal(id) {
    fetch('/mobile-users/' + id, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(res => res.json())
    .then(res => {
        if (!res.status) { alert('Booker not found.'); return; }
        const u = res.data;
        document.getElementById('editBookerForm').action = '/mobile-users/' + u.id;
        document.getElementById('edit_name').value = u.name;
        document.getElementById('edit_phone').value = u.phone;
        document.getElementById('edit_employee_code').value = u.employee_code ?? '';
        document.getElementById('edit_assigned_area').value = u.assigned_area ?? '';
        document.getElementById('edit_cnic').value = u.cnic ?? '';
        document.getElementById('edit_mobile_role').value = u.mobile_role ?? 'booker';

        $.magnificPopup.open({ items: { src: '#editModal' }, type: 'inline' });
    })
    .catch(() => alert('Error loading booker details.'));
}

$(document).ready(function() {
    $('#bookers-datatable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});
</script>
@endsection
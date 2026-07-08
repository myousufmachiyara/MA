@extends('layouts.app')

@section('title', 'Stock | Locations')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      @if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
      @if(session('error'))<div class="alert alert-danger">{{ session('error') }}</div>@endif

      <header class="card-header d-flex justify-content-between">
        <h2 class="card-title">Locations / Warehouses</h2>
        @can('locations.create')
        <button type="button" class="modal-with-form btn btn-primary" href="#addModal"><i class="fas fa-plus"></i> Add Location</button>
        @endcan
      </header>

      <div class="card-body">
        <table class="table table-bordered table-striped">
          <thead><tr><th>Code</th><th>Name</th><th>Address</th><th>Phone</th><th>Default</th><th>Status</th><th>Actions</th></tr></thead>
          <tbody>
            @foreach($locations as $loc)
            <tr>
              <td><code>{{ $loc->code }}</code></td>
              <td>{{ $loc->name }}</td>
              <td>{{ $loc->address ?? '—' }}</td>
              <td>{{ $loc->contact_no ?? '—' }}</td>
              <td>{{ $loc->is_default ? '⭐' : '' }}</td>
              <td><span class="badge bg-{{ $loc->is_active ? 'success' : 'secondary' }}">{{ $loc->is_active ? 'Active' : 'Inactive' }}</span></td>
              <td>
                @can('locations.edit')
                <a href="#" onclick="editLocation({{ $loc->id }})" class="text-primary me-1"><i class="fa fa-edit"></i></a>
                <form action="{{ route('locations.toggleActive', $loc->id) }}" method="POST" class="d-inline">
                  @csrf @method('PUT')
                  <button class="btn btn-link p-0 m-0 me-1"><i class="fa fa-toggle-{{ $loc->is_active ? 'on text-success' : 'off text-muted' }}"></i></button>
                </form>
                @endcan
                @can('locations.delete')
                <form action="{{ route('locations.destroy', $loc->id) }}" method="POST" class="d-inline">
                  @csrf @method('DELETE')
                  <button class="btn btn-link p-0 text-danger" onclick="return confirm('Delete this location?')"><i class="fa fa-trash-alt"></i></button>
                </form>
                @endcan
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>

    @can('locations.create')
    <div id="addModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" action="{{ route('locations.store') }}">
          @csrf
          <header class="card-header"><h2 class="card-title">Add Location</h2></header>
          <div class="card-body">
            <div class="mb-2"><label>Name *</label><input type="text" name="name" class="form-control" required></div>
            <div class="mb-2"><label>Code *</label><input type="text" name="code" class="form-control" placeholder="e.g. WH2" required></div>
            <div class="mb-2"><label>Address</label><textarea name="address" class="form-control"></textarea></div>
            <div class="mb-2"><label>Phone</label><input type="text" name="contact_no" class="form-control"></div>
            <div class="form-check">
              <input type="checkbox" name="is_default" value="1" class="form-check-input" id="add_is_default">
              <label class="form-check-label" for="add_is_default">Set as default location</label>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Add</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan

    @can('locations.edit')
    <div id="editModal" class="modal-block modal-block-primary mfp-hide">
      <section class="card">
        <form method="POST" id="editLocForm">
          @csrf @method('PUT')
          <header class="card-header"><h2 class="card-title">Edit Location</h2></header>
          <div class="card-body">
            <div class="mb-2"><label>Name *</label><input type="text" name="name" id="edit_name" class="form-control" required></div>
            <div class="mb-2"><label>Code *</label><input type="text" name="code" id="edit_code" class="form-control" required></div>
            <div class="mb-2"><label>Address</label><textarea name="address" id="edit_address" class="form-control"></textarea></div>
            <div class="mb-2"><label>Phone</label><input type="text" name="contact_no" id="edit_contact_no" class="form-control"></div>
            <div class="form-check">
              <input type="checkbox" name="is_default" value="1" class="form-check-input" id="edit_is_default">
              <label class="form-check-label" for="edit_is_default">Set as default location</label>
            </div>
          </div>
          <footer class="card-footer text-end">
            <button type="submit" class="btn btn-primary">Update</button>
            <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
          </footer>
        </form>
      </section>
    </div>
    @endcan
  </div>
</div>

<script>
function editLocation(id) {
    fetch('/locations/' + id + '/edit')
        .then(res => res.json())
        .then(data => {
            $('#editLocForm').attr('action', '/locations/' + id);
            $('#edit_name').val(data.name);
            $('#edit_code').val(data.code);
            $('#edit_address').val(data.address);
            $('#edit_contact_no').val(data.contact_no);
            $('#edit_is_default').prop('checked', !!data.is_default);
            $.magnificPopup.open({ items: { src: '#editModal' }, type: 'inline' });
        });
}
</script>
@endsection
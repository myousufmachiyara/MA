@extends('layouts.app')

@section('title', 'Stock | New Transfer')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('stock_transfer.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">New Stock Transfer</h2></header>
        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Date</label>
              <input type="date" name="transfer_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3">
              <label>From Location</label>
              <select name="from_location_id" class="form-control select2-js" required>
                <option value="">Select</option>
                @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>To Location</label>
              <select name="to_location_id" class="form-control select2-js" required>
                <option value="">Select</option>
                @foreach($locations as $loc)<option value="{{ $loc->id }}">{{ $loc->name }}</option>@endforeach
              </select>
            </div>
            <div class="col-md-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead><tr><th>Item</th><th>Variation</th><th>Quantity</th><th></th></tr></thead>
            <tbody id="ItemsBody">
              <tr>
                <td>
                  <select name="items[0][item_id]" id="item_name1" class="form-control select2-js" onchange="onItemChange(this)">
                    <option value="">Select Item</option>
                    @foreach($products as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
                  </select>
                </td>
                <td>
                  <select name="items[0][variation_id]" id="variation1" class="form-control select2-js">
                    <option value="">No Variation</option>
                  </select>
                </td>
                <td><input type="number" name="items[0][quantity]" class="form-control" value="0" step="any"></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
              </tr>
            </tbody>
          </table>
          <button type="button" class="btn btn-outline-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>
        </div>
        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success">Save Transfer</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = 2;

  $(document).ready(() => $('.select2-js').select2({ width: '100%' }));

  function addRow() {
    let rowIndex = index - 1;
    let row = `<tr>
      <td>
        <select name="items[${rowIndex}][item_id]" id="item_name${index}" class="form-control select2-js" onchange="onItemChange(this)">
          <option value="">Select Item</option>
          ${products.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}
        </select>
      </td>
      <td><select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js"><option value="">No Variation</option></select></td>
      <td><input type="number" name="items[${rowIndex}][quantity]" class="form-control" value="0" step="any"></td>
      <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#ItemsBody').append(row);
    $(`#item_name${index}, #variation${index}`).select2({ width: '100%' });
    index++;
  }

  function removeRow(btn) {
    if ($('#ItemsBody tr').length > 1) $(btn).closest('tr').remove();
  }

  function onItemChange(el) {
    const idMatch = el.id.match(/\d+$/);
    const rowIndex = idMatch[0];
    const variationSelect = $(`#variation${rowIndex}`);
    const itemId = el.value;

    if (itemId) {
      fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
          variationSelect.html('<option value="">No Variation</option>');
          if (data.success && data.variation.length > 0) {
            data.variation.forEach(v => variationSelect.append(`<option value="${v.id}">${v.sku}</option>`));
          }
          variationSelect.trigger('change.select2');
        });
    }
  }
</script>
@endsection
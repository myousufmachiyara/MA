@extends('layouts.app')

@section('title', 'Stock | New Adjustment')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('stock_adjustments.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">New Stock Adjustment</h2></header>

        <div class="card-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label>Date</label>
              <input type="date" name="adjustment_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Location</label>
              <select name="location_id" class="form-control select2-js">
                @foreach ($locations as $loc)
                  <option value="{{ $loc->id }}" {{ $loc->is_default ? 'selected' : '' }}>{{ $loc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-3 mb-3">
              <label>Reason</label>
              <select name="reason_type" class="form-control" required>
                <option value="damage">Damage</option>
                <option value="loss">Loss</option>
                <option value="theft">Theft</option>
                <option value="stock_take_correction">Stock-take Correction</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-3 mb-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="adjTable">
              <thead>
                <tr>
                  <th>S.No</th><th>Item</th><th>Variation</th><th>Direction</th>
                  <th>Quantity</th><th>Unit Cost</th><th>Value</th><th>Remarks</th><th>Action</th>
                </tr>
              </thead>
              <tbody id="AdjTableBody">
                <tr>
                  <td class="serial-no">1</td>
                  <td>
                    <select name="items[0][item_id]" id="item_name1" class="form-control select2-js" onchange="onItemNameChange(this)">
                      <option value="">Select Item</option>
                      @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                      @endforeach
                    </select>
                  </td>
                  <td>
                    <select name="items[0][variation_id]" id="variation1" class="form-control select2-js">
                      <option value="">No Variation</option>
                    </select>
                  </td>
                  <td>
                    <select name="items[0][direction]" class="form-control" required>
                      <option value="decrease">Decrease (−)</option>
                      <option value="increase">Increase (+)</option>
                    </select>
                  </td>
                  <td><input type="number" name="items[0][quantity]" id="qty1" class="form-control quantity" value="0" step="any" onchange="rowTotal(1)"></td>
                  <td><input type="number" name="items[0][unit_cost]" id="cost1" class="form-control" value="0" step="any" onchange="rowTotal(1)"></td>
                  <td><input type="number" id="value1" class="form-control" value="0" step="any" disabled></td>
                  <td><input type="text" name="items[0][remarks]" class="form-control" placeholder="e.g. crushed carton"></td>
                  <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
                </tr>
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary" onclick="addNewRow()"><i class="fas fa-plus"></i> Add Item</button>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Adjustment</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = 2;

  $(document).ready(function () { $('.select2-js').select2({ width: '100%' }); });

  function updateSerialNumbers() {
    $('#AdjTableBody tr').each(function (i) { $(this).find('.serial-no').text(i + 1); });
  }

  function removeRow(button) {
    if ($('#AdjTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      updateSerialNumbers();
    }
  }

  function addNewRow() {
    let rowIndex = index - 1;
    let newRow = `
      <tr>
        <td class="serial-no"></td>
        <td>
          <select name="items[${rowIndex}][item_id]" id="item_name${index}" class="form-control select2-js" onchange="onItemNameChange(this)">
            <option value="">Select Item</option>
            ${products.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}
          </select>
        </td>
        <td>
          <select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js">
            <option value="">No Variation</option>
          </select>
        </td>
        <td>
          <select name="items[${rowIndex}][direction]" class="form-control" required>
            <option value="decrease">Decrease (−)</option>
            <option value="increase">Increase (+)</option>
          </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][quantity]" id="qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td><input type="number" name="items[${rowIndex}][unit_cost]" id="cost${index}" class="form-control" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td><input type="number" id="value${index}" class="form-control" value="0" step="any" disabled></td>
        <td><input type="text" name="items[${rowIndex}][remarks]" class="form-control"></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
      </tr>`;
    $('#AdjTableBody').append(newRow);
    $(`#item_name${index}, #variation${index}`).select2({ width: '100%' });
    index++;
    updateSerialNumbers();
  }

  function rowTotal(row) {
    let qty = parseFloat($('#qty' + row).val()) || 0;
    let cost = parseFloat($('#cost' + row).val()) || 0;
    $('#value' + row).val((qty * cost).toFixed(2));
  }

  function onItemNameChange(selectElement) {
    const idMatch = selectElement.id.match(/\d+$/);
    if (!idMatch) return;
    const rowIndex = idMatch[0];
    const variationSelect = $(`#variation${rowIndex}`);
    const itemId = selectElement.value;

    if (itemId) {
      variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');
      fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
          variationSelect.html('<option value="">No Variation</option>');
          if (data.success && data.variation.length > 0) {
            data.variation.forEach(v => variationSelect.append(`<option value="${v.id}">${v.sku}</option>`));
          }
          variationSelect.trigger('change.select2');
        });
    } else {
      variationSelect.html('<option value="">No Variation</option>').trigger('change.select2');
    }
  }
</script>
@endsection
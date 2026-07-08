@extends('layouts.app')

@section('title', 'Purchases | New Order')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('purchase_orders.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">New Purchase Order</h2></header>

        <div class="card-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label>Order Date</label>
              <input type="date" name="order_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-3 mb-3">
              <label>Vendor</label>
              <select name="vendor_id" class="form-control select2-js" required>
                <option value="">Select Vendor</option>
                @foreach ($vendors as $vendor)
                  <option value="{{ $vendor->id }}">{{ $vendor->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-6 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="1"></textarea>
            </div>
          </div>

          <div class="table-responsive mb-3">
            <table class="table table-bordered" id="orderTable">
              <thead>
                <tr>
                  <th>S.No</th><th>Item</th><th>Variation</th><th>Quantity</th><th>Unit</th><th>Est. Price</th><th>Amount</th><th>Action</th>
                </tr>
              </thead>
              <tbody id="OrderTableBody">
                <tr>
                  <td class="serial-no">1</td>
                  <td>
                    <select name="items[0][item_id]" id="item_name1" class="form-control select2-js" onchange="onItemNameChange(this)">
                      <option value="">Select Item</option>
                      @foreach ($products as $product)
                        <option value="{{ $product->id }}" data-unit-id="{{ $product->measurement_unit }}">{{ $product->name }}</option>
                      @endforeach
                    </select>
                  </td>
                  <td>
                    <select name="items[0][variation_id]" id="variation1" class="form-control select2-js">
                      <option value="">Select Variation</option>
                    </select>
                  </td>
                  <td><input type="number" name="items[0][quantity]" id="qty1" class="form-control quantity" value="0" step="any" onchange="rowTotal(1)"></td>
                  <td>
                    <select name="items[0][unit]" id="unit1" class="form-control" required>
                      <option value="">-- Select --</option>
                      @foreach ($units as $unit)
                        <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
                      @endforeach
                    </select>
                  </td>
                  <td><input type="number" name="items[0][price]" id="price1" class="form-control" value="0" step="any" onchange="rowTotal(1)"></td>
                  <td><input type="number" id="amount1" class="form-control" value="0" step="any" disabled></td>
                  <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
                </tr>
              </tbody>
            </table>
            <button type="button" class="btn btn-outline-primary" onclick="addNewRow()"><i class="fas fa-plus"></i> Add Item</button>
          </div>

          <div class="row">
            <div class="col text-end">
              <h4>Total: <strong class="text-danger">PKR <span id="netTotal">0.00</span></strong></h4>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Order</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = 2;

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
  });

  function updateSerialNumbers() {
    $('#OrderTableBody tr').each(function (i) { $(this).find('.serial-no').text(i + 1); });
  }

  function removeRow(button) {
    if ($('#OrderTableBody tr').length > 1) {
      $(button).closest('tr').remove();
      tableTotal();
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
            ${products.map(p => `<option value="${p.id}" data-unit-id="${p.measurement_unit}">${p.name}</option>`).join('')}
          </select>
        </td>
        <td>
          <select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js">
            <option value="">Select Variation</option>
          </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][quantity]" id="qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td>
          <select name="items[${rowIndex}][unit]" id="unit${index}" class="form-control" required>
            <option value="">-- Select --</option>
            @foreach ($units as $unit)
              <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->shortcode }})</option>
            @endforeach
          </select>
        </td>
        <td><input type="number" name="items[${rowIndex}][price]" id="price${index}" class="form-control" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td><input type="number" id="amount${index}" class="form-control" value="0" step="any" disabled></td>
        <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
      </tr>`;
    $('#OrderTableBody').append(newRow);
    $(`#item_name${index}, #variation${index}, #unit${index}`).select2({ width: '100%' });
    index++;
    updateSerialNumbers();
  }

  function rowTotal(row) {
    let qty = parseFloat($('#qty' + row).val()) || 0;
    let price = parseFloat($('#price' + row).val()) || 0;
    $('#amount' + row).val((qty * price).toFixed(2));
    tableTotal();
  }

  function tableTotal() {
    let total = 0;
    $('#OrderTableBody tr').each(function () {
      total += parseFloat($(this).find('input[id^="amount"]').val()) || 0;
    });
    $('#netTotal').text(total.toFixed(2));
  }

  function onItemNameChange(selectElement) {
    const idMatch = selectElement.id.match(/\d+$/);
    if (!idMatch) return;
    const rowIndex = idMatch[0];

    const selectedOption = selectElement.options[selectElement.selectedIndex];
    const unitId = selectedOption.getAttribute('data-unit-id');
    $(`#unit${rowIndex}`).val(String(unitId)).trigger('change.select2');

    const variationSelect = $(`#variation${rowIndex}`);
    const itemId = selectElement.value;

    if (itemId) {
      variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');
      fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
          variationSelect.html('<option value="">Select Variation</option>');
          if (data.success && data.variation.length > 0) {
            data.variation.forEach(v => variationSelect.append(`<option value="${v.id}">${v.sku}</option>`));
          } else {
            variationSelect.html('<option value="">No Variations Found</option>');
          }
          variationSelect.trigger('change.select2');
        });
    } else {
      variationSelect.html('<option value="">Select Variation</option>').trigger('change.select2');
    }
  }
</script>
@endsection
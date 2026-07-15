@extends('layouts.app')

@section('title', 'Sale | Book Order (Walk-in)')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_orders.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">Book Order — Walk-in Customer</h2></header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Customer</label>
              <select name="customer_id" class="form-control select2-js" required>
                <option value="">Select Customer</option>
                @foreach ($customers as $c)
                  <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Order Date</label>
              <input type="date" name="order_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>
            <div class="col-md-2">
              <label>Payment Terms</label>
              <select name="payment_terms" class="form-control" required>
                <option value="cash">Cash</option>
                <option value="credit">Credit</option>
              </select>
            </div>
            <div class="col-md-5">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control">
            </div>
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead>
              <tr><th>S.No</th><th>Item</th><th>Variation</th><th>Quantity</th><th>Unit</th><th>Price</th><th>Amount</th><th>Action</th></tr>
            </thead>
            <tbody id="ItemsBody">
              <tr>
                <td class="serial-no">1</td>
                <td>
                  <select name="items[0][item_id]" id="item_name1" class="form-control select2-js" onchange="onItemChange(this)">
                    <option value="">Select Item</option>
                    @foreach ($products as $p)
                      <option value="{{ $p->id }}" data-unit-id="{{ $p->measurement_unit }}" data-price="{{ $p->selling_price }}">{{ $p->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[0][variation_id]" id="variation1" class="form-control select2-js">
                    <option value="">No Variation</option>
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
          <button type="button" class="btn btn-outline-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>

          <div class="row mt-3">
            <div class="col text-end">
              <h4>Total: <strong class="text-danger">PKR <span id="netTotal">0.00</span></strong></h4>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Book Order</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = 2;

  $(document).ready(() => $('.select2-js').select2({ width: '100%' }));

  function updateSerialNumbers() { $('.serial-no').each((i, el) => $(el).text(i + 1)); }

  function removeRow(btn) {
    if ($('#ItemsBody tr').length > 1) { $(btn).closest('tr').remove(); updateSerialNumbers(); tableTotal(); }
  }

  function addRow() {
    let rowIndex = index - 1;
    let row = `<tr>
      <td class="serial-no"></td>
      <td>
        <select name="items[${rowIndex}][item_id]" id="item_name${index}" class="form-control select2-js" onchange="onItemChange(this)">
          <option value="">Select Item</option>
          ${products.map(p => `<option value="${p.id}" data-unit-id="${p.measurement_unit}" data-price="${p.selling_price}">${p.name}</option>`).join('')}
        </select>
      </td>
      <td><select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js"><option value="">No Variation</option></select></td>
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
    $('#ItemsBody').append(row);
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
    $('input[id^="amount"]').each(function () { total += parseFloat($(this).val()) || 0; });
    $('#netTotal').text(total.toFixed(2));
  }

  function onItemChange(el) {
    const idMatch = el.id.match(/\d+$/);
    const rowIndex = idMatch[0];

    // Auto-fill unit + selling price from the product's own defaults
    const selectedOption = el.options[el.selectedIndex];
    const unitId = selectedOption.getAttribute('data-unit-id');
    const defaultPrice = selectedOption.getAttribute('data-price');
    $(`#unit${rowIndex}`).val(String(unitId)).trigger('change.select2');
    $(`#price${rowIndex}`).val(defaultPrice || 0);
    rowTotal(rowIndex);

    const variationSelect = $(`#variation${rowIndex}`);
    const itemId = el.value;

    if (itemId) {
      fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
          variationSelect.html('<option value="">No Variation</option>');
          if (data.success && data.variation.length > 0) {
            data.variation.forEach(v => {
                variationSelect.append(`<option value="${v.id}" data-price="${v.selling_price ?? 0}">${v.sku}</option>`);
            });
          }
          variationSelect.trigger('change.select2');
        });
    }
  }

  // Auto-fill price from variation's own selling_price when a variation is picked
  $(document).on('change', 'select[id^="variation"]', function () {
    const rowIndex = this.id.match(/\d+$/)[0];
    const selectedOption = this.options[this.selectedIndex];
    const variationPrice = selectedOption.getAttribute('data-price');
    if (variationPrice !== null) {
      $(`#price${rowIndex}`).val(variationPrice);
      rowTotal(rowIndex);
    }
  });
</script>
@endsection
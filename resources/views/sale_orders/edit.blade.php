@extends('layouts.app')

@section('title', 'Sales | Edit Order')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_orders.update', $order->id) }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Edit Order SO-{{ $order->order_no }} — {{ $order->customer->name ?? 'N/A' }}</h2>
        </header>

        <div class="card-body">
          <table class="table table-bordered" id="orderTable">
            <thead>
              <tr><th>S.No</th><th>Item</th><th>Variation</th><th>Quantity</th><th>Unit</th><th>Price</th><th>Amount</th><th>Action</th></tr>
            </thead>
            <tbody id="OrderTableBody">
              @foreach($order->items as $key => $item)
              <tr>
                <td class="serial-no">{{ $key + 1 }}</td>
                <td>
                  <select name="items[{{ $key }}][item_id]" id="item_name{{ $key + 1 }}" class="form-control select2-js" onchange="onItemChange(this)">
                    @foreach ($products as $product)
                      <option value="{{ $product->id }}" data-unit-id="{{ $product->measurement_unit }}" data-price="{{ $product->selling_price }}"
                        {{ $item->item_id == $product->id ? 'selected' : '' }}>{{ $product->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[{{ $key }}][variation_id]" id="variation{{ $key + 1 }}" class="form-control select2-js">
                    <option value="">No Variation</option>
                    @foreach($item->product->variations as $v)
                      <option value="{{ $v->id }}" data-price="{{ $v->selling_price }}" {{ $item->variation_id == $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" name="items[{{ $key }}][quantity]" id="qty{{ $key + 1 }}" class="form-control quantity" value="{{ $item->quantity }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td>
                  <select name="items[{{ $key }}][unit]" class="form-control">
                    @foreach ($units as $unit)
                      <option value="{{ $unit->id }}" {{ $item->unit == $unit->id ? 'selected' : '' }}>{{ $unit->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" name="items[{{ $key }}][price]" id="price{{ $key + 1 }}" class="form-control" value="{{ $item->price }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td><input type="number" id="amount{{ $key + 1 }}" class="form-control" value="{{ $item->quantity * $item->price }}" disabled></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
              </tr>
              @endforeach
            </tbody>
          </table>
          <button type="button" class="btn btn-outline-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>

          <div class="row mt-3">
            <div class="col text-end">
              <h4>Total: <strong class="text-danger">PKR <span id="netTotal">{{ number_format($order->total_amount, 2) }}</span></strong></h4>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-primary">Update Order</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var units = @json($units);
  var index = {{ count($order->items) + 1 }};

  $(document).ready(() => { $('.select2-js').select2({ width: '100%' }); tableTotal(); });

  function addRow() {
    let rowIndex = index - 1;
    let row = `<tr>
      <td class="serial-no"></td>
      <td><select name="items[${rowIndex}][item_id]" id="item_name${index}" class="form-control select2-js" onchange="onItemChange(this)">
        <option value="">Select Item</option>
        ${products.map(p => `<option value="${p.id}" data-unit-id="${p.measurement_unit}" data-price="${p.selling_price}">${p.name}</option>`).join('')}
      </select></td>
      <td><select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js"><option value="">No Variation</option></select></td>
      <td><input type="number" name="items[${rowIndex}][quantity]" id="qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>
      <td><select name="items[${rowIndex}][unit]" id="unit${index}" class="form-control select2-js">${units.map(u => `<option value="${u.id}">${u.name}</option>`).join('')}</select></td>
      <td><input type="number" name="items[${rowIndex}][price]" id="price${index}" class="form-control" value="0" step="any" onchange="rowTotal(${index})"></td>
      <td><input type="number" id="amount${index}" class="form-control" value="0" disabled></td>
      <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#OrderTableBody').append(row);
    $(`#item_name${index}, #variation${index}, #unit${index}`).select2({ width: '100%' });
    index++;
    updateSerialNumbers();
  }

  function removeRow(btn) { $(btn).closest('tr').remove(); updateSerialNumbers(); tableTotal(); }
  function updateSerialNumbers() { $('.serial-no').each((i, el) => $(el).text(i + 1)); }

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

    // FIX: auto-fill unit + selling price from the product's own defaults (was missing entirely)
    const selectedOption = el.options[el.selectedIndex];
    const unitId = selectedOption.getAttribute('data-unit-id');
    const defaultPrice = selectedOption.getAttribute('data-price');
    if (unitId) $(`#unit${rowIndex}`).val(String(unitId)).trigger('change.select2');
    if (defaultPrice !== null) {
      $(`#price${rowIndex}`).val(defaultPrice);
      rowTotal(rowIndex);
    }

    const variationSelect = $(`#variation${rowIndex}`);
    const itemId = el.value;

    if (itemId) {
      fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
          variationSelect.html('<option value="">No Variation</option>');
          if (data.success && data.variation.length > 0) {
            data.variation.forEach(v => {
              // FIX: was missing data-price — this is what the delegated handler below reads
              variationSelect.append(`<option value="${v.id}" data-price="${v.selling_price}">${v.sku}</option>`);
            });
          }
          variationSelect.trigger('change.select2');
        });
    }
  }

  // FIX: this entire handler was missing — auto-fill price when a variation is picked
  $(document).on('change', 'select[id^="variation"]', function () {
    const rowIndex = this.id.match(/\d+$/)[0];
    const selectedOption = this.options[this.selectedIndex];
    const variationPrice = selectedOption.getAttribute('data-price');
    if (variationPrice !== null && variationPrice !== '') {
      $(`#price${rowIndex}`).val(variationPrice);
      rowTotal(rowIndex);
    }
  });
</script>
@endsection
@extends('layouts.app')

@section('title', 'Sale | Edit Invoice')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_invoices.update', $invoice->id) }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header">
          <h2 class="card-title">Edit Sale Invoice: SI-{{ $invoice->invoice_no }}</h2>
        </header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Customer</label>
              <select name="customer_id" class="form-control select2-js" required>
                @foreach ($customers as $c)
                  <option value="{{ $c->id }}" {{ $invoice->customer_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Invoice Date</label>
              <input type="date" name="invoice_date" class="form-control" value="{{ \Carbon\Carbon::parse($invoice->invoice_date)->format('Y-m-d') }}" required>
            </div>
            <div class="col-md-2">
              <label>Location</label>
              <select name="location_id" class="form-control select2-js">
                @foreach ($locations as $loc)
                  <option value="{{ $loc->id }}" {{ $invoice->location_id == $loc->id ? 'selected' : '' }}>{{ $loc->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Payment Terms</label>
              <select name="payment_terms" class="form-control" required>
                <option value="cash" {{ $invoice->payment_terms == 'cash' ? 'selected' : '' }}>Cash</option>
                <option value="credit" {{ $invoice->payment_terms == 'credit' ? 'selected' : '' }}>Credit</option>
              </select>
            </div>
            <div class="col-md-3">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ $invoice->remarks }}">
            </div>
          </div>

          <div class="row mb-3 align-items-end">
            <div class="col-md-2">
              <label>Apply GST?</label>
              <select name="apply_gst" class="form-control" id="applyGst" onchange="toggleGst()">
                <option value="0" {{ !$invoice->is_tax_invoice ? 'selected' : '' }}>No</option>
                <option value="1" {{ $invoice->is_tax_invoice ? 'selected' : '' }}>Yes</option>
              </select>
            </div>
            <div class="col-md-2" id="gstTypeWrap" style="display:none;">
              <label>GST Type</label>
              <select name="gst_type" class="form-control">
                <option value="exclusive" {{ $invoice->gst_type == 'exclusive' ? 'selected' : '' }}>Exclusive</option>
                <option value="inclusive" {{ $invoice->gst_type == 'inclusive' ? 'selected' : '' }}>Inclusive</option>
              </select>
            </div>
            <div class="col-md-2" id="gstRateWrap" style="display:none;">
              <label>GST Rate (%)</label>
              <input type="number" name="gst_rate" class="form-control" step="0.01" value="{{ $invoice->gst_rate ?? 18 }}">
            </div>
            @if($invoice->wht_applicable)
            <div class="col-md-3">
              <div class="alert alert-warning py-1 px-2 mb-0">
                WHT {{ $invoice->wht_rate }}% applies to this customer — deducted at Settlement, not editable here.
              </div>
            </div>
            @endif
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead>
              <tr><th>S.No</th><th>Item</th><th>Variation</th><th>Quantity</th><th>Unit</th><th>Price</th><th>Amount</th><th>Action</th></tr>
            </thead>
            <tbody id="ItemsBody">
              @foreach($invoice->items as $key => $item)
              <tr>
                <td class="serial-no">{{ $key + 1 }}</td>
                <td>
                  <select name="items[{{ $key }}][item_id]" id="item_name{{ $key + 1 }}" class="form-control select2-js" onchange="onItemChange(this)">
                    @foreach ($products as $product)
                      <option value="{{ $product->id }}" data-unit-id="{{ $product->measurement_unit }}"
                        {{ $item->item_id == $product->id ? 'selected' : '' }}>
                        {{ $product->name }}
                      </option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[{{ $key }}][variation_id]" id="variation{{ $key + 1 }}" class="form-control select2-js">
                    <option value="">No Variation</option>
                    @foreach($item->product->variations as $v)
                      <option value="{{ $v->id }}" {{ $item->variation_id == $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" name="items[{{ $key }}][quantity]" id="qty{{ $key + 1 }}" class="form-control quantity" value="{{ $item->quantity }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td>
                  <select name="items[{{ $key }}][unit]" id="unit{{ $key + 1 }}" class="form-control" required>
                    @foreach ($units as $unit)
                      <option value="{{ $unit->id }}" {{ $item->unit == $unit->id ? 'selected' : '' }}>{{ $unit->name }} ({{ $unit->shortcode }})</option>
                    @endforeach
                  </select>
                </td>
                <td><input type="number" name="items[{{ $key }}][price]" id="price{{ $key + 1 }}" class="form-control" value="{{ $item->price }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td><input type="number" id="amount{{ $key + 1 }}" class="form-control" value="{{ $item->quantity * $item->price }}" step="any" disabled></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
              </tr>
              @endforeach
            </tbody>
          </table>
          <button type="button" class="btn btn-outline-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>

          <div class="row mt-3">
            <div class="col text-end">
              <h4>Net Total: <strong class="text-danger">PKR <span id="netTotal">{{ number_format($invoice->net_amount, 2) }}</span></strong></h4>
              @if($invoice->total_amount > 0)
              <p class="text-muted mb-0">Current Total (incl. GST): PKR {{ number_format($invoice->total_amount, 2) }} &nbsp;|&nbsp; Paid So Far: PKR {{ number_format($invoice->paid_amount, 2) }}</p>
              @endif
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <a href="{{ route('sale_invoices.index') }}" class="btn btn-default">Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Invoice</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var units = @json($units);
  var index = {{ count($invoice->items) + 1 }};

  $(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });
    toggleGst();
    tableTotal();
  });

  function toggleGst() {
      $('#gstTypeWrap, #gstRateWrap').toggle($('#applyGst').val() === '1');
  }

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
            ${products.map(p => `<option value="${p.id}" data-unit-id="${p.measurement_unit}">${p.name}</option>`).join('')}
          </select>
        </td>
        <td><select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js"><option value="">No Variation</option></select></td>
        <td><input type="number" name="items[${rowIndex}][quantity]" id="qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>
        <td>
          <select name="items[${rowIndex}][unit]" id="unit${index}" class="form-control" required>
            <option value="">-- Select --</option>
            ${units.map(u => `<option value="${u.id}">${u.name} (${u.shortcode})</option>`).join('')}
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

      const selectedOption = el.options[el.selectedIndex];
      const unitId = selectedOption.getAttribute('data-unit-id');
      $(`#unit${rowIndex}`).val(String(unitId)).trigger('change.select2');

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
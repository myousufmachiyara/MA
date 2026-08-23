@extends('layouts.app')

@section('title', 'Sale Return | Edit')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('sale_return.update', $return->id) }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      @method('PUT')
      <section class="card">
        <header class="card-header"><h2 class="card-title">Edit Sale Return SR-{{ $return->id }}</h2></header>

        <div class="card-body">
          <div class="row mb-3">
            <div class="col-md-3">
              <label>Customer</label>
              <select name="account_id" class="form-control select2-js" required>
                @foreach ($customers as $c)
                  <option value="{{ $c->id }}" {{ $return->account_id == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
              </select>
            </div>
            <div class="col-md-2">
              <label>Return Date</label>
              <input type="date" name="return_date" class="form-control" value="{{ \Carbon\Carbon::parse($return->return_date)->format('Y-m-d') }}" required>
            </div>
            <div class="col-md-3">
              <label>Against Invoice #</label>
              <input type="text" name="sale_invoice_no" class="form-control" value="{{ $return->sale_invoice_no }}" placeholder="e.g. 000123">
            </div>
            <div class="col-md-4">
              <label>Remarks</label>
              <input type="text" name="remarks" class="form-control" value="{{ $return->remarks }}">
            </div>
          </div>

          <table class="table table-bordered" id="itemsTable">
            <thead>
              <tr><th>S.No</th><th>Item</th><th>Variation</th><th>Qty</th><th>Price</th><th>Amount</th><th>Action</th></tr>
            </thead>
            <tbody id="ItemsBody">
              @foreach($return->items as $key => $item)
              <tr>
                <td class="serial-no">{{ $key + 1 }}</td>
                <td>
                  <select name="items[{{ $key }}][product_id]" id="item_name{{ $key + 1 }}" class="form-control select2-js" onchange="onItemChange(this)">
                    @foreach ($products as $product)
                      <option value="{{ $product->id }}" {{ $item->product_id == $product->id ? 'selected' : '' }}>{{ $product->name }}</option>
                    @endforeach
                  </select>
                </td>
                <td>
                  <select name="items[{{ $key }}][variation_id]" id="variation{{ $key + 1 }}" class="form-control select2-js">
                    <option value="">No Variation</option>
                    @if($item->product)
                      @foreach($item->product->variations as $v)
                        <option value="{{ $v->id }}" {{ $item->variation_id == $v->id ? 'selected' : '' }}>{{ $v->sku }}</option>
                      @endforeach
                    @endif
                  </select>
                </td>
                <td><input type="number" name="items[{{ $key }}][qty]" id="qty{{ $key + 1 }}" class="form-control quantity" value="{{ $item->qty }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td><input type="number" name="items[{{ $key }}][price]" id="price{{ $key + 1 }}" class="form-control" value="{{ $item->price }}" step="any" onchange="rowTotal({{ $key + 1 }})"></td>
                <td><input type="number" id="amount{{ $key + 1 }}" class="form-control" value="{{ $item->qty * $item->price }}" disabled></td>
                <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
              </tr>
              @endforeach
            </tbody>
          </table>
          <button type="button" class="btn btn-outline-primary" onclick="addRow()"><i class="fas fa-plus"></i> Add Item</button>

          <div class="row mt-3">
            <div class="col text-end">
              <h4>Total: <strong class="text-danger">PKR <span id="netTotal">{{ number_format($return->items->sum(fn($i) => $i->qty * $i->price), 2) }}</span></strong></h4>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-primary">Update Return</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  var products = @json($products);
  var index = {{ count($return->items) + 1 }};

  $(document).ready(() => { $('.select2-js').select2({ width: '100%' }); tableTotal(); });

  function updateSerialNumbers() { $('.serial-no').each((i, el) => $(el).text(i + 1)); }

  function removeRow(btn) { $(btn).closest('tr').remove(); updateSerialNumbers(); tableTotal(); }

  function addRow() {
    let rowIndex = index - 1;
    let row = `<tr>
      <td class="serial-no"></td>
      <td><select name="items[${rowIndex}][product_id]" id="item_name${index}" class="form-control select2-js" onchange="onItemChange(this)">
        <option value="">Select Item</option>
        ${products.map(p => `<option value="${p.id}">${p.name}</option>`).join('')}
      </select></td>
      <td><select name="items[${rowIndex}][variation_id]" id="variation${index}" class="form-control select2-js"><option value="">No Variation</option></select></td>
      <td><input type="number" name="items[${rowIndex}][qty]" id="qty${index}" class="form-control quantity" value="0" step="any" onchange="rowTotal(${index})"></td>
      <td><input type="number" name="items[${rowIndex}][price]" id="price${index}" class="form-control" value="0" step="any" onchange="rowTotal(${index})"></td>
      <td><input type="number" id="amount${index}" class="form-control" value="0" disabled></td>
      <td><button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)"><i class="fas fa-times"></i></button></td>
    </tr>`;
    $('#ItemsBody').append(row);
    $(`#item_name${index}, #variation${index}`).select2({ width: '100%' });
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
    const rowIndex = el.id.match(/\d+$/)[0];
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
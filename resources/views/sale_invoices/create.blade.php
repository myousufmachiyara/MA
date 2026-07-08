@extends('layouts.app')

@section('title', 'Create Sale Invoice')

@section('content')
<style>
    .select2-container--default .select2-selection--single {
        height: 38px !important;
        padding: 5px;
        border: 1px solid #ced4da;
    }
    .select2-container {
        display: block !important;
        width: 100% !important;
    }
    #itemTable th { background: #f8f9fa; }
    #itemTable td { vertical-align: middle; }
    .stock-exceeded { border-color: red !important; }
    .stock-ok       { border-color: #28a745 !important; }
</style>

<div class="row">
    <form action="{{ route('sale_invoices.store') }}" onkeydown="return event.key != 'Enter';" method="POST">
        @csrf
        <div class="col-12 mb-2">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Create Sale Invoice</h2>
                    @if ($errors->any())
                        <div class="alert alert-danger mt-2">
                            <ul class="mb-0">
                                @foreach ($errors->all() as $error)
                                    <li>{{ $error }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </header>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-2">
                            <label>Invoice #</label>
                            <input type="text" name="invoice_no" class="form-control" readonly placeholder="Auto"/>
                        </div>
                        <div class="col-md-2">
                            <label>Date</label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required />
                        </div>
                        <div class="col-md-3">
                            <label>Customer Name</label>
                            <select name="account_id" class="form-control select2-js" required>
                                <option value="">Select Customer</option>
                                @foreach($customers as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label>Invoice Type</label>
                            <select name="type" id="invoice_type" class="form-control" required>
                                <option value="cash">Cash</option>
                                <option value="credit">Credit</option>
                            </select>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="card">
                <header class="card-header">
                    <h2 class="card-title">Invoice Items</h2>
                </header>
                <div class="card-body">
                    <table class="table table-bordered" id="itemTable">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th width="15%">Variation</th>
                                <th width="10%">Price</th>
                                <th width="10%">Qty</th>
                                <th width="12%">Total</th>
                                <th width="50px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <select name="items[0][product_id]" id="item_name0"
                                            class="form-control select2-js product-select"
                                            onchange="onItemNameChange(this)" required>
                                        <option value="">Select Product</option>
                                        @foreach($products as $product)
                                            <option value="{{ $product->id }}"
                                                    data-price="{{ $product->selling_price ?? 0 }}"
                                                    data-stock="{{ $product->real_time_stock ?? 0 }}">
                                                {{ $product->name }} (Stock: {{ $product->real_time_stock ?? 0 }})
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <select name="items[0][variation_id]" id="variation0"
                                            class="form-control select2-js variation-select">
                                        <option value="">Select Variation</option>
                                    </select>
                                </td>
                                <td><input type="number" name="items[0][sale_price]" class="form-control sale-price" step="any" required></td>
                                <td>
                                    <input type="number" name="items[0][quantity]" class="form-control quantity" step="any" required>
                                    {{-- FIX: hidden field stores available stock for this row so JS can read it --}}
                                    <small class="text-muted stock-hint"></small>
                                </td>
                                <td><input type="number" name="items[0][total]" class="form-control row-total" readonly></td>
                                <td>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <button type="button" class="btn btn-success btn-sm" onclick="addRow()">+ Add Item</button>

                    <hr>
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label>Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-2">
                            <label><strong>Discount (PKR)</strong></label>
                            <input type="number" name="discount" id="discountInput" class="form-control" step="any" value="0">
                        </div>
                        <div class="col-md-6 text-end">
                            <label><strong>Total Bill</strong></label>
                            <h4 class="text-primary mt-0">PKR <span id="netAmountText">0.00</span></h4>
                            <input type="hidden" name="net_amount" id="netAmountInput">
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label><strong>Receive Payment To:</strong></label>
                            <select name="payment_account_id" class="form-control select2-js">
                                <option value="">No Payment (Credit Sale)</option>
                                @foreach($paymentAccounts as $pAc)
                                    <option value="{{ $pAc->id }}">{{ $pAc->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label>Amount Received</label>
                            <input type="number" name="amount_received" id="amountReceived"
                                   class="form-control" step="any" value="0">
                        </div>
                        <div class="col-md-5 text-end">
                            <label>Remaining Balance</label>
                            <h4 class="text-danger mt-0">PKR <span id="balanceAmountText">0.00</span></h4>
                        </div>
                    </div>
                </div>
                <footer class="card-footer text-end">
                    <a href="{{ route('sale_invoices.index') }}" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Invoice</button>
                </footer>
            </section>
        </div>
    </form>
</div>

<script>
let rowIndex = $('#itemTable tbody tr').length;

$(document).ready(function () {
    $('.select2-js').select2({ width: '100%' });

    // Row total recalc
    $(document).on('input', '.sale-price, .quantity', function () {
        calcRowTotal($(this).closest('tr'));
    });

    // Balance recalc
    $(document).on('input', '#amountReceived, #discountInput', calcTotal);

    // Cash: auto-fill amount received = net total
    $(document).on('change', '#invoice_type', function () {
        if ($(this).val() === 'cash') {
            $('#amountReceived').val($('#netAmountInput').val());
        } else {
            $('#amountReceived').val(0);
        }
        calcTotal();
    });

    // ─────────────────────────────────────────────────────────────
    // FIX: Stock validation
    //
    // Old code looked for strong[id^="stock_display"] which was never
    // rendered — so availableStock was always 0 and every qty > 0
    // turned red.
    //
    // Fix: read data-stock from the SELECTED product option.
    // If a variation is selected, use the variation's own stock
    // from its data-stock attribute; otherwise fall back to the
    // product-level stock.
    // ─────────────────────────────────────────────────────────────
    $(document).on('input', '.quantity', function () {
        validateStock($(this).closest('tr'));
    });
});

// ─────────────────────────────────────────────────────────────────
// getAvailableStock — reads stock for the currently selected
// product option (and variation option if one is chosen)
// ─────────────────────────────────────────────────────────────────
function getAvailableStock($row) {
    const $productSelect   = $row.find('.product-select');
    const $variationSelect = $row.find('.variation-select');

    // Check if a specific variation is selected and has its own stock
    const $selectedVariation = $variationSelect.find('option:selected');
    const variationStock     = parseFloat($selectedVariation.data('stock'));

    if ($selectedVariation.val() && !isNaN(variationStock)) {
        return variationStock;
    }

    // Fall back to product-level stock from the product option
    const $selectedProduct = $productSelect.find('option:selected');
    const productStock     = parseFloat($selectedProduct.data('stock'));
    return isNaN(productStock) ? 0 : productStock;
}

function validateStock($row) {
    const $qtyInput      = $row.find('.quantity');
    const $hint          = $row.find('.stock-hint');
    const qty            = parseFloat($qtyInput.val()) || 0;
    const availableStock = getAvailableStock($row);

    if (qty > availableStock) {
        $qtyInput.addClass('stock-exceeded').removeClass('stock-ok');
        $hint.text('⚠ Only ' + availableStock + ' available').css('color', 'red');
    } else {
        $qtyInput.addClass('stock-ok').removeClass('stock-exceeded');
        $hint.text('In stock: ' + availableStock).css('color', '#28a745');
    }
}

// ─────────────────────────────────────────────────────────────────
// addRow — builds a new table row with Blade-rendered product list
// ─────────────────────────────────────────────────────────────────
function addRow() {
    const idx    = rowIndex++;
    const rowHtml = `
    <tr>
        <td>
            <select name="items[${idx}][product_id]" id="item_name${idx}"
                    class="form-control product-select" onchange="onItemNameChange(this)" required>
                <option value="">Select Product</option>
                @foreach($products as $product)
                    <option value="{{ $product->id }}"
                            data-price="{{ $product->selling_price ?? 0 }}"
                            data-stock="{{ $product->real_time_stock ?? 0 }}">
                        {{ $product->name }} (Stock: {{ $product->real_time_stock ?? 0 }})
                    </option>
                @endforeach
            </select>
        </td>
        <td>
            <select name="items[${idx}][variation_id]" id="variation${idx}"
                    class="form-control variation-select">
                <option value="">Select Variation</option>
            </select>
        </td>
        <td><input type="number" name="items[${idx}][sale_price]" class="form-control sale-price" step="any" required></td>
        <td>
            <input type="number" name="items[${idx}][quantity]" class="form-control quantity" step="any" required>
            <small class="text-muted stock-hint"></small>
        </td>
        <td><input type="number" name="items[${idx}][total]" class="form-control row-total" readonly></td>
        <td>
            <button type="button" class="btn btn-danger btn-sm" onclick="removeRow(this)">
                <i class="fas fa-times"></i>
            </button>
        </td>
    </tr>`;

    $('#itemTable tbody').append(rowHtml);
    $(`#item_name${idx}, #variation${idx}`).select2({ width: '100%' });
}

// ─────────────────────────────────────────────────────────────────
// onItemNameChange — fires when a product is selected
// ─────────────────────────────────────────────────────────────────
function onItemNameChange(selectElement) {
    const $row     = $(selectElement).closest('tr');
    const itemId   = selectElement.value;
    const idMatch  = selectElement.id.match(/\d+$/);
    if (!idMatch) return;
    const rowNum   = idMatch[0];

    // Auto-fill price from data-price attribute
    const $selectedOption = $(selectElement.options[selectElement.selectedIndex]);
    $row.find('.sale-price').val($selectedOption.data('price') || 0);

    // Reset variation dropdown
    const $variationSelect = $(`#variation${rowNum}`);

    if (!itemId) {
        $variationSelect.html('<option value="">Select Variation</option>').trigger('change.select2');
        $row.find('.stock-hint').text('');
        $row.find('.quantity').removeClass('stock-exceeded stock-ok');
        calcRowTotal($row);
        return;
    }

    $variationSelect.html('<option value="">Loading...</option>').trigger('change.select2');

    fetch(`/product/${itemId}/variations`)
        .then(res => res.json())
        .then(data => {
            const variations = data.variation || data.variations || [];

            if (variations.length > 0) {
                let html = '<option value="">Select Variation</option>';
                variations.forEach(v => {
                    // Store per-variation stock in data-stock so validation can read it
                    const vStock = v.stock_quantity ?? v.current_stock ?? 0;
                    const label  = [v.sku, v.name].filter(Boolean).join(' ');
                    html += `<option value="${v.id}" data-stock="${vStock}">${label} (Stock: ${vStock})</option>`;
                });
                $variationSelect.html(html);
            } else {
                $variationSelect.html('<option value="">Standard (No Variations)</option>');
            }
            $variationSelect.trigger('change.select2');

            // Re-run stock validation now that dropdown is populated
            validateStock($row);
        })
        .catch(() => {
            $variationSelect.html('<option value="">Error loading</option>').trigger('change.select2');
        });

    calcRowTotal($row);
}

function removeRow(btn) {
    if ($('#itemTable tbody tr').length > 1) {
        $(btn).closest('tr').remove();
        calcTotal();
    }
}

function calcRowTotal($row) {
    const price = parseFloat($row.find('.sale-price').val()) || 0;
    const qty   = parseFloat($row.find('.quantity').val())   || 0;
    $row.find('.row-total').val((price * qty).toFixed(2));
    validateStock($row);
    calcTotal();
}

function calcTotal() {
    let total = 0;
    $('.row-total').each(function () {
        total += parseFloat($(this).val()) || 0;
    });
    const discount  = parseFloat($('#discountInput').val()) || 0;
    const netAmount = Math.max(0, total - discount);

    $('#netAmountText').text(netAmount.toLocaleString(undefined, { minimumFractionDigits: 2 }));
    $('#netAmountInput').val(netAmount.toFixed(2));

    const received = parseFloat($('#amountReceived').val()) || 0;
    const balance  = netAmount - received;
    $('#balanceAmountText').text(balance.toLocaleString(undefined, { minimumFractionDigits: 2 }));
}
</script>
@endsection
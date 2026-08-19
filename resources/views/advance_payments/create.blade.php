@extends('layouts.app')

@section('title', 'Advance Payments | New')

@section('content')
<div class="row">
  <div class="col">
    <form action="{{ route('advance_payments.store') }}" method="POST" onkeydown="return event.key != 'Enter';">
      @csrf
      <section class="card">
        <header class="card-header"><h2 class="card-title">Record Advance Payment</h2></header>

        <div class="card-body">
          <div class="row">
            <div class="col-md-3 mb-3">
              <label>Party Type <span class="text-danger">*</span></label>
              <select name="party_type" id="party_type" class="form-control" required onchange="togglePartyOptions()">
                <option value="customer">Customer</option>
                <option value="vendor">Vendor</option>
              </select>
            </div>

            <div class="col-md-3 mb-3">
              <label>Party <span class="text-danger">*</span></label>
              <select name="party_id" id="party_customer" class="form-control select2-js">
                <option value="">Select Customer</option>
                @foreach($customers as $c)
                  <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
              </select>
              <select name="party_id" id="party_vendor" class="form-control select2-js" style="display:none;">
                <option value="">Select Vendor</option>
                @foreach($vendors as $v)
                  <option value="{{ $v->id }}">{{ $v->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2 mb-3">
              <label>Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" class="form-control" value="{{ date('Y-m-d') }}" required>
            </div>

            <div class="col-md-2 mb-3">
              <label>Cash / Bank Account <span class="text-danger">*</span></label>
              <select name="cash_bank_account_id" class="form-control select2-js" required>
                <option value="">Select Account</option>
                @foreach($cashBankAccounts as $acc)
                  <option value="{{ $acc->id }}">[{{ $acc->account_code }}] {{ $acc->name }}</option>
                @endforeach
              </select>
            </div>

            <div class="col-md-2 mb-3">
              <label>Amount <span class="text-danger">*</span></label>
              <input type="number" name="amount" class="form-control" step="any" min="0.01" required>
            </div>

            <div class="col-md-12 mb-3">
              <label>Remarks</label>
              <textarea name="remarks" class="form-control" rows="2"></textarea>
            </div>
          </div>
        </div>

        <footer class="card-footer text-end">
          <button type="submit" class="btn btn-success"><i class="fas fa-save"></i> Save Advance</button>
        </footer>
      </section>
    </form>
  </div>
</div>

<script>
  $(document).ready(() => { $('.select2-js').select2({ width: '100%' }); togglePartyOptions(); });

  function togglePartyOptions() {
    const isCustomer = $('#party_type').val() === 'customer';
    $('#party_customer').toggle(isCustomer).prop('disabled', !isCustomer);
    $('#party_vendor').toggle(!isCustomer).prop('disabled', isCustomer);
  }
</script>
@endsection
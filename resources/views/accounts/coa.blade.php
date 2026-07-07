@extends('layouts.app')

@section('title', 'Accounts | All COA')

@section('content')

    {{-- ── Shared account type list — single source of truth ────────── --}}
    @php
        $accountTypes = [
            'customer'   => 'Customer',
            'vendor'     => 'Vendor',
            'cash'       => 'Cash',
            'bank'       => 'Bank',
            'inventory'  => 'Inventory / Stock',
            'liability'  => 'Liability',
            'equity'     => 'Equity',
            'revenue'    => 'Revenue',
            'cogs'       => 'Cost of Goods Sold',
            'expenses'   => 'Expenses',
            'receivable' => 'Receivable (Loan Given)',
            'payable'    => 'Payable (Loan Taken)',
            // 'delivery_clearing' intentionally excluded — system-managed only
        ];

        $partyTypes = ['customer', 'vendor'];
    @endphp

    <div class="row">
        <div class="col">
            <section class="card">

                @if(session('success'))
                    <div class="alert alert-success">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="alert alert-danger">{{ session('error') }}</div>
                @endif

                <header class="card-header">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <h2 class="card-title">All Accounts</h2>
                        @can('coa.create')
                            <button type="button" class="modal-with-form btn btn-primary" href="#addModal">
                                <i class="fas fa-plus"></i> Add Account
                            </button>
                        @endcan
                    </div>
                    @if ($errors->has('error'))
                        <strong class="text-danger">{{ $errors->first('error') }}</strong>
                    @endif
                </header>

                <div class="card-body">

                    {{-- ── Filters ───────────────────────────────────── --}}
                    <form method="GET" action="{{ route('coa.index') }}" class="mb-3">
                        <div class="row">
                            <div class="col-md-3">
                                <label>Filter by Sub-head</label>
                                <select name="subhead" class="form-control" onchange="this.form.submit()">
                                    <option value="all" {{ request('subhead') == 'all' || !request('subhead') ? 'selected' : '' }}>
                                        All
                                    </option>
                                    @foreach($subHeadOfAccounts as $sub)
                                        <option value="{{ $sub->id }}"
                                            {{ request('subhead') == $sub->id ? 'selected' : '' }}>
                                            {{ $sub->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label>Filter by Type</label>
                                <select name="account_type" class="form-control" onchange="this.form.submit()">
                                    <option value="all" {{ request('account_type') == 'all' || !request('account_type') ? 'selected' : '' }}>
                                        All
                                    </option>
                                    @foreach($accountTypes as $value => $label)
                                        <option value="{{ $value }}" {{ request('account_type') == $value ? 'selected' : '' }}>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </form>

                    {{-- ── Table ───────────────────────────────────── --}}
                    <div class="modal-wrapper table-scroll">
                        <table class="table table-bordered table-striped mb-0" id="datatable-default">
                            <thead>
                                <tr>
                                    <th>S.No</th>
                                    <th>Code</th>
                                    <th>Account Name</th>
                                    <th>Sub-head</th>
                                    <th>Type</th>
                                    <th>Tax Profile</th>
                                    <th>Phone</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($chartOfAccounts as $item)
                                <tr>
                                    <td>{{ $loop->iteration }}</td>
                                    <td><code>{{ $item->account_code }}</code></td>
                                    <td><strong>{{ $item->name }}</strong></td>
                                    <td>{{ $item->subHeadOfAccount->name ?? '—' }}</td>
                                    <td><strong>{{ $accountTypes[$item->account_type] ?? ucfirst(str_replace('_',' ',$item->account_type ?? '—')) }}</strong></td>
                                    <td>
                                        @if(in_array($item->account_type, $partyTypes))
                                            @if($item->customer_type)
                                                <span class="badge bg-info text-dark">{{ ucfirst($item->customer_type) }}</span>
                                            @endif
                                            @if($item->filer_status)
                                                <span class="badge bg-secondary">{{ $item->filer_status === 'filer' ? 'Filer' : 'Non-Filer' }}</span>
                                            @endif
                                            @if($item->is_gst_registered)
                                                <span class="badge bg-success">GST</span>
                                            @endif
                                            @if($item->wht_applicable)
                                                <span class="badge bg-warning text-dark">WHT {{ $item->wht_rate }}%</span>
                                            @endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $item->contact_no ?? '—' }}</td>
                                    <td>{{ \Carbon\Carbon::parse($item->opening_date)->format('d-m-Y') }}</td>
                                    <td>
                                        @if($item->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-danger">Inactive</span>
                                        @endif
                                    </td>
                                    <td>
                                        @can('coa.edit')
                                            @if(!$item->is_system_account)
                                                <a href="#" class="text-primary me-1" title="Edit"
                                                onclick="editAccount({{ $item->id }})">
                                                    <i class="fa fa-edit"></i>
                                                </a>
                                                <form action="{{ route('coa.toggleActive', $item->id) }}"
                                                    method="POST" style="display:inline;">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="btn btn-link p-0 m-0 me-1"
                                                        title="{{ $item->is_active ? 'Deactivate' : 'Activate' }}">
                                                        <i class="fa {{ $item->is_active ? 'fa-toggle-on text-success' : 'fa-toggle-off text-muted' }}"></i>
                                                    </button>
                                                </form>
                                            @else
                                                <span class="text-muted" title="System-managed account">
                                                    <i class="fa fa-lock"></i>
                                                </span>
                                            @endif
                                        @endcan
                                        @can('coa.delete')
                                            @if(!$item->is_system_account)
                                                <form action="{{ route('coa.destroy', $item->id) }}"
                                                    method="POST" style="display:inline;"
                                                    onsubmit="return confirm('Delete this account? This cannot be undone.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn btn-link p-0 text-danger">
                                                        <i class="fa fa-trash-alt"></i>
                                                    </button>
                                                </form>
                                            @endif
                                        @endcan
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            {{-- ================================================================ --}}
            {{-- ADD MODAL                                                         --}}
            {{-- ================================================================ --}}
            @can('coa.create')
            <div id="addModal" class="modal-block modal-block-primary mfp-hide">
                <section class="card">
                    <form method="POST" id="addForm" action="{{ route('coa.store') }}"
                        enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
                        @csrf
                        <header class="card-header">
                            <h2 class="card-title">Add New Account</h2>
                        </header>
                        <div class="card-body">
                            <div class="row form-group">

                                <div class="col-lg-6 mb-2">
                                    <label>Account Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" placeholder="Account Name"
                                        name="name" required>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Account Type</label>
                                    <select data-plugin-selecttwo class="form-control select2-js account-type-select" name="account_type">
                                        <option value="" disabled selected>Select Account Type</option>
                                        @foreach($accountTypes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Sub-head of Account <span class="text-danger">*</span></label>
                                    <select data-plugin-selecttwo class="form-control select2-js" name="shoa_id" required>
                                        <option value="" disabled selected>Select Sub-head</option>
                                        @foreach($subHeadOfAccounts as $row)
                                            <option value="{{ $row->id }}">{{ $row->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Receivables</label>
                                    <input type="number" class="form-control" name="receivables"
                                        value="0" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Payables</label>
                                    <input type="number" class="form-control" name="payables"
                                        value="0" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Credit Limit</label>
                                    <input type="number" class="form-control" name="credit_limit"
                                        value="0" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Date <span class="text-danger">*</span></label>
                                    <input type="date" class="form-control" name="opening_date"
                                        value="{{ date('Y-m-d') }}" required>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Remarks</label>
                                    <input type="text" class="form-control" placeholder="Remarks" name="remarks">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Address</label>
                                    <textarea class="form-control" rows="2" placeholder="Address"
                                            name="address"></textarea>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Phone No.</label>
                                    <input type="text" class="form-control" placeholder="Phone No."
                                        name="contact_no">
                                </div>

                                {{-- ── Tax Profile — shown only for Customer / Vendor ── --}}
                                <div class="col-lg-12 party-only mt-2" style="display:none;">
                                    <hr>
                                    <strong>Tax Profile</strong>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>Customer Type</label>
                                    <select data-plugin-selecttwo class="form-control select2-js" name="customer_type">
                                        <option value="">Not Applicable</option>
                                        <option value="retailer">Retailer</option>
                                        <option value="wholesaler">Wholesaler</option>
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>Filer Status</label>
                                    <select data-plugin-selecttwo class="form-control select2-js" name="filer_status">
                                        <option value="">Unknown</option>
                                        <option value="filer">Filer</option>
                                        <option value="non_filer">Non-Filer</option>
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>GST Registered?</label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input gst-toggle" name="is_gst_registered" value="1">
                                        <label class="form-check-label">Registered for GST</label>
                                    </div>
                                </div>

                                <div class="col-lg-6 mb-2 party-only gst-number-field" style="display:none;">
                                    <label>GST Number</label>
                                    <input type="text" class="form-control" name="gst_number" placeholder="e.g. 03-XX-XXXXXXX-X">
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>WHT Applicable?</label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" class="form-check-input wht-toggle" name="wht_applicable" value="1">
                                        <label class="form-check-label">Withhold tax on this account's invoices</label>
                                    </div>
                                </div>

                                <div class="col-lg-6 mb-2 party-only wht-rate-field" style="display:none;">
                                    <label>WHT Rate (%)</label>
                                    <input type="number" class="form-control" name="wht_rate" step="0.01" min="0" max="100"
                                        placeholder="Leave blank to auto-suggest">
                                </div>

                            </div>
                        </div>
                        <footer class="card-footer">
                            <div class="col-md-12 text-end">
                                <button type="submit" class="btn btn-primary">Add Account</button>
                                <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
                            </div>
                        </footer>
                    </form>
                </section>
            </div>
            @endcan

            {{-- ================================================================ --}}
            {{-- EDIT MODAL                                                        --}}
            {{-- ================================================================ --}}
            @can('coa.edit')
            <div id="editModal" class="modal-block modal-block-primary mfp-hide">
                <section class="card">
                    <form method="POST" id="editForm" action=""
                        enctype="multipart/form-data" onkeydown="return event.key != 'Enter';">
                        @csrf
                        @method('PUT')
                        <header class="card-header">
                            <h2 class="card-title">Edit Account</h2>
                        </header>
                        <div class="card-body">
                            <div class="row form-group">

                                <div class="col-lg-6 mb-2">
                                    <label>Account Name <span class="text-danger">*</span></label>
                                    <input type="text" id="edit_name" class="form-control"
                                        placeholder="Account Name" name="name" required>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Account Type</label>
                                    <select data-plugin-selecttwo id="edit_account_type" class="form-control select2-js account-type-select"
                                            name="account_type">
                                        <option value="" disabled>Select Account Type</option>
                                        @foreach($accountTypes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Sub-head of Account <span class="text-danger">*</span></label>
                                    <select id="edit_shoa_id" class="form-control select2-js"
                                            name="shoa_id" required>
                                        <option value="" disabled>Select Sub-head</option>
                                        @foreach($subHeadOfAccounts as $row)
                                            <option value="{{ $row->id }}">{{ $row->name }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Receivables</label>
                                    <input type="number" id="edit_receivables" class="form-control"
                                        name="receivables" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Payables</label>
                                    <input type="number" id="edit_payables" class="form-control"
                                        name="payables" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Credit Limit</label>
                                    <input type="number" id="edit_credit_limit" class="form-control"
                                        name="credit_limit" step="any">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Date <span class="text-danger">*</span></label>
                                    <input type="date" id="edit_opening_date" class="form-control"
                                        name="opening_date" required>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Remarks</label>
                                    <input type="text" id="edit_remarks" class="form-control"
                                        placeholder="Remarks" name="remarks">
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Address</label>
                                    <textarea id="edit_address" class="form-control" rows="2"
                                            placeholder="Address" name="address"></textarea>
                                </div>

                                <div class="col-lg-6 mb-2">
                                    <label>Phone No.</label>
                                    <input type="text" id="edit_contact_no" class="form-control"
                                        placeholder="Phone No." name="contact_no">
                                </div>

                                {{-- ── Tax Profile — shown only for Customer / Vendor ── --}}
                                <div class="col-lg-12 party-only mt-2" style="display:none;">
                                    <hr>
                                    <strong>Tax Profile</strong>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>Customer Type</label>
                                    <select data-plugin-selecttwo id="edit_customer_type" class="form-control select2-js" name="customer_type">
                                        <option value="">Not Applicable</option>
                                        <option value="retailer">Retailer</option>
                                        <option value="wholesaler">Wholesaler</option>
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>Filer Status</label>
                                    <select data-plugin-selecttwo id="edit_filer_status" class="form-control select2-js" name="filer_status">
                                        <option value="">Unknown</option>
                                        <option value="filer">Filer</option>
                                        <option value="non_filer">Non-Filer</option>
                                    </select>
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>GST Registered?</label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" id="edit_is_gst_registered" class="form-check-input gst-toggle" name="is_gst_registered" value="1">
                                        <label class="form-check-label">Registered for GST</label>
                                    </div>
                                </div>

                                <div class="col-lg-6 mb-2 party-only gst-number-field" style="display:none;">
                                    <label>GST Number</label>
                                    <input type="text" id="edit_gst_number" class="form-control" name="gst_number" placeholder="e.g. 03-XX-XXXXXXX-X">
                                </div>

                                <div class="col-lg-6 mb-2 party-only" style="display:none;">
                                    <label>WHT Applicable?</label>
                                    <div class="form-check form-switch">
                                        <input type="checkbox" id="edit_wht_applicable" class="form-check-input wht-toggle" name="wht_applicable" value="1">
                                        <label class="form-check-label">Withhold tax on this account's invoices</label>
                                    </div>
                                </div>

                                <div class="col-lg-6 mb-2 party-only wht-rate-field" style="display:none;">
                                    <label>WHT Rate (%)</label>
                                    <input type="number" id="edit_wht_rate" class="form-control" name="wht_rate" step="0.01" min="0" max="100"
                                        placeholder="Leave blank to auto-suggest">
                                </div>

                            </div>
                        </div>
                        <footer class="card-footer">
                            <div class="col-md-12 text-end">
                                <button type="submit" class="btn btn-primary">Update Account</button>
                                <button type="button" class="btn btn-default modal-dismiss">Cancel</button>
                            </div>
                        </footer>
                    </form>
                </section>
            </div>
            @endcan

        </div>
    </div>

    <script>
    // ── Show/hide tax-profile fields based on account type ─────────
    const PARTY_TYPES = ['customer', 'vendor'];

    function togglePartyFields(scopeSelector) {
        const $scope = $(scopeSelector);
        const type = $scope.find('.account-type-select').val();
        const isParty = PARTY_TYPES.includes(type);
        $scope.find('.party-only').toggle(isParty);
    }

    $(document).on('change', '#addForm .account-type-select', function () {
        togglePartyFields('#addForm');
    });
    $(document).on('change', '#editForm .account-type-select', function () {
        togglePartyFields('#editForm');
    });

    $(document).on('change', '#addForm .gst-toggle', function () {
        $('#addForm .gst-number-field').toggle(this.checked);
    });
    $(document).on('change', '#addForm .wht-toggle', function () {
        $('#addForm .wht-rate-field').toggle(this.checked);
    });
    $(document).on('change', '#editForm .gst-toggle', function () {
        $('#editForm .gst-number-field').toggle(this.checked);
    });
    $(document).on('change', '#editForm .wht-toggle', function () {
        $('#editForm .wht-rate-field').toggle(this.checked);
    });

    function editAccount(id) {
        fetch('/coa/' + id + '/edit')
            .then(res => res.json())
            .then(data => {

                // Set form action
                $('#editForm').attr('action', '/coa/' + id);

                $('#edit_name').val(data.name);
                $('#edit_receivables').val(data.receivables);
                $('#edit_payables').val(data.payables);
                $('#edit_credit_limit').val(data.credit_limit);
                $('#edit_opening_date').val(data.opening_date);
                $('#edit_remarks').val(data.remarks);
                $('#edit_address').val(data.address);
                $('#edit_contact_no').val(data.contact_no);

                // Tax profile fields
                $('#edit_customer_type').val(data.customer_type).trigger('change');
                $('#edit_filer_status').val(data.filer_status).trigger('change');
                $('#edit_gst_number').val(data.gst_number);
                $('#edit_wht_rate').val(data.wht_rate);

                $('#edit_is_gst_registered').prop('checked', !!data.is_gst_registered);
                $('#edit_wht_applicable').prop('checked', !!data.wht_applicable);

                // trigger('change') updates Select2 visual state
                $('#edit_account_type').val(data.account_type).trigger('change');
                $('#edit_shoa_id').val(data.shoa_id).trigger('change');

                // Show/hide dependent sections based on loaded data
                togglePartyFields('#editForm');
                $('#editForm .gst-number-field').toggle(!!data.is_gst_registered);
                $('#editForm .wht-rate-field').toggle(!!data.wht_applicable);

                $.magnificPopup.open({
                    items: { src: '#editModal' },
                    type: 'inline'
                });
            })
            .catch(err => {
                console.error('Failed to load account:', err);
                alert('Could not load account data. Please try again.');
            });
    }
    </script>

@endsection
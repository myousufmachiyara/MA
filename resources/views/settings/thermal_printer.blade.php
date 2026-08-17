@extends('layouts.app')

@section('title', 'Settings | Thermal Printer')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">Thermal Printer Setup</h2></header>
      <div class="card-body">
        <p class="text-muted">QZ Tray must be installed and running on this PC. <a href="https://qz.io/download/" target="_blank">Download QZ Tray</a> if not already installed.</p>

        <button type="button" class="btn btn-primary mb-3" onclick="detectPrinters()">
          <i class="fas fa-search"></i> Detect Printers
        </button>

        <div id="printerListWrap" style="display:none;">
          <label>Select Default Thermal Printer</label>
          <select id="printerSelect" class="form-control mb-2"></select>
          <button type="button" class="btn btn-success" onclick="saveSelectedPrinter()">Save as Default</button>
        </div>

        <div id="currentPrinter" class="alert alert-info mt-3"></div>
      </div>
    </section>
  </div>
</div>

<script>
  document.addEventListener('DOMContentLoaded', () => {
    const current = getDefaultThermalPrinter();
    document.getElementById('currentPrinter').textContent = current
      ? 'Current default printer: ' + current
      : 'No default printer selected yet.';
  });

  async function detectPrinters() {
    try {
      const printers = await listThermalPrinters();
      if (printers.length === 0) {
        alert('No printers found. Make sure the printer is connected/paired and QZ Tray is running.');
        return;
      }
      const select = document.getElementById('printerSelect');
      select.innerHTML = printers.map(p => `<option value="${p}">${p}</option>`).join('');
      document.getElementById('printerListWrap').style.display = 'block';
    } catch (err) {
      alert('Could not detect printers: ' + err);
    }
  }

  function saveSelectedPrinter() {
    const name = document.getElementById('printerSelect').value;
    setDefaultThermalPrinter(name);
    document.getElementById('currentPrinter').textContent = 'Current default printer: ' + name;
    alert('Default printer saved.');
  }
</script>
@endsection
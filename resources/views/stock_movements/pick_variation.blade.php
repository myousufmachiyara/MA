@extends('layouts.app')

@section('title', 'Select Variation')

@section('content')
<div class="row">
  <div class="col">
    <section class="card">
      <header class="card-header"><h2 class="card-title">{{ $product->name }} — select variation</h2></header>
      <div class="card-body">
        <table class="table table-bordered">
          <thead><tr><th>Variation</th><th>Current Stock</th><th></th></tr></thead>
          <tbody>
            @foreach($product->variations as $v)
            <tr>
              <td>{{ $v->sku }}</td>
              <td>{{ number_format($v->stock_quantity, 2) }}</td>
              <td><a href="{{ route('stock_movements.show', ['itemId' => $product->id, 'variation_id' => $v->id]) }}" class="btn btn-sm btn-outline-primary">View</a></td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </section>
  </div>
</div>
@endsection
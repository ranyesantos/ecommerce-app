@extends('layouts.app')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Editar produto</h1>

    @include('catalog.products._form', [
        'product' => $product,
        'action' => route('products.update', $product),
        'method' => 'PUT',
        'submitLabel' => 'Salvar alterações',
    ])
@endsection

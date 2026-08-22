@extends('layouts.app')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Novo produto</h1>

    @include('catalog.products._form', [
        'product' => null,
        'action' => route('products.store'),
        'submitLabel' => 'Criar produto',
    ])
@endsection

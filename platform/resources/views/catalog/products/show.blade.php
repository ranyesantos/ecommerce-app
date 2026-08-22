@use('Illuminate\Support\Number')

@extends('layouts.app')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">{{ $product->name }}</h1>
        <a href="{{ route('products.edit', $product) }}" class="underline">Editar</a>
    </div>

    <dl class="space-y-4 rounded bg-white p-6 shadow">
        <div><dt class="font-medium">SKU</dt><dd>{{ $product->sku }}</dd></div>
        <div><dt class="font-medium">Descrição</dt><dd>{{ $product->description }}</dd></div>
        <div><dt class="font-medium">Preço</dt><dd>{{ Number::currency($product->price_cents / 100, in: 'BRL', locale: 'pt_BR') }}</dd></div>
        <div><dt class="font-medium">Status</dt><dd>{{ $product->is_active ? 'Ativo' : 'Inativo' }}</dd></div>
    </dl>

    <div class="mt-6 flex gap-3">
        @if ($product->is_active)
            <form method="POST" action="{{ route('products.deactivate', $product) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="rounded border px-4 py-2">Desativar</button>
            </form>
        @else
            <form method="POST" action="{{ route('products.activate', $product) }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="rounded border px-4 py-2">Ativar</button>
            </form>
        @endif

        <form method="POST" action="{{ route('products.destroy', $product) }}">
            @csrf
            @method('DELETE')
            <button type="submit" class="rounded bg-red-700 px-4 py-2 text-white">Excluir</button>
        </form>
    </div>
@endsection

@use('Illuminate\Support\Number')

@extends('layouts.app')

@section('content')
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-semibold">Produtos</h1>
        <a href="{{ route('products.create') }}" class="rounded bg-gray-900 px-4 py-2 text-white">Novo produto</a>
    </div>

    <div class="overflow-x-auto rounded bg-white shadow">
        <table class="min-w-full text-left">
            <thead class="border-b bg-gray-50">
                <tr>
                    <th class="px-4 py-3">SKU</th>
                    <th class="px-4 py-3">Nome</th>
                    <th class="px-4 py-3">Preço</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr class="border-b last:border-0">
                        <td class="px-4 py-3">{{ $product->sku }}</td>
                        <td class="px-4 py-3">{{ $product->name }}</td>
                        <td class="px-4 py-3">{{ Number::currency($product->price_cents / 100, in: 'BRL', locale: 'pt_BR') }}</td>
                        <td class="px-4 py-3">{{ $product->is_active ? 'Ativo' : 'Inativo' }}</td>
                        <td class="px-4 py-3">
                            <a href="{{ route('products.show', $product) }}" class="mr-3 underline">Ver</a>
                            <a href="{{ route('products.edit', $product) }}" class="underline">Editar</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center">Nenhum produto encontrado.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $products->links() }}</div>
@endsection

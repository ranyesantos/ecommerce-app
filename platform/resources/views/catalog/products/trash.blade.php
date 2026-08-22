@extends('layouts.app')

@section('content')
    <h1 class="mb-6 text-2xl font-semibold">Lixeira</h1>

    <div class="overflow-x-auto rounded bg-white shadow">
        <table class="min-w-full text-left">
            <thead class="border-b bg-gray-50">
                <tr>
                    <th class="px-4 py-3">SKU</th>
                    <th class="px-4 py-3">Nome</th>
                    <th class="px-4 py-3">Excluído em</th>
                    <th class="px-4 py-3"><span class="sr-only">Ações</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($products as $product)
                    <tr class="border-b last:border-0">
                        <td class="px-4 py-3">{{ $product->sku }}</td>
                        <td class="px-4 py-3">{{ $product->name }}</td>
                        <td class="px-4 py-3">{{ $product->deleted_at }}</td>
                        <td class="px-4 py-3">
                            <form method="POST" action="{{ route('products.trash.restore', $product) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="underline">Restaurar</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="px-4 py-6 text-center">A lixeira está vazia.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-6">{{ $products->links() }}</div>
@endsection

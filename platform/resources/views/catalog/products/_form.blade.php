<form method="POST" action="{{ $action }}" class="space-y-6">
    @csrf
    @if (($method ?? 'POST') !== 'POST')
        @method($method)
    @endif

    <div>
        <label for="sku" class="block font-medium">SKU</label>
        <input id="sku" name="sku" maxlength="32" required value="{{ old('sku', $product?->sku) }}" class="mt-1 block w-full rounded border px-3 py-2">
        @error('sku')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="name" class="block font-medium">Nome</label>
        <input id="name" name="name" maxlength="255" required value="{{ old('name', $product?->name) }}" class="mt-1 block w-full rounded border px-3 py-2">
        @error('name')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="description" class="block font-medium">Descrição</label>
        <textarea id="description" name="description" class="mt-1 block w-full rounded border px-3 py-2">{{ old('description', $product?->description) }}</textarea>
        @error('description')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <div>
        <label for="price_cents" class="block font-medium">Preço (centavos)</label>
        <input id="price_cents" name="price_cents" type="number" min="1" step="1" required value="{{ old('price_cents', $product?->price_cents) }}" class="mt-1 block w-full rounded border px-3 py-2">
        @error('price_cents')
            <p class="mt-1 text-sm text-red-700">{{ $message }}</p>
        @enderror
    </div>

    <button type="submit" class="rounded bg-gray-900 px-4 py-2 text-white">{{ $submitLabel }}</button>
</form>

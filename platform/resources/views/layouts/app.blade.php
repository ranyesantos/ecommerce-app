<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Catálogo' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <header class="border-b bg-white">
        <nav class="mx-auto flex max-w-6xl gap-6 px-6 py-4" aria-label="Navegação principal">
            <a href="{{ route('products.index') }}" class="font-semibold">Produtos</a>
            <a href="{{ route('products.create') }}">Novo produto</a>
            <a href="{{ route('products.trash.index') }}">Lixeira</a>
        </nav>
    </header>

    @if (session('status'))
        <div class="mx-auto mt-6 max-w-6xl px-6" role="status">
            <p class="rounded border border-green-200 bg-green-50 px-4 py-3 text-green-800">{{ session('status') }}</p>
        </div>
    @endif

    <main class="mx-auto max-w-6xl px-6 py-8">
        @yield('content')
    </main>
</body>
</html>

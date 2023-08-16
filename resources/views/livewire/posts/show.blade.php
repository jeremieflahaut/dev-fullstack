<div class="container">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="{{ route('home') }}">Accueil</a></li>
            <li class="breadcrumb-item"><a href="{{ route('posts.index') }}">Articles</a></li>
            <li class="breadcrumb-item active" aria-current="page">{{ $post->title }}</li>
        </ol>
    </nav>

    <h1>{{ $post->title }}</h1>
    <div class="text-muted">Mise à jour le : {{ $post->updated_at->format('d/m/Y') }}</div>
    <x-posts.categories :categories="$post->categories" />
    <img src="{{ asset($post->image_path) }}" alt="{{ $post->title }}" width="720">
    <p>{!! Illuminate\Support\Str::markdown($post->content) !!}</p>
</div>

<div class="container">
    <h1>{{ $post->title }}</h1>
    <div class="text-muted">Mise à jour le : {{ $post->updated_at->format('d/m/Y') }}</div>
    <ul>
        @foreach ($post->categories as $category)
            <span class="badge bg-light text-dark">{{ $category->name }}</span>
        @endforeach
    </ul>
    <img src="{{ asset($post->image_path) }}" alt="Article Image">
    <p>{!! Illuminate\Support\Str::markdown($post->content) !!}</p>
</div>

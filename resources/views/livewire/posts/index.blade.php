<div class="container">
    <div class="row">
        @foreach ($posts as $post)
            <div class="col-md-4 mb-4">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <img src="{{ asset($post->image_path) }}" class="card-img-top" alt="{{ $post->title }}">
                        <h5 class="card-title">{{ $post->title }}</h5>
                        <p class="text-muted">Mise à jour le : {{ $post->updated_at->format('d/m/Y') }}</p>
                        <p class="card-text">{{ $post->limitedContent() }}</p>
                        <a href="{{ route('posts.show', $post->slug) }}"
                           class="btn btn-outline-danger mt-auto w-50 mx-auto">Lire la suite</a>
                        <x-posts.categories :categories="$post->categories" />
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @if($perPage < $totals)
        <div class="row">
            <button wire:click="load" class="btn btn-outline-danger mt-auto w-25 mx-auto">Charger plus d'articles</button>
        </div>
    @endif
</div>





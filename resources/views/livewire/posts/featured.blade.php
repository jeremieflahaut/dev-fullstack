<div class="container my-5">
    <div class="row p-4 pb-0 pe-lg-0 pt-lg-5 align-items-center rounded-3 border shadow-lg">
        <div class="col-lg-7 p-3 p-lg-5 pt-lg-3">
            <h1 class="display-4 fw-bold lh-1">{{ $post->title }}</h1>
            <p class="text-muted">Mise à jour le : {{ $post->updated_at->format('d/m/Y') }}</p>
            <p class="lead">{{ $post->limitedContent() }}</p>
            <div class="d-grid gap-2 d-md-flex justify-content-md-start mb-4 mb-lg-3">
                <a href="{{ route('posts.show', $post->slug) }}" type="button" class="btn btn-outline-danger btn-lg px-4 me-md-2 fw-bold">Lire la suite</a>
            </div>
        </div>
        <div class="col-lg-4 offset-lg-1 p-0 overflow-hidden shadow-lg">
            <img class="rounded-lg-3" src="{{ asset($post->image_path) }}" alt="" width="720">
        </div>
    </div>
</div>

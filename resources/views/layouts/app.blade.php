<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Blog</title>
    <title>Votre Blog</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    @livewireStyles
</head>
<body>
<div class="container">
    <header
        class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-between py-3 mb-4 border-bottom">
        <a href="{{ route('home') }}"
           class="d-flex align-items-center col-md-3 mb-2 mb-md-0 text-dark text-decoration-none">
            <img src="{{ asset('images/logo.png') }}" alt="Logo" width="120" height="60">
        </a>

        <nav>
            <ul class="nav col-12 col-md-auto mb-2 justify-content-center mb-md-0">
                <li><a href="{{ route('home') }}"
                       class="nav-link px-2 {{ Route::currentRouteName() == 'home' ? 'link-secondary' : 'link-dark' }}">Accueil</a>
                </li>
                <li><a href="{{ route('posts.index') }}"
                       class="nav-link px-2 {{ Route::currentRouteName() == 'posts.index' ? 'link-secondary' : 'link-dark' }}">Blog</a>
                </li>
            </ul>
        </nav>

        <div class="col-md-3 text-end">
            <button type="button" class="btn btn-outline-danger me-2">Login</button>
            {{--<button type="button" class="btn btn-primary">Sign-up</button>--}}
        </div>
    </header>
</div>


@yield('content')

<div class="container" wire:loading>
    <p>Chargement en cours...</p>
</div>

<div class="container">
    <footer class="row row-cols-5 py-5 my-5 border-top">
        <div class="col">
            <a href="{{ route('home') }}" class="d-flex align-items-center mb-3 link-dark text-decoration-none">
                <img src="{{ asset('images/logo.png') }}" alt="Logo" width="120" height="60">
            </a>
            <p class="text-muted">dev-fullstack© 2023</p>
        </div>

        <div class="col"></div>
        <div class="col"></div>
        <div class="col"></div>

        <div class="col">
            <h5>Section</h5>
            <ul class="nav flex-column">
                <li class="nav-item mb-2"><a href="{{ route('home') }}" class="nav-link p-0 text-muted">Accueil</a></li>
                <li class="nav-item mb-2"><a href="{{ route('posts.index') }}" class="nav-link p-0 text-muted">Blog</a>
                </li>
            </ul>
        </div>
    </footer>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4"
        crossorigin="anonymous">

</script>
@livewireScripts

</body>
</html>

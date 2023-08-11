<footer class="row row-cols-5 py-5 my-5 border-top">
    <div class="col">
        <a href="{{ route('home') }}" class="d-flex align-items-center mb-3 link-dark text-decoration-none">
            <img src="{{ asset('images/logo.jpg') }}" alt="Logo" width="120">
        </a>
        <p class="text-muted">© dev-fullstack 2023</p>
    </div>

    <div class="col"></div>
    <div class="col"></div>
    <div class="col"></div>

    <div class="col">
        <h5>Navigation</h5>
        <ul class="nav flex-column">
            @foreach($nav as $item)
                <li class="nav-item mb-2">
                    <a href="{{ route($item['route']) }}"
                                             class="nav-link p-0 text-muted">{{ $item['text'] }}</a>
                </li>
            @endforeach
            @auth
                @foreach($auth as $item)
                    <li class="nav-item mb-2">
                        <a href="{{ route($item['route']) }}"
                                                 class="nav-link p-0 text-muted">{{ $item['text'] }}</a>
                    </li>
                @endforeach
            @endauth
        </ul>
    </div>
</footer>

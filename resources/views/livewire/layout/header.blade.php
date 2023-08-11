<header
    class="d-flex flex-wrap align-items-center justify-content-center justify-content-md-between py-3 mb-4 border-bottom">
    <div class="col-md-3 mb-2 mb-md-0"></div>
    <nav>
        <ul class="nav col-12 col-md-auto mb-2 justify-content-center mb-md-0">
            @foreach($nav as $item)
                <li>
                    <a href="{{ route($item['route']) }}"
                       class="nav-link px-2 {{ Route::currentRouteName() == $item['route'] ? 'link-secondary' : 'link-dark' }}">{{$item['text']}}</a>
                </li>

            @endforeach

            @auth
                @foreach($auth as $item)
                    <li>
                        <a href="{{ route($item['route']) }}"
                           class="nav-link px-2 {{ Route::currentRouteName() == $item['route'] ? 'link-secondary' : 'link-dark' }}">{{$item['text']}}</a>
                    </li>

                @endforeach
            @endauth
        </ul>
    </nav>

    <div class="col-md-3 text-end">
        @guest
            <a href="{{ route('login') }}" type="button" class="btn btn-outline-danger me-2">Login</a>
        @endguest
        @auth
            {{--<p>Connecté en tant que {{ auth()->user()->name }}</p>--}}
            <livewire:auth.logout-confirmation-modal/>
        @endauth
    </div>
</header>

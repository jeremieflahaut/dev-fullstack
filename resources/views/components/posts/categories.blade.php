@props(['categories'])

<ul>
    @foreach ($categories as $category)
        <li class="list-inline-item">
            <a href="#" class="badge bg-light text-dark text-decoration-none" title="Voir les articles dans la catégorie {{ $category->name }}">{{ $category->name }}</a>
        </li>
    @endforeach
</ul>

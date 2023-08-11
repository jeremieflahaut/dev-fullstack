@extends('layouts.app')

@section('content')
    <div class="container">
        <h1>Blog du Dev FullStack</h1>

        <livewire:posts.featured />
        <livewire:posts.index />
    </div>
@endsection

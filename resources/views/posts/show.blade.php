@extends('layouts.app')

@section('content')
    <div class="container">
        <livewire:posts.show :post="$post" />
    </div>
@endsection

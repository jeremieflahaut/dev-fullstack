@extends('layouts.app')

@section('title', $post->title)
@section('meta_description', $post->description)

@section('content')
    <div class="container">
        <livewire:posts.show :post="$post" />
    </div>
@endsection

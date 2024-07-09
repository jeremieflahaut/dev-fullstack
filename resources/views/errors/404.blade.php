@extends('layouts.app')

@section('title', 'Page Not Found')
@section('meta_description', '404')

@section('content')
    <div class="container text-center">
        <h1 class="display-4">404 - Page Not Found</h1>
        <p class="lead">Sorry, the page you are looking for could not be found.</p>
        <a href="{{ url('/') }}" class="btn btn-primary">Go to Homepage</a>
    </div>
@endsection

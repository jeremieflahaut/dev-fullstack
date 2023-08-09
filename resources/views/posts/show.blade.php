@extends('layouts.app')

@section('content')
    <div class="container">
        <livewire:post-show :post="$post" />
    </div>
@endsection

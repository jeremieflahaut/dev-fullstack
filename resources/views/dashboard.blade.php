@extends('layouts.app')

@section('title', 'Dashboard - Dev Fullstack')
@section('meta_description', 'Dashboard')

@section('content')
    <div class="container">
        <div class="row justify-content-center">
            <div class="col">
                @auth
                    <div class="card">
                        <div class="card-header">Chat GPT</div>
                        <div class="card-body">
                            <livewire:dashboard.chat-gpt />
                        </div>
                    </div>
                @endauth
            </div>
        </div>
    </div>
@endsection

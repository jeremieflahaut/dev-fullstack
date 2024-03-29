@extends('layouts.app')

@section('title', 'Blog du Dev Fullstack : Astuces de développement, tutoriels Laravel et plus encore')
@section('meta_description', 'Découvrez le Blog du Dev Fullstack, votre source d\'astuces de développement, de tutoriels sur Laravel et bien plus. Restez à jour avec les dernières tendances technologiques et améliorez vos compétences en développement web.')

@section('content')
    <div class="container my-5">
        <div class="row p-4 pb-0 pe-lg-0 pt-lg-5 align-items-center rounded-3 border shadow-lg">
            <div class="col-lg-7 p-3 p-lg-5 pt-lg-3">
                <h1 class="display-6 fw-bold lh-1">Bienvenue sur le Blog<br /> du Dev FullStack</h1>
                <p class="lead">Je suis développeur web fullstack avec une expertise dans la création d'applications performantes. Passionné par Laravel, j'ai acquis une solide expérience dans la conception d'applications web robustes et évolutives en utilisant cette technologie.</p>
                <p class="lead">Basé pret d'Antibes et Sophia-Antipolis (06), je combine ma passion pour le développement avec une approche créative pour offrir des solutions innovantes. Mon objectif est de créer des expériences utilisateur exceptionnelles grâce à des technologies modernes et des meilleures pratiques.</p>
                <p class="lead">À travers ce blog, je partage des tutoriels et des conseils pour aider les développeurs à améliorer leurs compétences et à créer des applications de qualité. J'espère que vous trouverez ici des ressources inspirantes pour votre parcours de développeur web.</p>
                <div class="d-grid gap-2 d-md-flex justify-content-md-start mb-4 mb-lg-3">
                    <a href="{{ route('posts.index') }}" class="btn btn-danger btn-lg">Explorer les articles</a>
                </div>
            </div>
            <div class="col-lg-4 offset-lg-1 p-0 overflow-hidden shadow-lg">
                <img class="rounded-lg-3" src="{{ asset('images/logo.jpg') }}" alt="" width="720">
            </div>
        </div>
    </div>
    <section class="features py-5">
        <div class="container">
            <div class="row text-center">
                <div class="col-md-4">
                    <div class="feature-icon">
                        <i class="fa-brands fa-laravel fa-5x"></i>
                    </div>
                    <h3 class="mt-3">Laravel</h3>
                    <p>Laravel est le cœur de mon choix pour son architecture moderne, sa facilité de développement et sa communauté dynamique. Il me permet de créer des applications web robustes et élégantes qui répondent aux besoins des utilisateurs.</p>
                </div>
                <div class="col-md-4">
                    <div class="feature-icon">
                        <i class="fa-regular fa-hand-pointer fa-5x"></i>
                    </div>
                    <h3 class="mt-3">Livewire</h3>
                    <p>Livewire est l'élément clé pour développer des interfaces utilisateur interactives sans sacrifier la performance. Son approche intuitive offre une expérience web immersive et dynamique qui capte l'attention des utilisateurs.</p>
                </div>
                <div class="col-md-4">
                    <div class="feature-icon">
                        <i class="fa-solid fa-globe fa-5x"></i>
                    </div>
                    <h3 class="mt-3">API Intégrées</h3>
                    <p>Mon blog tire pleinement parti des API en intégrant des services externes. Les API offrent des fonctionnalités étendues, de la récupération de données en temps réel à l'interactivité. Grâce à elles, je peux fournir des informations actualisées et interactives à mes visiteurs, rendant l'expérience utilisateur encore plus immersive.</p>
                </div>
            </div>
        </div>
    </section>

@endsection

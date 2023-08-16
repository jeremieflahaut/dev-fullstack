@extends('layouts.app')

@section('title', 'Mentions légales - Blog du Dev Fullstack')

@section('description', 'Consultez les mentions légales du Blog du Dev Fullstack. Découvrez les informations sur l\'éditeur du site, l\'hébergeur, la collecte de données personnelles et plus encore.')

@section('content')
    <div class="container">
        <div class="row">
            <div class="col-md-8 mx-auto">
                <h1 class="mb-4">Mentions légales</h1>
                <h2>Hébergeur du site :</h2>
                <p>OVH<br />
                Adresse : 2 rue Kellermann - 59100 Roubaix - France<br />
                Site Web : <a href="https://www.ovh.com" class="link-secondary">www.ovh.com</a></p>

                <h2>Collecte et traitement des données personnelles :</h2>
                <p>Aucune donnée personnelle n'est collectée sur ce site à moins que vous n'interagissiez activement avec certaines fonctionnalités nécessitant la collecte de telles informations, telles que les formulaires de contact ou d'inscription à la newsletter. Dans ce cas, les données que vous fournissez seront utilisées uniquement à des fins spécifiques et ne seront en aucun cas cédées, louées ou vendues à des tiers.</p>
                <p>Conformément à la loi Informatique et Libertés du 6 janvier 1978 modifiée, vous disposez d'un droit d'accès, de rectification, de suppression et d'opposition aux données vous concernant. Vous pouvez exercer ce droit en me contactant.</p>

                <h2 id="cookies">Gestion des cookies :</h2>
                <p>Ce site utilise des cookies pour améliorer votre expérience de navigation et vous offrir des fonctionnalités personnalisées. Les cookies sont de petits fichiers texte stockés sur votre ordinateur ou votre appareil mobile. Ils sont couramment utilisés pour collecter des informations anonymes sur la manière dont vous naviguez sur le site, afin de vous offrir une expérience plus pertinente.</p>
                <p>Nous utilisons également la solution de gestion des cookies <a href="https://tarteaucitron.io/" target="_blank" rel="noopener" class="link-secondary">Tarteaucitron.io</a> pour vous permettre de choisir quels cookies vous souhaitez accepter. Vous pouvez modifier vos préférences à tout moment en cliquant sur le cadenas situé en bas à droite de la page.</p>
                <p>En utilisant ce site, vous consentez à l'utilisation de cookies conformément à notre <a href="{{ route('cookies') }}" class="link-secondary">politique de cookies</a>.</p>

                <h2>Responsabilité :</h2>
                <p>Les informations fournies sur ce site sont fournies à titre indicatif. Je ne saurai être tenu responsable de toute erreur, omission ou résultat obtenu suite à l'utilisation de ces informations.</p>

                <h2>Propriété intellectuelle :</h2>
                <p>Tous les textes rédigés sur ce blog sont la propriété du responsable du Blog et sont protégés par les lois sur le droit d'auteur. Les images utilisées sur ce site sont issues de <a href="https://www.pexels.com" target="_blank" rel="noopener noreferrer" class="link-secondary">Pexels</a> et sont considérées comme des images libres de droits. Tous les droits de propriété intellectuelle associés au contenu de ce blog sont réservés.</p>
                <p>Toute reproduction, redistribution ou utilisation du contenu, qu'il s'agisse de textes ou d'images, à des fins commerciales ou non commerciales, est strictement interdite sans l'autorisation préalable écrite du responsable du Blog. Si vous souhaitez partager notre contenu sur d'autres plateformes, veuillez inclure un lien vers notre blog et mentionner la source.</p>
                <p>En ce qui concerne les textes générés avec l'aide de ChatGPT, veuillez noter que ce contenu est produit avec l'assistance d'une intelligence artificielle et qu'il peut ne pas être considéré comme du contenu original dans certains contextes.</p>

                <h2>Contact: </h2>
                En raison de nombreux spams, je vous propose de prendre contact avec moi directement via X (Anciennement Twitter) sur <a href="https://twitter.com/_DevFullStack" target="_blank" class="link-secondary">@_devFullStack</a>
            </div>
        </div>
    </div>
@endsection


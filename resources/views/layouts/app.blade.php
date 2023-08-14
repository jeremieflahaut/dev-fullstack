<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="@yield('meta_description', 'Découvrez un article passionnant sur le blog du Dev Fullstack. Explorez des sujets intéressants liés au développement web, à Laravel, à la programmation et bien plus encore.')">

    <title>@yield('title', 'Article du Blog du Dev Fullstack : Découvrez des astuces de développement, des tutoriels sur Laravel et bien plus !') - Blog</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-tomorrow.min.css">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @livewireStyles

    <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
            j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
            'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
        })(window,document,'script','dataLayer','GTM-M6R9TZXR');</script>

</head>
<body>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-M6R9TZXR"
                  height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>

<div class="container">
    <livewire:layout.header/>
</div>

<div class="container" wire:loading>
    <p>Chargement en cours...</p>
</div>

@yield('content')

<div class="container">
    <livewire:layout.footer/>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js"
        integrity="sha384-kenU1KFdBIe4zVF0s0G1M5b4hcpxyD9F7jL+jjXkk+Q2h455rYXK/7HAuoJl+0I4"
        crossorigin="anonymous">
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/prism.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/components/prism-core.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/plugins/autoloader/prism-autoloader.min.js"></script>
<script>
    Prism.plugins.autoloader.use_minified = true;
    Prism.highlightAll();
</script>
@livewireScripts
</body>
</html>

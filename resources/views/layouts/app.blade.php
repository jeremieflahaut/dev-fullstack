<!DOCTYPE html>
<html lang="fr">
<head>
    @guest
        <script src="/js/tarteaucitron/tarteaucitron.js"></script>

        <script type="text/javascript">
            tarteaucitron.init({
                "privacyUrl": "", /* Privacy policy url */
                "bodyPosition": "bottom", /* or top to bring it as first element for accessibility */

                "hashtag": "#tarteaucitron", /* Open the panel with this hashtag */
                "cookieName": "tarteaucitron", /* Cookie name */

                "orientation": "bottom", /* Banner position (top - bottom) */

                "groupServices": false, /* Group services by category */
                "showDetailsOnClick": true, /* Click to expand the description */
                "serviceDefaultState": "wait", /* Default state (true - wait - false) */

                "showAlertSmall": false, /* Show the small banner on bottom right */
                "cookieslist": false, /* Show the cookie list */

                "closePopup": false, /* Show a close X on the banner */

                "showIcon": true, /* Show cookie icon to manage cookies */
                //"iconSrc": "", /* Optionnal: URL or base64 encoded image */
                "iconPosition": "BottomRight", /* BottomRight, BottomLeft, TopRight and TopLeft */

                "adblocker": false, /* Show a Warning if an adblocker is detected */

                "DenyAllCta" : true, /* Show the deny all button */
                "AcceptAllCta" : true, /* Show the accept all button when highPrivacy on */
                "highPrivacy": true, /* HIGHLY RECOMMANDED Disable auto consent */

                "handleBrowserDNTRequest": false, /* If Do Not Track == 1, disallow all */

                "removeCredit": true, /* Remove credit link */
                "moreInfoLink": false, /* Show more info link */

                "useExternalCss": false, /* If false, the tarteaucitron.css file will be loaded */
                "useExternalJs": false, /* If false, the tarteaucitron.js file will be loaded */

                //"cookieDomain": ".my-multisite-domaine.fr", /* Shared cookie for multisite */

                "readmoreLink": "", /* Change the default readmore link */

                "mandatory": true, /* Show a message about mandatory cookies */
                "mandatoryCta": true /* Show the disabled accept button when mandatory on */
            });
        </script>
        @if(App::environment('production'))
            <script type="text/javascript">
                tarteaucitron.user.googletagmanagerId = '{{ config("layout.google_tag_manager_id") }}';
                (tarteaucitron.job = tarteaucitron.job || []).push('googletagmanager');
            </script>
        @endif
    @endguest
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
          content="@yield('meta_description', 'Découvrez un article passionnant sur le blog du Dev Fullstack. Explorez des sujets intéressants liés au développement web, à Laravel, à la programmation et bien plus encore.')">

    <title>@yield('title', 'Article du Blog du Dev Fullstack : Découvrez des astuces de développement, des tutoriels sur Laravel et bien plus !')
        - Blog</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-rbsA2VBKQhggwzxH7pPCaAqO46MgnOM80zW1RWuH61DGLwZJEdK2Kadq2F9CUG65" crossorigin="anonymous">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.24.1/themes/prism-tomorrow.min.css">

    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @livewireStyles

</head>
<body>
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

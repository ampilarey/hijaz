{{-- Makes the page installable as an app: the staff app from the portal and the ground pages, the customer app from /my. --}}
<link rel="manifest" href="{{ $manifest }}">
<meta name="theme-color" content="#017a42">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="{{ $title }}">
<link rel="apple-touch-icon" href="{{ \App\Support\PublicAsset::url('apple-touch-icon.png') }}">
@vite('resources/js/pwa.js')

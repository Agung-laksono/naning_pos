<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'Rozak Mart') }}</title>

        <!-- PWA Web App Manifest & Standalone Fullscreen Meta Tags -->
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#2563eb">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Rozak Mart">
        <link rel="apple-touch-icon" href="/icons/icon-192x192.png">

        <script>
            // Tangkap event sebelum Vue sempat me-load agar prompt instalasi tidak terlewat
            window.__pwaInstallPrompt = null;
            window.addEventListener('beforeinstallprompt', function(e) {
                e.preventDefault();
                window.__pwaInstallPrompt = e;
                window.dispatchEvent(new CustomEvent('pwa-prompt-ready'));
            });
        </script>

        <!-- Google Fonts: Roboto & Roboto Mono -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Roboto+Mono:wght@500;700;900&display=swap" rel="stylesheet">

        <style>
            /* Mengisi layar tablet secara penuh 100vw x 100vh tanpa letterbox */
            html, body {
                width: 100vw;
                height: 100vh;
                min-width: 100%;
                min-height: 100%;
                overflow: hidden;
                margin: 0;
                padding: 0;
                background-color: #0f172a;
                user-select: none;
                -webkit-user-select: none;
                touch-action: manipulation;
                font-family: 'Roboto', system-ui, -apple-system, sans-serif;
            }

            /* Android Material 3 Tap Effect */
            button, .clickable-chip {
                transition: all 0.15s cubic-bezier(0.2, 0, 0, 1);
                -webkit-tap-highlight-color: transparent;
            }
            button:active, .clickable-chip:active {
                transform: scale(0.97);
                filter: brightness(0.94);
            }

            /* Material Custom Scrollbar */
            ::-webkit-scrollbar {
                width: 4px;
                height: 4px;
            }
            ::-webkit-scrollbar-track {
                background: transparent;
            }
            ::-webkit-scrollbar-thumb {
                background: #cbd5e1;
                border-radius: 9999px;
            }
            ::-webkit-scrollbar-thumb:hover {
                background: #94a3b8;
            }

            /* Khusus Print Struk Kasir Thermal 80mm */
            @media print {
                body {
                    background: white !important;
                    margin: 0 !important;
                    padding: 0 !important;
                    width: 100% !important;
                    height: auto !important;
                    max-width: 100% !important;
                    max-height: none !important;
                    overflow: visible !important;
                }
                body > * {
                    display: none !important;
                }
                #receipt-modal {
                    display: block !important;
                    position: static !important;
                    background: white !important;
                    padding: 0 !important;
                }
                #receipt-modal > div {
                    box-shadow: none !important;
                    border: none !important;
                    padding: 0 !important;
                    max-width: 100% !important;
                }
                #receipt-modal button {
                    display: none !important;
                }
                .thermal-receipt {
                    border: none !important;
                    box-shadow: none !important;
                    max-height: none !important;
                    padding: 0 !important;
                    width: 100% !important;
                    max-width: 80mm !important;
                    margin: 0 auto !important;
                }
            }
        </style>

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead

        <!-- Service Worker Registration untuk PWA Offline-First -->
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', function() {
                    navigator.serviceWorker.register('/sw.js').then(function(reg) {
                        console.log('Rozak Mart PWA Service Worker terdaftar.');
                        reg.update(); // Paksa periksa pembaruan sw.js
                    }).catch(function(err) {
                        console.warn('PWA SW gagal:', err);
                    });

                    // Otomatis muat ulang jika Service Worker versi baru sudah aktif
                    let isRefreshing = false;
                    navigator.serviceWorker.addEventListener('controllerchange', function() {
                        if (!isRefreshing) {
                            isRefreshing = true;
                            window.location.reload();
                        }
                    });
                });
            }
        </script>
    </head>
    <body class="antialiased flex items-center justify-center w-full h-full">
        @inertia
    </body>
</html>

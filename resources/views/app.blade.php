<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=1097, height=686, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">

        <title inertia>{{ config('app.name', 'TPKmart POS') }} - Android Tablet (1097x686)</title>

        <!-- Google Fonts: Roboto & Roboto Mono -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700;900&family=Roboto+Mono:wght@500;700;900&display=swap" rel="stylesheet">

        <style>
            /* Mengunci rasio layar tablet persis 1097 x 686 (Android Tablet Immersive Mode) */
            html, body {
                width: 1097px;
                height: 686px;
                max-width: 1097px;
                max-height: 686px;
                overflow: hidden;
                margin: 0 auto;
                background-color: #0f172a; /* Slate 900 framing untuk display letterbox jika dibuka di layar monitor lebih besar */
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
    </head>
    <body class="antialiased flex items-center justify-center">
        @inertia
    </body>
</html>

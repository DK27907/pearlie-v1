<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Legal information for MediDesk Assistant.">
    <title>@yield('title') · MediDesk Assistant</title>
    <style>
        body {
            margin: 0;
            color: #1e293b;
            font-family: system-ui, sans-serif;
            line-height: 1.65;
        }

        header, main, footer {
            box-sizing: border-box;
            margin: 0 auto;
            max-width: 48rem;
            padding: 1.25rem;
        }

        header, footer {
            color: #475569;
        }

        header {
            border-bottom: 1px solid #e2e8f0;
            font-weight: 700;
        }

        footer {
            border-top: 1px solid #e2e8f0;
            font-size: 0.9rem;
        }

        a {
            color: #075985;
        }

        h1, h2 {
            line-height: 1.25;
        }

        h2 {
            margin-top: 2rem;
        }

        nav a {
            margin-right: 1rem;
        }
    </style>
</head>
<body>
    <header>MediDesk Assistant</header>
    <main>
        @yield('content')
    </main>
    <footer>
        <nav aria-label="Legal pages">
            @yield('legal-links')
        </nav>
        <p>© 2026 AxiomForge Digital Solutions</p>
    </footer>
</body>
</html>

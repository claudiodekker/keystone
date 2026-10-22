<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'Laravel') }}</title>
    </head>
    <body>
        <p>Signed in as {{ $user->name }}.</p>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit">Sign out</button>
        </form>
    </body>
</html>

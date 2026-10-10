<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    <p>{{ __('keystone::mail.welcome.created', ['app' => $app]) }}</p>

    <p>{{ __('keystone::mail.welcome.sign_in') }}</p>
</body>
</html>

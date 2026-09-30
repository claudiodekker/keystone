<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="font-family: -apple-system, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #1f2937; line-height: 1.5;">
    @include($what)

    <table role="presentation" cellpadding="4" cellspacing="0">
        <tr>
            <th align="left">{{ __('keystone::alerts.fields.when') }}</th>
            <td>{{ $occurredAt }}</td>
        </tr>
        <tr>
            <th align="left">{{ __('keystone::alerts.fields.ip_address') }}</th>
            <td>{{ $ipAddress }}</td>
        </tr>
        @if ($location !== null)
            <tr>
                <th align="left">{{ __('keystone::alerts.fields.location') }}</th>
                <td>{{ $location }}</td>
            </tr>
        @endif
        <tr>
            <th align="left">{{ __('keystone::alerts.fields.device') }}</th>
            <td>{{ $device }}</td>
        </tr>
        @if ($credential !== null)
            <tr>
                <th align="left">{{ __('keystone::alerts.fields.credential') }}</th>
                <td>{{ $credential }}</td>
            </tr>
        @endif
    </table>

    <p>{{ __('keystone::alerts.review') }}</p>
</body>
</html>

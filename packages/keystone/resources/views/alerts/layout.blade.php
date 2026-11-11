<!DOCTYPE html>
<html>
<body>
<p>@yield('summary')</p>
<p>{{ $when }}</p>
@foreach ($ipAddresses as $ipAddress)
<p>{{ $ipAddress }}</p>
@endforeach
@foreach ($devices as $device)
<p>{{ $device }}</p>
@endforeach
<p>@yield('advice', __('keystone::alerts.if-not-you'))</p>
</body>
</html>

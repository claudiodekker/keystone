<!DOCTYPE html>
<html>
<body>
<p>@yield('summary')</p>
<p>{{ $when }}</p>
@if ($ipAddress !== null)
<p>{{ $ipAddress }}</p>
@endif
<p>{{ $device }}</p>
<p>@yield('advice', __('keystone::alerts.if-not-you'))</p>
</body>
</html>

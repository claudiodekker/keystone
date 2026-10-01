<p>{{ __('keystone::alerts.types.recovery_code.used.what') }}</p>

@if ($remainingRecoveryCodes !== null)
    <p>{{ trans_choice('keystone::alerts.types.recovery_code.used.remaining', $remainingRecoveryCodes) }}</p>
@endif

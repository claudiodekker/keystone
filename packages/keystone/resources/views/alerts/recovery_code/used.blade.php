@extends('keystone::alerts.layout')

@section('summary', __('keystone::alerts.recovery_code.used.summary').' '.trans_choice('keystone::alerts.recovery_code.used.left', $recoveryCodesLeft))

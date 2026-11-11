@extends('keystone::alerts.layout')

@section('summary', trans_choice('keystone::alerts.challenge.abandoned.summary', $count))

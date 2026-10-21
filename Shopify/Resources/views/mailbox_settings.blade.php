@extends('layouts.app')

@section('title_full', 'Shopify - '.$mailbox->name)

@section('sidebar')
    @include('partials/sidebar_menu_toggle')
    @include('mailboxes/sidebar_menu')
@endsection

@section('content')
    <div class="section-heading">
        Shopify
    </div>

    @include('partials/flash_messages')

    <div class="row-container">
        <div class="row">
            <div class="col-xs-12">
                @if ($global_configured)
                    <div class="alert alert-info margin-top">
                        {{ __('A global Shopify connection is configured. Fill in this page only if this mailbox uses a different store; leave the shop domain empty to use the global connection.') }}
                    </div>
                @endif

                <form class="form-horizontal margin-top margin-bottom" method="POST" action="{{ route('mailboxes.shopify.save', ['id' => $mailbox->id]) }}" autocomplete="off">
                    {{ csrf_field() }}

                    @include('shopify::partials/settings_fields', ['settings' => $settings, 'scope' => 'mailbox'])

                    <div class="form-group margin-top margin-bottom">
                        <div class="col-sm-6 col-sm-offset-2">
                            <button type="submit" class="btn btn-primary">{{ __('Save and test connection') }}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endsection

<form class="form-horizontal margin-top margin-bottom" method="POST" action="" autocomplete="off">
    {{ csrf_field() }}

    @if (!empty($settings['mailboxes_enabled']))
        <div class="alert alert-warning">
            {{ __('These mailboxes have their own Shopify connection, which replaces the one below:') }}
            <ul>
                @foreach ($settings['mailboxes_enabled'] as $mailbox)
                    <li><a href="{{ route('mailboxes.shopify', ['id' => $mailbox->id]) }}">{{ $mailbox->name }}</a></li>
                @endforeach
            </ul>
        </div>
    @endif

    @include('shopify::partials/settings_fields', ['settings' => $settings, 'scope' => 'global'])

    <div class="form-group margin-top margin-bottom">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">
                {{ __('Save and test connection') }}
            </button>
        </div>
    </div>
</form>

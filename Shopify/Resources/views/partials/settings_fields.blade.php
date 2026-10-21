{{--
    Connection fields shared by the global settings page and the mailbox settings page.
    Expects $settings with keys such as 'shopify.shop_domain'.
    Secrets arrive masked (********) and are never sent back to the browser.
--}}
@php
    $old = old('settings');
    $val = function ($key, $default = '') use ($old, $settings) {
        if (is_array($old) && array_key_exists('shopify.'.$key, $old)) {
            return $old['shopify.'.$key];
        }

        return isset($settings['shopify.'.$key]) ? $settings['shopify.'.$key] : $default;
    };
@endphp

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Shop domain') }}</label>
    <div class="col-sm-6">
        <div class="input-group input-sized-lg">
            <span class="input-group-addon input-group-addon-grey">https://</span>
            <input type="text" class="form-control input-sized-lg" name="settings[shopify.shop_domain]" value="{{ $val('shop_domain') }}" placeholder="my-store.myshopify.com" autocomplete="off">
        </div>
        <p class="form-help">
            {{ __('The .myshopify.com address of your store (not your public domain).') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Client ID') }}</label>
    <div class="col-sm-6">
        <input type="text" class="form-control input-sized-lg" name="settings[shopify.client_id]" value="{{ $val('client_id') }}" autocomplete="off">
        <p class="form-help">
            {{ __('Shopify Dev Dashboard → your app → Settings.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Client secret') }}</label>
    <div class="col-sm-6">
        <input type="password" class="form-control input-sized-lg" name="settings[shopify.client_secret]" value="{{ $val('client_secret') }}" autocomplete="new-password">
        <p class="form-help">
            {{ __('Stored encrypted. Leave the asterisks to keep the current secret.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label"></label>
    <div class="col-sm-6">
        <details @if ($val('access_token') !== '') open @endif>
            <summary class="text-help" style="cursor:pointer">&#9656; {{ __('Legacy access token') }} <small>({{ __('Only for custom apps created before 2026 (token starting with shpat_)') }})</small></summary>
            <input type="password" class="form-control input-sized-lg margin-top-10" name="settings[shopify.access_token]" value="{{ $val('access_token') }}" autocomplete="new-password">
            <p class="form-help">
                {{ __('Optional. If set, it is used instead of the Client ID and Client secret.') }}
            </p>
        </details>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('API version') }}</label>
    <div class="col-sm-6">
        <input type="text" class="form-control input-sized-lg" name="settings[shopify.api_version]" value="{{ $val('api_version', \Modules\Shopify\Services\ShopifyManager::DEFAULT_API_VERSION) }}" placeholder="2026-10" autocomplete="off">
        <p class="form-help">
            {!! __('Shopify releases a new version every quarter (2026-10, 2027-01…). :%a_begin%More info:%a_end%', ['%a_begin%' => '<a href="https://shopify.dev/docs/api/usage/versioning" target="_blank" rel="noopener noreferrer">', '%a_end%' => '</a>']) !!}
        </p>
    </div>
</div>

<div class="form-group">
    <label for="shopify-sync-{{ $scope }}" class="col-sm-2 control-label">{{ __('Sync customers') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <div class="onoffswitch-wrap">
                <div class="onoffswitch">
                    <input type="hidden" name="settings[shopify.sync_customers]" value="0">
                    <input type="checkbox" name="settings[shopify.sync_customers]" value="1" id="shopify-sync-{{ $scope }}" class="onoffswitch-checkbox" @if ($val('sync_customers', true)) checked="checked" @endif>
                    <label class="onoffswitch-label" for="shopify-sync-{{ $scope }}"></label>
                </div>
            </div>
        </div>
        <p class="help-block">
            {{ __('When a customer writes in, find them in Shopify and fill the empty fields of their FreeScout profile (name, phone, address). Existing data is never overwritten.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label for="shopify-link-{{ $scope }}" class="col-sm-2 control-label">{{ __('Order links') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <div class="onoffswitch-wrap">
                <div class="onoffswitch">
                    <input type="hidden" name="settings[shopify.link_orders]" value="0">
                    <input type="checkbox" name="settings[shopify.link_orders]" value="1" id="shopify-link-{{ $scope }}" class="onoffswitch-checkbox" @if ($val('link_orders', true)) checked="checked" @endif>
                    <label class="onoffswitch-label" for="shopify-link-{{ $scope }}"></label>
                </div>
            </div>
        </div>
        <p class="help-block">
            {{ __('Order numbers written in messages become clickable and open the order details.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label for="shopify-restrict-{{ $scope }}" class="col-sm-2 control-label">{{ __('Restrict order lookups') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <div class="onoffswitch-wrap">
                <div class="onoffswitch">
                    <input type="hidden" name="settings[shopify.restrict_orders]" value="0">
                    <input type="checkbox" name="settings[shopify.restrict_orders]" value="1" id="shopify-restrict-{{ $scope }}" class="onoffswitch-checkbox" @if ($val('restrict_orders', false)) checked="checked" @endif>
                    <label class="onoffswitch-label" for="shopify-restrict-{{ $scope }}"></label>
                </div>
            </div>
        </div>
        <p class="help-block">
            {{ __('Agents can only open the orders of the customer of the conversation (matched by email address). When off, any order of the store can be searched by its number.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Order number prefix') }}</label>
    <div class="col-sm-6">
        <input type="text" class="form-control input-sized-sm" name="settings[shopify.order_prefix]" value="{{ $val('order_prefix', '#') }}" maxlength="12" placeholder="#" autocomplete="off">
        <p class="form-help">
            {{ __('How your order numbers start: # for #1001, or SHOP- for SHOP-1001.') }}
        </p>
    </div>
</div>

<div class="form-group">
    <div class="col-sm-6 col-sm-offset-2">
        <div class="alert alert-info">
            <strong>{{ __('Setting up the connection') }}</strong>
            <ol class="margin-bottom-0">
                <li>{{ __('In the Shopify Dev Dashboard, create an app and add the read_orders and read_customers scopes.') }}</li>
                <li>{{ __('To see orders older than 60 days, also request read_all_orders (Shopify must approve it).') }}</li>
                <li>{{ __('Release a version of the app and install it on your store, from the same Shopify organization.') }}</li>
                <li>{{ __('Copy the Client ID and Client secret from the app settings and paste them above.') }}</li>
            </ol>
        </div>
    </div>
</div>

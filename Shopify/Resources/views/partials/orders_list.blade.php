<div class="panel-heading">
    <h4 class="panel-title">
        <a data-toggle="collapse" href=".shopify-collapse-orders">
            Shopify
            <b class="caret"></b>
        </a>
    </h4>
</div>
<div class="shopify-collapse-orders panel-collapse collapse in">
    <div class="panel-body">
        <div class="sidebar-block-header2"><strong>Shopify</strong> (<a data-toggle="collapse" href=".shopify-collapse-orders">{{ __('close') }}</a>)</div>

        @if ($data === null)
            <div class="shopify-loader"><img src="{{ asset('img/loader-tiny.gif') }}" alt="{{ __('Loading…') }}"></div>
        @else
            @if (!empty($data['error']))
                <div class="shopify-error" role="alert">
                    {{ $data['error']['message'] }}
                    @if (in_array($data['error']['type'], ['scope', 'api']))
                        <div class="shopify-error-detail">{{ $data['error']['detail'] }}</div>
                    @endif
                </div>
            @else
                @if ($data['customer'])
                    <div class="shopify-customer">
                        <div class="shopify-customer-name">
                            @if ($data['customer']['admin_url'])
                                <a href="{{ $data['customer']['admin_url'] }}" target="_blank" rel="noopener noreferrer">{{ $data['customer']['name'] ?: $data['customer']['email'] }}</a>
                            @else
                                {{ $data['customer']['name'] ?: $data['customer']['email'] }}
                            @endif
                        </div>
                        <div class="shopify-customer-stats">
                            <span>{{ __('Orders') }}: <strong>{{ $data['customer']['orders_count'] }}</strong></span>
                            @if ($data['customer']['total_spent'])
                                <span>{{ __('Spent') }}: <strong>{{ $data['customer']['total_spent'] }}</strong></span>
                            @endif
                            @if ($data['customer']['since'])
                                <span>{{ __('Customer since') }} {{ $data['customer']['since'] }}</span>
                            @endif
                        </div>
                        @if (count($data['customer']['tags']))
                            <div class="shopify-tags">
                                @foreach ($data['customer']['tags'] as $tag)
                                    <span class="shopify-tag">{{ $tag }}</span>
                                @endforeach
                            </div>
                        @endif
                        @if ($data['customer']['note'] !== '')
                            <div class="shopify-note" title="{{ __('Note on the Shopify customer') }}">{{ $data['customer']['note'] }}</div>
                        @endif
                    </div>
                @elseif ($data['notice'] === 'missing_customers_scope')
                    <div class="shopify-hint">{{ __('Customer details are not available: add the read_customers permission to the app.') }}</div>
                @endif

                @if (count($data['orders']))
                    <ul class="sidebar-block-list shopify-orders-list">
                        @foreach ($data['orders'] as $order)
                            <li class="shopify-order-item" data-shopify-order="{{ $order['name'] }}" tabindex="0" role="button">
                                <div class="shopify-order-top">
                                    <span class="shopify-order-name">{{ $order['name'] }}</span>
                                    <span class="shopify-order-total">{{ $order['total'] }}</span>
                                </div>
                                <div class="shopify-order-meta">
                                    <small class="text-help">{{ $order['created_at'] }}</small>
                                    <span class="shopify-badges">
                                        @if ($order['cancelled'])
                                            @include('shopify::partials/badge', ['status' => ['tone' => 'danger', 'label' => __('Cancelled')]])
                                        @endif
                                        @include('shopify::partials/badge', ['status' => $order['financial']])
                                        @include('shopify::partials/badge', ['status' => $order['fulfillment']])
                                    </span>
                                </div>
                            </li>
                        @endforeach
                    </ul>

                    {{-- Details are rendered here, hidden, and shown in the panel on click. --}}
                    @foreach ($data['orders'] as $order)
                        <div class="shopify-detail-source" hidden data-shopify-detail="{{ $order['name'] }}">
                            @include('shopify::partials/order_detail', ['order' => $order])
                        </div>
                    @endforeach
                @else
                    <div class="text-help margin-top-10 shopify-no-orders">
                        @if ($data['customer'])
                            {{ __('No orders found') }}
                        @else
                            {{ __('No Shopify customer or order found for this email.') }}
                        @endif
                    </div>
                @endif
            @endif

            <form class="shopify-search" data-shopify-search>
                <input type="text" class="form-control input-sm" maxlength="40" placeholder="{{ __('Find an order number…') }}" aria-label="{{ __('Find an order number…') }}">
                <button type="submit" class="btn btn-default btn-sm" title="{{ __('Search') }}"><i class="glyphicon glyphicon-search"></i></button>
            </form>

            <div class="margin-top-10 shopify-refresh small">
                <a href="#" class="sidebar-block-link"><i class="glyphicon glyphicon-refresh"></i> {{ __('Refresh') }}</a>
            </div>
        @endif
    </div>
</div>

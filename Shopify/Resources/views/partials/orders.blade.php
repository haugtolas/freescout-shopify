{{-- Sidebar block. $data is null when the data still has to be loaded by the browser. --}}
<div class="conv-sidebar-block shopify-block">
    <div class="panel-group accordion accordion-empty">
        <div class="panel panel-default" id="shopify-orders">
            @include('shopify::partials/orders_list', ['data' => $data, 'settings' => $settings])
        </div>
    </div>
</div>

{{-- Slide-out panel. Moved to <body> by module.js so it can cover the whole page. --}}
<div id="shopify-order-panel" class="shopify-order-panel" aria-hidden="true">
    <div class="shopify-panel-overlay"></div>
    <div class="shopify-panel-content" role="dialog" aria-modal="true" aria-labelledby="shopify-panel-title">
        <div class="shopify-panel-header">
            <span class="shopify-panel-title"><i class="glyphicon glyphicon-shopping-cart"></i> <span id="shopify-panel-title"></span></span>
            <button type="button" class="shopify-panel-close" aria-label="{{ __('Close') }}">&times;</button>
        </div>
        <div class="shopify-panel-body" id="shopify-panel-body"></div>
    </div>
</div>

@php
    $shopify_config = [
        'conversationId' => (int) $conversation_id,
        'load'           => $data === null,
        'linkOrders'     => (bool) $settings['link_orders'],
        'prefix'         => $settings['order_prefix'],
        'strings'        => [
            'error'     => __('Could not load the Shopify data.'),
            'linkTitle' => __('Show this order'),
            'loading'   => __('Loading…'),
        ],
    ];
@endphp

@section('javascript')
    @parent
    shopifyInit({!! json_encode($shopify_config, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!});
@endsection

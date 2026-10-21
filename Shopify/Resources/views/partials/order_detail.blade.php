{{-- Details of one order. $order comes from OrderPresenter::order(); Blade escapes everything. --}}
@if (!empty($mismatch))
    <div class="shopify-warning" role="alert">{{ __('This order is not linked to the email address of this customer. Check who you are talking to before sharing any detail.') }}</div>
@endif
<div class="shopify-section">
    <div class="shopify-row">
        <div class="shopify-label">{{ __('Payment') }}</div>
        <div class="shopify-value">@include('shopify::partials/badge', ['status' => $order['financial']])</div>
    </div>
    <div class="shopify-row">
        <div class="shopify-label">{{ __('Fulfillment') }}</div>
        <div class="shopify-value">@include('shopify::partials/badge', ['status' => $order['fulfillment']])</div>
    </div>
    <div class="shopify-row">
        <div class="shopify-label">{{ __('Order placed') }}</div>
        <div class="shopify-value">{{ $order['created_at'] }}</div>
    </div>
    @if ($order['cancelled'])
        <div class="shopify-row">
            <div class="shopify-label">{{ __('Cancelled') }}</div>
            <div class="shopify-value">{{ $order['cancelled_at'] }}</div>
        </div>
    @endif
    @if ($order['source'] !== '')
        <div class="shopify-row">
            <div class="shopify-label">{{ __('Channel') }}</div>
            <div class="shopify-value">{{ $order['source'] }}</div>
        </div>
    @endif
    <div class="shopify-links">
        @if ($order['admin_url'])
            <a href="{{ $order['admin_url'] }}" target="_blank" rel="noopener noreferrer" class="shopify-panel-link">{{ __('Open in Shopify') }} →</a>
        @endif
        @if ($order['status_url'])
            <a href="{{ $order['status_url'] }}" target="_blank" rel="noopener noreferrer" class="shopify-panel-link">{{ __('Customer order status page') }} →</a>
        @endif
    </div>
</div>

@if (count($order['tracking']))
    <div class="shopify-section">
        <div class="shopify-section-title">{{ __('Tracking') }}</div>
        @foreach ($order['tracking'] as $track)
            <div class="shopify-row">
                <div class="shopify-label">{{ $track['company'] !== '' ? $track['company'] : __('Shipment') }}</div>
                <div class="shopify-value">@include('shopify::partials/badge', ['status' => $track['status']])</div>
            </div>
            @if ($track['number'] !== '')
                <div class="shopify-tracking">
                    @if ($track['url'])
                        <a href="{{ $track['url'] }}" target="_blank" rel="noopener noreferrer" class="shopify-tracking-number">{{ $track['number'] }}</a>
                    @else
                        <span class="shopify-tracking-number">{{ $track['number'] }}</span>
                    @endif
                </div>
            @endif
            @if ($track['delivered_at'] !== '')
                <div class="shopify-sub">{{ __('Delivered') }}: {{ $track['delivered_at'] }}</div>
            @elseif ($track['eta'] !== '')
                <div class="shopify-sub">{{ __('Estimated delivery') }}: {{ $track['eta'] }}</div>
            @endif
        @endforeach
    </div>
@endif

@if (count($order['address']))
    <div class="shopify-section">
        <div class="shopify-section-title">{{ __('Shipping address') }}</div>
        <div class="shopify-address">
            @foreach ($order['address'] as $line)
                {{ $line }}@if (!$loop->last)<br>@endif
            @endforeach
        </div>
    </div>
@endif

@if (count($order['items']))
    <div class="shopify-section">
        <div class="shopify-section-title">{{ __('Items') }} ({{ $order['items_count'] }})</div>
        @foreach ($order['items'] as $item)
            <div class="shopify-line-item">
                <div class="shopify-line-item-image">
                    @if ($item['image'])
                        <img src="{{ $item['image'] }}" alt="" loading="lazy">
                    @else
                        <i class="glyphicon glyphicon-gift"></i>
                    @endif
                </div>
                <div class="shopify-line-item-details">
                    <div class="shopify-line-item-name">{{ $item['title'] }}</div>
                    @if ($item['variant'] !== '')
                        <div class="shopify-sub">{{ $item['variant'] }}</div>
                    @endif
                    @if ($item['sku'] !== '')
                        <div class="shopify-sub">SKU: {{ $item['sku'] }}</div>
                    @endif
                </div>
                <div class="shopify-line-item-price">
                    <div>{{ $item['line_total'] }}</div>
                    <div class="shopify-sub">{{ $item['quantity'] }} × {{ $item['unit_price'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
@endif

<div class="shopify-section">
    <div class="shopify-section-title">{{ __('Receipt') }}</div>
    @if ($order['subtotal'] !== '')
        <div class="shopify-row"><div class="shopify-label">{{ __('Subtotal') }}</div><div class="shopify-value">{{ $order['subtotal'] }}</div></div>
    @endif
    @if ($order['discounts'] !== '')
        <div class="shopify-row"><div class="shopify-label">{{ __('Discounts') }}</div><div class="shopify-value">−{{ $order['discounts'] }}</div></div>
    @endif
    @if ($order['shipping'] !== '')
        <div class="shopify-row"><div class="shopify-label">{{ __('Shipping') }}</div><div class="shopify-value">{{ $order['shipping'] }}</div></div>
    @endif
    @if ($order['tax'] !== '')
        <div class="shopify-row"><div class="shopify-label">{{ __('Tax') }}</div><div class="shopify-value">{{ $order['tax'] }}</div></div>
    @endif
    <div class="shopify-row shopify-total"><div class="shopify-label">{{ __('Total') }}</div><div class="shopify-value">{{ $order['total'] }}</div></div>
    @if ($order['refunded'] !== '')
        <div class="shopify-row"><div class="shopify-label">{{ __('Refunded') }}</div><div class="shopify-value">−{{ $order['refunded'] }}</div></div>
    @endif
</div>

@if ($order['note'] !== '' || count($order['tags']))
    <div class="shopify-section">
        @if ($order['note'] !== '')
            <div class="shopify-section-title">{{ __('Order note') }}</div>
            <div class="shopify-note">{{ $order['note'] }}</div>
        @endif
        @if (count($order['tags']))
            <div class="shopify-tags">
                @foreach ($order['tags'] as $tag)
                    <span class="shopify-tag">{{ $tag }}</span>
                @endforeach
            </div>
        @endif
    </div>
@endif

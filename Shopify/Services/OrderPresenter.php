<?php

namespace Modules\Shopify\Services;

/**
 * Turns raw Shopify GraphQL nodes into plain, display-ready arrays.
 * All text is escaped later by Blade; URLs are checked here (http/https only).
 */
class OrderPresenter
{
    const TONE_SUCCESS = 'success';
    const TONE_INFO    = 'info';
    const TONE_WARNING = 'warning';
    const TONE_DANGER  = 'danger';
    const TONE_NEUTRAL = 'neutral';

    /**
     * @param array  $node   Order node.
     * @param string $handle Store handle (for admin links), e.g. "my-store".
     * @return array
     */
    public static function order(array $node, $handle)
    {
        $legacyId = isset($node['legacyResourceId']) ? preg_replace('/\D/', '', (string) $node['legacyResourceId']) : '';
        $currency = self::currencyOf($node);

        $items = [];
        $lineItems = isset($node['lineItems']['nodes']) && is_array($node['lineItems']['nodes']) ? $node['lineItems']['nodes'] : [];
        foreach ($lineItems as $item) {
            $qty   = isset($item['quantity']) ? (int) $item['quantity'] : 1;
            $money = isset($item['discountedUnitPriceSet']['shopMoney'])
                ? $item['discountedUnitPriceSet']['shopMoney']
                : (isset($item['originalUnitPriceSet']['shopMoney']) ? $item['originalUnitPriceSet']['shopMoney'] : null);

            $variant = isset($item['variantTitle']) ? trim((string) $item['variantTitle']) : '';
            if (strcasecmp($variant, 'Default Title') === 0) {
                $variant = ''; // Shopify's placeholder for single-variant products.
            }

            $items[] = [
                'title'      => isset($item['title']) ? (string) $item['title'] : '',
                'variant'    => $variant,
                'sku'        => isset($item['sku']) ? (string) $item['sku'] : '',
                'quantity'   => $qty,
                'unit_price' => $money ? self::formatMoney($money['amount'], $money['currencyCode']) : '',
                'line_total' => $money ? self::formatMoney((float) $money['amount'] * $qty, $money['currencyCode']) : '',
                'image'      => self::imageUrl(isset($item['image']['url']) ? $item['image']['url'] : ''),
            ];
        }

        $discounts = self::moneyIfPositive($node, 'totalDiscountsSet');
        $tax       = self::moneyIfPositive($node, 'totalTaxSet');
        $refunded  = self::moneyIfPositive($node, 'totalRefundedSet');

        return [
            'id'           => $legacyId,
            'name'         => isset($node['name']) ? (string) $node['name'] : '',
            'created_at'   => self::date(isset($node['createdAt']) ? $node['createdAt'] : null),
            'cancelled'    => !empty($node['cancelledAt']),
            'cancelled_at' => self::date(isset($node['cancelledAt']) ? $node['cancelledAt'] : null),
            'financial'    => self::financialStatus(isset($node['displayFinancialStatus']) ? $node['displayFinancialStatus'] : ''),
            'fulfillment'  => self::fulfillmentStatus(isset($node['displayFulfillmentStatus']) ? $node['displayFulfillmentStatus'] : ''),
            'total'        => self::money($node, 'totalPriceSet'),
            'subtotal'     => self::money($node, 'subtotalPriceSet'),
            'shipping'     => self::money($node, 'totalShippingPriceSet'),
            'discounts'    => $discounts,
            'tax'          => $tax,
            'refunded'     => $refunded,
            'currency'     => $currency,
            'items'        => $items,
            'items_count'  => array_sum(array_map(function ($i) { return $i['quantity']; }, $items)),
            'tracking'     => self::tracking(isset($node['fulfillments']) ? $node['fulfillments'] : []),
            'address'      => self::addressLines(isset($node['shippingAddress']) ? $node['shippingAddress'] : null),
            'note'         => isset($node['note']) ? (string) $node['note'] : '',
            'tags'         => isset($node['tags']) && is_array($node['tags']) ? array_values(array_map('strval', $node['tags'])) : [],
            'source'       => isset($node['sourceName']) ? (string) $node['sourceName'] : '',
            'admin_url'    => $legacyId !== '' ? 'https://admin.shopify.com/store/'.rawurlencode($handle).'/orders/'.$legacyId : '',
            'status_url'   => ShopifyClient::safeUrl(isset($node['statusPageUrl']) ? $node['statusPageUrl'] : ''),
        ];
    }

    /**
     * @param array  $node
     * @param string $handle
     * @return array
     */
    public static function customer(array $node, $handle)
    {
        $legacyId = isset($node['legacyResourceId']) ? preg_replace('/\D/', '', (string) $node['legacyResourceId']) : '';
        $name     = isset($node['displayName']) ? trim((string) $node['displayName']) : '';

        $spent = '';
        if (isset($node['amountSpent']['amount'])) {
            $spent = self::formatMoney($node['amountSpent']['amount'], $node['amountSpent']['currencyCode']);
        }

        return [
            'name'         => $name,
            'email'        => isset($node['email']) ? (string) $node['email'] : '',
            'orders_count' => isset($node['numberOfOrders']) ? (int) $node['numberOfOrders'] : 0,
            'total_spent'  => $spent,
            'since'        => self::date(isset($node['createdAt']) ? $node['createdAt'] : null),
            'tags'         => isset($node['tags']) && is_array($node['tags']) ? array_values(array_map('strval', $node['tags'])) : [],
            'note'         => isset($node['note']) ? (string) $node['note'] : '',
            'admin_url'    => $legacyId !== '' ? 'https://admin.shopify.com/store/'.rawurlencode($handle).'/customers/'.$legacyId : '',
        ];
    }

    // ------------------------------------------------------------------
    // Statuses
    // ------------------------------------------------------------------

    public static function financialStatus($code)
    {
        $code = strtoupper((string) $code);
        $tones = [
            'PAID'               => self::TONE_SUCCESS,
            'PARTIALLY_PAID'     => self::TONE_WARNING,
            'PENDING'            => self::TONE_WARNING,
            'AUTHORIZED'         => self::TONE_INFO,
            'PARTIALLY_REFUNDED' => self::TONE_INFO,
            'REFUNDED'           => self::TONE_NEUTRAL,
            'VOIDED'             => self::TONE_NEUTRAL,
            'EXPIRED'            => self::TONE_DANGER,
        ];

        return self::status($code !== '' ? $code : 'PENDING', $tones);
    }

    public static function fulfillmentStatus($code)
    {
        $code = strtoupper((string) $code);
        $tones = [
            'FULFILLED'           => self::TONE_SUCCESS,
            'DELIVERED'           => self::TONE_SUCCESS,
            'PICKED_UP'           => self::TONE_SUCCESS,
            'PARTIALLY_FULFILLED' => self::TONE_WARNING,
            'IN_PROGRESS'         => self::TONE_INFO,
            'IN_TRANSIT'          => self::TONE_INFO,
            'OUT_FOR_DELIVERY'    => self::TONE_INFO,
            'READY_FOR_PICKUP'    => self::TONE_INFO,
            'CONFIRMED'           => self::TONE_INFO,
            'LABEL_PRINTED'       => self::TONE_INFO,
            'LABEL_PURCHASED'     => self::TONE_INFO,
            'SCHEDULED'           => self::TONE_INFO,
            'ON_HOLD'             => self::TONE_WARNING,
            'DELAYED'             => self::TONE_WARNING,
            'ATTEMPTED_DELIVERY'  => self::TONE_WARNING,
            'NOT_DELIVERED'       => self::TONE_DANGER,
            'FAILURE'             => self::TONE_DANGER,
            'REQUEST_DECLINED'    => self::TONE_DANGER,
            'CANCELED'            => self::TONE_NEUTRAL,
            'RESTOCKED'           => self::TONE_NEUTRAL,
            'UNFULFILLED'         => self::TONE_WARNING,
            'PENDING_FULFILLMENT' => self::TONE_WARNING,
            'OPEN'                => self::TONE_WARNING,
        ];

        return self::status($code !== '' ? $code : 'UNFULFILLED', $tones);
    }

    private static function status($code, array $tones)
    {
        $label = ucfirst(strtolower(str_replace('_', ' ', $code)));

        return [
            'code'  => $code,
            'label' => self::t($label),
            'tone'  => isset($tones[$code]) ? $tones[$code] : self::TONE_NEUTRAL,
        ];
    }

    // ------------------------------------------------------------------
    // Pieces
    // ------------------------------------------------------------------

    /**
     * @param array $fulfillments
     * @return array each: company, number, url, status{label,tone}, delivered_at, eta
     */
    private static function tracking($fulfillments)
    {
        $rows = [];
        if (!is_array($fulfillments)) {
            return $rows;
        }

        foreach ($fulfillments as $f) {
            if (!is_array($f)) {
                continue;
            }
            $status = self::fulfillmentStatus(isset($f['displayStatus']) ? $f['displayStatus'] : 'FULFILLED');
            $delivered = self::date(isset($f['deliveredAt']) ? $f['deliveredAt'] : null);
            $eta       = self::date(isset($f['estimatedDeliveryAt']) ? $f['estimatedDeliveryAt'] : null);

            $infos = isset($f['trackingInfo']) && is_array($f['trackingInfo']) ? $f['trackingInfo'] : [];
            if (!$infos) {
                $infos = [[]];
            }
            foreach ($infos as $info) {
                $rows[] = [
                    'company'      => isset($info['company']) ? (string) $info['company'] : '',
                    'number'       => isset($info['number']) ? (string) $info['number'] : '',
                    'url'          => ShopifyClient::safeUrl(isset($info['url']) ? $info['url'] : ''),
                    'status'       => $status,
                    'delivered_at' => $delivered,
                    'eta'          => $eta,
                ];
            }
        }

        return $rows;
    }

    /**
     * @param array|null $a MailingAddress
     * @return array Lines to print one under the other.
     */
    private static function addressLines($a)
    {
        if (!is_array($a)) {
            return [];
        }
        $get = function ($key) use ($a) {
            return isset($a[$key]) ? trim((string) $a[$key]) : '';
        };

        $region = $get('provinceCode') !== '' ? $get('provinceCode') : $get('province');
        $cityLine = trim($get('zip').' '.$get('city').($region !== '' ? ', '.$region : ''));

        $lines = array_filter([
            $get('name'),
            $get('company'),
            $get('address1'),
            $get('address2'),
            $cityLine,
            $get('country'),
            $get('phone'),
        ], function ($line) {
            return $line !== '';
        });

        return array_values($lines);
    }

    // ------------------------------------------------------------------
    // Formatting
    // ------------------------------------------------------------------

    private static function currencyOf(array $node)
    {
        return isset($node['totalPriceSet']['shopMoney']['currencyCode']) ? (string) $node['totalPriceSet']['shopMoney']['currencyCode'] : '';
    }

    private static function money(array $node, $field)
    {
        if (!isset($node[$field]['shopMoney']['amount'])) {
            return '';
        }

        return self::formatMoney($node[$field]['shopMoney']['amount'], $node[$field]['shopMoney']['currencyCode']);
    }

    private static function moneyIfPositive(array $node, $field)
    {
        if (!isset($node[$field]['shopMoney']['amount']) || (float) $node[$field]['shopMoney']['amount'] <= 0) {
            return '';
        }

        return self::money($node, $field);
    }

    /**
     * @param string|float $amount
     * @param string       $currency ISO 4217
     * @return string
     */
    public static function formatMoney($amount, $currency)
    {
        if ($amount === null || $amount === '') {
            return '';
        }
        $currency = strtoupper(trim((string) $currency));

        if ($currency !== '' && class_exists('NumberFormatter')) {
            $locale = function_exists('app') ? str_replace('_', '-', (string) app()->getLocale()) : 'en';
            $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
            $formatted = $formatter->formatCurrency((float) $amount, $currency);
            if ($formatted !== false) {
                return $formatted;
            }
        }

        return trim($currency.' '.number_format((float) $amount, 2, '.', ' '));
    }

    /**
     * @param string|null $iso
     * @return string
     */
    public static function date($iso)
    {
        if (empty($iso)) {
            return '';
        }

        if (class_exists('App\User') && method_exists('App\User', 'dateFormat')) {
            return (string) \App\User::dateFormat($iso, 'M j, Y');
        }

        $time = strtotime((string) $iso);

        return $time ? date('M j, Y', $time) : '';
    }

    /**
     * Shopify's CDN can resize on the fly: ask for a small version.
     */
    private static function imageUrl($url)
    {
        $url = ShopifyClient::safeUrl($url);
        if ($url === '') {
            return '';
        }
        if (strpos((string) parse_url($url, PHP_URL_HOST), 'cdn.shopify.com') !== false && strpos($url, 'width=') === false) {
            $url .= (strpos($url, '?') === false ? '?' : '&').'width=120';
        }

        return $url;
    }

    private static function t($text)
    {
        return function_exists('__') ? __($text) : $text;
    }
}

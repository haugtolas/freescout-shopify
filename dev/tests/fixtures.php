<?php

/** Shared sample data for the tests. */

function sampleOrder(array $over = [])
{
    $money = function ($a, $c = 'EUR') { return ['shopMoney' => ['amount' => $a, 'currencyCode' => $c]]; };

    return array_merge([
        'id' => 'gid://shopify/Order/450789469', 'legacyResourceId' => '450789469', 'name' => '#1001',
        'createdAt' => '2026-10-01T10:00:00Z', 'cancelledAt' => null,
        'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED',
        'note' => 'Gift <b>wrap</b>', 'tags' => ['vip'], 'sourceName' => 'web',
        'statusPageUrl' => 'https://shop.example/status/abc',
        'totalPriceSet' => $money('120.50'), 'subtotalPriceSet' => $money('130.50'),
        'totalDiscountsSet' => $money('10.00'), 'totalShippingPriceSet' => $money('0.00'),
        'totalTaxSet' => $money('0.00'), 'totalRefundedSet' => $money('0.00'),
        'shippingAddress' => [
            'name' => 'Jane Doe', 'company' => '', 'address1' => '1 rue de Rivoli', 'address2' => '',
            'city' => 'Paris', 'province' => '', 'provinceCode' => '', 'zip' => '75001', 'country' => 'France', 'phone' => '',
        ],
        'fulfillments' => [[
            'displayStatus' => 'DELIVERED', 'deliveredAt' => '2026-10-04T10:00:00Z', 'estimatedDeliveryAt' => null,
            'trackingInfo' => [
                ['company' => 'Colissimo', 'number' => '6A123', 'url' => 'https://track.example/6A123'],
                ['company' => 'Evil', 'number' => '666', 'url' => 'javascript:alert(document.cookie)'],
            ],
        ]],
        'lineItems' => ['nodes' => [
            ['title' => 'Bracelet', 'variantTitle' => 'Default Title', 'sku' => 'BR-1', 'quantity' => 2,
             'image' => ['url' => 'https://cdn.shopify.com/s/files/1/a.jpg?v=1'],
             'originalUnitPriceSet' => $money('70.00'), 'discountedUnitPriceSet' => $money('65.25')],
            ['title' => 'Cord', 'variantTitle' => 'Black / M', 'sku' => null, 'quantity' => 1, 'image' => null,
             'originalUnitPriceSet' => $money('5.00'), 'discountedUnitPriceSet' => $money('5.00')],
        ]],
    ], $over);
}

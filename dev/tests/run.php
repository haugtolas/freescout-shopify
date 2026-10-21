<?php

/**
 * Standalone tests (no FreeScout, no PHPUnit, no network).
 *
 *   php tests/run.php
 *
 * They exercise the Shopify client, the presenter and the customer mapping
 * with a fake HTTP transport.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__.'/../../Shopify/Services/ShopifyException.php';
require __DIR__.'/../../Shopify/Services/TokenStore.php';
require __DIR__.'/../../Shopify/Services/ShopifyClient.php';
require __DIR__.'/../../Shopify/Services/OrderPresenter.php';
require __DIR__.'/../../Shopify/Services/CustomerSync.php';
require __DIR__.'/../../Shopify/Services/ShopifyManager.php';
require __DIR__.'/../../Shopify/Services/WorkflowRules.php';

use Modules\Shopify\Services\CustomerSync;
use Modules\Shopify\Services\OrderPresenter;
use Modules\Shopify\Services\ShopifyClient;
use Modules\Shopify\Services\ShopifyException;
use Modules\Shopify\Services\TokenStore;
use Modules\Shopify\Services\WorkflowRules;

// Translation stub: returns the English text.
function __($text, array $replace = [])
{
    foreach ($replace as $k => $v) {
        $text = str_replace(':'.$k, $v, $text);
    }

    return $text;
}

class ArrayStore implements TokenStore
{
    public $data = [];
    public $ttl = [];
    public function get($key) { return isset($this->data[$key]) ? $this->data[$key] : null; }
    public function put($key, $value, $seconds) { $this->data[$key] = $value; $this->ttl[$key] = $seconds; }
    public function forget($key) { unset($this->data[$key]); }
}

class FakeTransport
{
    public $queue = [];
    public $requests = [];

    public function push($status, $body)
    {
        $this->queue[] = [$status, is_string($body) ? $body : json_encode($body)];

        return $this;
    }

    public function __invoke($method, $url, array $headers, $body)
    {
        $this->requests[] = compact('method', 'url', 'headers', 'body');
        if (!$this->queue) {
            throw new \RuntimeException('Unexpected request to '.$url);
        }

        return array_shift($this->queue);
    }

    public function tokenRequests()
    {
        return array_values(array_filter($this->requests, function ($r) {
            return strpos($r['url'], '/admin/oauth/access_token') !== false;
        }));
    }

    public function graphqlRequests()
    {
        return array_values(array_filter($this->requests, function ($r) {
            return strpos($r['url'], '/graphql.json') !== false;
        }));
    }
}

$passed = 0;
$failed = 0;
$current = '';

function test($name, callable $fn)
{
    global $passed, $failed, $current;
    $current = $name;
    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (\Throwable $e) {
        $failed++;
        echo "  FAIL $name\n       ".$e->getMessage()."\n";
    }
}

function eq($expected, $actual, $label = '')
{
    if ($expected !== $actual) {
        throw new \Exception(($label ? $label.': ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
    }
}

function truthy($value, $label = '')
{
    if (!$value) {
        throw new \Exception(($label ?: 'value').' should be truthy');
    }
}

function throwsType($type, callable $fn)
{
    try {
        $fn();
    } catch (ShopifyException $e) {
        eq($type, $e->type, 'exception type ('.$e->getMessage().')');

        return $e;
    }
    throw new \Exception('Expected a ShopifyException of type '.$type);
}

function client(FakeTransport $t, ArrayStore $s = null, array $over = [])
{
    return new ShopifyClient(array_merge([
        'shop'          => 'my-store.myshopify.com',
        'api_version'   => '2026-10',
        'client_id'     => 'cid123',
        'client_secret' => 'super-secret-value',
        'access_token'  => '',
    ], $over), $s ?: new ArrayStore(), $t);
}

function tokenResponse($token = 'tok1', $expires = 86399)
{
    return ['access_token' => $token, 'scope' => 'read_orders,read_customers', 'expires_in' => $expires];
}

echo "\nShop domain validation\n";

test('accepts the usual forms', function () {
    eq('my-store.myshopify.com', ShopifyClient::normalizeShop('my-store.myshopify.com'));
    eq('my-store.myshopify.com', ShopifyClient::normalizeShop('  HTTPS://My-Store.myshopify.com/admin/orders?x=1  '));
    eq('my-store.myshopify.com', ShopifyClient::normalizeShop('my-store'));
    eq('my-store.myshopify.com', ShopifyClient::normalizeShop('https://admin.shopify.com/store/my-store/orders/123'));
});

test('rejects anything that is not a myshopify.com host (secret exfiltration)', function () {
    foreach ([
        '', 'evil.com', 'my-store.myshopify.com.evil.com', 'evil.com/my-store.myshopify.com',
        'my-store.myshopify.com@evil.com', 'http://169.254.169.254', 'a.b.myshopify.com',
        '-bad.myshopify.com', 'my store.myshopify.com', 'my-store.myshopify.com:8080',
    ] as $input) {
        eq(null, ShopifyClient::normalizeShop($input), $input);
    }

    // A bare word is a shop handle: it can only ever become a *.myshopify.com host.
    eq('localhost.myshopify.com', ShopifyClient::normalizeShop('localhost'));
});

test('constructor refuses an invalid shop, version or missing credentials', function () {
    $t = new FakeTransport();
    throwsType('config', function () use ($t) { client($t, null, ['shop' => 'evil.com']); });
    throwsType('config', function () use ($t) { client($t, null, ['api_version' => '2026-13']); });
    throwsType('config', function () use ($t) { client($t, null, ['client_secret' => '']); });
    eq(0, count($t->requests));
});

test('api versions', function () {
    foreach (['2026-01', '2026-04', '2026-07', '2026-10', '2027-01', 'unstable'] as $v) {
        truthy(ShopifyClient::isValidApiVersion($v), $v);
    }
    foreach (['', '2026-02', 'latest', '2026-1', '../2026-10'] as $v) {
        eq(false, ShopifyClient::isValidApiVersion($v), $v);
    }
});

test('safeUrl only lets http(s) through', function () {
    eq('https://x.com/a', ShopifyClient::safeUrl('https://x.com/a'));
    eq('', ShopifyClient::safeUrl('javascript:alert(1)'));
    eq('', ShopifyClient::safeUrl('JaVaScRiPt:alert(1)'));
    eq('', ShopifyClient::safeUrl('data:text/html;base64,AAAA'));
    eq('', ShopifyClient::safeUrl('//evil.com'));
});

test('search terms are escaped', function () {
    eq('email:"a@b.co"', ShopifyClient::searchTerm('email', 'a@b.co'));
    eq('email:"a\\" OR email:\\"b"', ShopifyClient::searchTerm('email', 'a" OR email:"b'));
    eq('name:"x\\\\y"', ShopifyClient::searchTerm('name', 'x\\y'));
});

echo "\nToken handling\n";

test('client credentials: exchanges, caches and reuses the token', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse('tok1'))->push(200, ['data' => ['a' => 1]])->push(200, ['data' => ['a' => 2]]);
    $store = new ArrayStore();
    $c = client($t, $store);

    eq(['a' => 1], $c->query('{ a }'));
    eq(['a' => 2], $c->query('{ a }'));

    eq(1, count($t->tokenRequests()), 'token requested once');
    $req = $t->tokenRequests()[0];
    eq('POST', $req['method']);
    eq('https://my-store.myshopify.com/admin/oauth/access_token', $req['url']);
    truthy(in_array('Content-Type: application/x-www-form-urlencoded', $req['headers'], true));
    parse_str($req['body'], $form);
    eq('client_credentials', $form['grant_type']);
    eq('cid123', $form['client_id']);
    eq('super-secret-value', $form['client_secret']);

    foreach ($t->graphqlRequests() as $g) {
        truthy(in_array('X-Shopify-Access-Token: tok1', $g['headers'], true));
        eq('https://my-store.myshopify.com/admin/api/2026-10/graphql.json', $g['url']);
    }
});

test('the cached token expires 5 minutes before Shopify says so', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse('tok1', 86399))->push(200, ['data' => []]);
    $store = new ArrayStore();
    client($t, $store)->query('{ a }');
    eq([86399 - 300], array_values($store->ttl));
});

test('a rotated secret never reuses the old token', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse('tokA'))->push(200, ['data' => []])
      ->push(200, tokenResponse('tokB'))->push(200, ['data' => []]);
    $store = new ArrayStore();
    client($t, $store)->query('{ a }');
    client($t, $store, ['client_secret' => 'rotated-secret'])->query('{ a }');
    eq(2, count($t->tokenRequests()));
});

test('a 401 renews the token once and retries', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse('old'))
      ->push(401, ['errors' => 'Invalid API key or access token'])
      ->push(200, tokenResponse('fresh'))
      ->push(200, ['data' => ['ok' => true]]);
    $c = client($t);

    eq(['ok' => true], $c->query('{ a }'));
    eq(2, count($t->tokenRequests()));
    $last = end($t->requests);
    truthy(in_array('X-Shopify-Access-Token: fresh', $last['headers'], true));
});

test('a second 401 gives up with an auth error', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse('a'))->push(401, '{}')->push(200, tokenResponse('b'))->push(401, '{}');
    throwsType('auth', function () use ($t) { client($t)->query('{ a }'); });
});

test('static access token: never requests a token', function () {
    $t = new FakeTransport();
    $t->push(200, ['data' => ['ok' => 1]]);
    $c = client($t, null, ['client_id' => '', 'client_secret' => '', 'access_token' => 'shpat_abc']);
    eq(['ok' => 1], $c->query('{ a }'));
    eq(0, count($t->tokenRequests()));
    truthy(in_array('X-Shopify-Access-Token: shpat_abc', $t->requests[0]['headers'], true));
});

test('static token rejected: no retry loop', function () {
    $t = new FakeTransport();
    $t->push(401, '{}');
    throwsType('auth', function () use ($t) {
        client($t, null, ['client_id' => '', 'client_secret' => '', 'access_token' => 'shpat_abc'])->query('{ a }');
    });
    eq(1, count($t->requests));
});

echo "\nError mapping\n";

test('token endpoint errors', function () use (&$t) {
    $t = new FakeTransport(); $t->push(400, ['error' => 'shop_not_permitted']);
    throwsType('not_permitted', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport(); $t->push(400, ['error' => 'invalid_client']);
    throwsType('auth', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport(); $t->push(404, '');
    throwsType('shop', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport(); $t->push(429, '');
    throwsType('throttled', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport(); $t->push(500, 'oops');
    throwsType('api', function () use ($t) { client($t)->query('{ a }'); });
});

test('graphql errors', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['errors' => [[
        'message' => 'Access denied for orders field. Required access: `read_orders` access scope.',
        'extensions' => ['code' => 'ACCESS_DENIED'],
    ]]]);
    $e = throwsType('scope', function () use ($t) { client($t)->query('{ a }'); });
    truthy(strpos($e->getMessage(), 'read_orders') !== false, 'message mentions the scope');

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]);
    throwsType('throttled', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['errors' => [['message' => 'Field x doesn\'t exist on type Order']]]);
    throwsType('api', function () use ($t) { client($t)->query('{ a }'); });

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(403, ['errors' => 'This action requires merchant approval for read_orders scope.']);
    $e = throwsType('scope', function () use ($t) { client($t)->query('{ a }'); });
    truthy(strpos($e->getMessage(), 'merchant approval') !== false);

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, 'not json');
    throwsType('api', function () use ($t) { client($t)->query('{ a }'); });
});

test('network failure becomes a network error', function () {
    $t = new FakeTransport(); // empty queue: transport throws.
    throwsType('network', function () use ($t) { client($t)->query('{ a }'); });
});

test('a secret echoed back by Shopify never reaches an error message', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['errors' => [['message' => 'bad super-secret-value and tok1']]]);
    $e = throwsType('api', function () use ($t) { client($t)->query('{ a }'); });
    eq(false, strpos($e->getMessage(), 'super-secret-value'));

    $t = new FakeTransport();
    $t->push(403, ['errors' => 'denied shpat_abc']);
    $e = throwsType('scope', function () use ($t) {
        client($t, null, ['client_id' => '', 'client_secret' => '', 'access_token' => 'shpat_abc'])->query('{ a }');
    });
    eq(false, strpos($e->getMessage(), 'shpat_abc'));
});

echo "\nQueries\n";

test('findByEmail returns customer and orders in one call', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => ['customers' => ['nodes' => [[
        'id' => 'gid://shopify/Customer/1', 'displayName' => 'Jane Doe', 'email' => 'Jane@Example.com',
        'orders' => ['nodes' => [['name' => '#1001'], ['name' => '#1000']]],
    ]]]]]);
    $r = client($t)->findByEmail('jane@example.com');
    eq('Jane Doe', $r['customer']['displayName']);
    eq(2, count($r['orders']));
    truthy(!isset($r['customer']['orders']), 'orders removed from customer node');
    eq(1, count($t->graphqlRequests()));
    $vars = json_decode($t->graphqlRequests()[0]['body'], true)['variables'];
    eq('email:"jane@example.com"', $vars['q']);
    eq(5, $vars['n']);
});

test('findByEmail falls back to an order search for guest checkouts', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())
      ->push(200, ['data' => ['customers' => ['nodes' => []]]])
      ->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#1002', 'email' => 'guest@example.com']]]]]);
    $r = client($t)->findByEmail('guest@example.com');
    eq(null, $r['customer']);
    eq('#1002', $r['orders'][0]['name']);
    eq(2, count($t->graphqlRequests()));
});

test('missing read_customers still shows orders, with a notice', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())
      ->push(200, ['errors' => [['message' => 'Access denied for customers field. Required access: `read_customers` access scope.', 'extensions' => ['code' => 'ACCESS_DENIED']]]])
      ->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#1', 'email' => 'a@b.co']]]]]);
    $r = client($t)->findByEmail('a@b.co');
    eq('missing_customers_scope', $r['notice']);
    eq(1, count($r['orders']));
});

test('missing read_orders is not hidden', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())
      ->push(200, ['errors' => [['message' => 'Access denied for orders field. Required access: `read_orders` access scope.', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
    throwsType('scope', function () use ($t) { client($t)->findByEmail('a@b.co'); });
});

test('findOrderByName', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#1234']]]]]);
    eq('#1234', client($t)->findOrderByName('#1234')['name']);
    $vars = json_decode($t->graphqlRequests()[0]['body'], true)['variables'];
    eq('name:"#1234"', $vars['q']);

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => ['orders' => ['nodes' => []]]]);
    eq(null, client($t)->findOrderByName('#9'));
});

test('a fuzzy search hit for another customer is ignored (no data of someone else)', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())
      ->push(200, ['data' => ['customers' => ['nodes' => [
          ['displayName' => 'Mallory', 'email' => 'jane@example.com.evil.io', 'orders' => ['nodes' => [['name' => '#7']]]],
          ['displayName' => 'Jane', 'email' => 'JANE@example.com', 'orders' => ['nodes' => [['name' => '#8']]]],
      ]]]]);
    $r = client($t)->findByEmail('jane@example.com');
    eq('Jane', $r['customer']['displayName']);
    eq('#8', $r['orders'][0]['name']);

    // Only wrong hits: treated as "not found", then orders are filtered the same way.
    $t = new FakeTransport();
    $t->push(200, tokenResponse())
      ->push(200, ['data' => ['customers' => ['nodes' => [['displayName' => 'Mallory', 'email' => 'other@example.com', 'orders' => ['nodes' => []]]]]]])
      ->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#1', 'email' => 'other@example.com'], ['name' => '#2', 'email' => 'jane@example.com'], ['name' => '#3']]]]]);
    $r = client($t)->findByEmail('jane@example.com');
    eq(null, $r['customer']);
    eq(['#2'], array_map(function ($o) { return $o['name']; }, $r['orders']));
});

test('findOrderByName only accepts the exact order name', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#10010'], ['name' => '#1001']]]]]);
    eq('#1001', client($t)->findOrderByName('#1001')['name']);

    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => ['orders' => ['nodes' => [['name' => '#10010']]]]]);
    eq(null, client($t)->findOrderByName('#1001'));
});

test('sameEmail ignores case and spaces but never matches empty', function () {
    eq(true, ShopifyClient::sameEmail(' Jane@Example.COM ', 'jane@example.com'));
    eq(false, ShopifyClient::sameEmail('', ''));
    eq(false, ShopifyClient::sameEmail('a@b.co', 'a@b.com'));
    eq(true, \Modules\Shopify\Services\ShopifyManager::orderBelongsTo(['email' => 'A@B.co'], ['x@y.z', 'a@b.co']));
    eq(false, \Modules\Shopify\Services\ShopifyManager::orderBelongsTo(['email' => 'a@b.co'], ['x@y.z']));
    eq(false, \Modules\Shopify\Services\ShopifyManager::orderBelongsTo([], ['x@y.z']));
});

test('testConnection reports scopes', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => [
        'shop' => ['name' => 'My Store', 'myshopifyDomain' => 'my-store.myshopify.com'],
        'currentAppInstallation' => ['accessScopes' => [['handle' => 'read_orders'], ['handle' => 'read_customers']]],
    ]]);
    $r = client($t)->testConnection();
    eq('My Store', $r['shop_name']);
    truthy($r['has_orders']); truthy($r['has_customers']);
    eq(false, $r['has_all_orders']);
});

test('testConnection ignores a cached token so new scopes show up at once', function () {
    $t = new FakeTransport(); $store = new ArrayStore();
    $scopesReply = function ($handles) {
        return ['data' => ['shop' => ['name' => 'S', 'myshopifyDomain' => 'my-store.myshopify.com'],
            'currentAppInstallation' => ['accessScopes' => array_map(function ($h) { return ['handle' => $h]; }, $handles)]]];
    };
    $t->push(200, tokenResponse('old'))->push(200, $scopesReply(['read_orders']));
    $r1 = client($t, $store)->testConnection();
    eq(false, $r1["has_all_orders"]);
    // The app got a new scope; the old token is still cached for ~24 h.
    $t->push(200, tokenResponse('new'))->push(200, $scopesReply(['read_orders', 'read_all_orders']));
    $r2 = client($t, $store)->testConnection();
    truthy($r2['has_all_orders']);
    eq(2, count($t->tokenRequests()));
});

test('no request ever goes to another host', function () {
    $t = new FakeTransport();
    $t->push(200, tokenResponse())->push(200, ['data' => []]);
    client($t)->query('{ a }');
    foreach ($t->requests as $r) {
        eq('my-store.myshopify.com', parse_url($r['url'], PHP_URL_HOST));
        eq('https', parse_url($r['url'], PHP_URL_SCHEME));
    }
});

echo "\nPresenter\n";

require __DIR__.'/fixtures.php';

test('maps an order', function () {
    $o = OrderPresenter::order(sampleOrder(), 'my-store');
    eq('#1001', $o['name']);
    eq('https://admin.shopify.com/store/my-store/orders/450789469', $o['admin_url']);
    eq('success', $o['financial']['tone']);
    eq('Paid', $o['financial']['label']);
    eq('Fulfilled', $o['fulfillment']['label']);
    eq(3, $o['items_count']);
    eq('', $o['items'][0]['variant'], 'Default Title hidden');
    eq('Black / M', $o['items'][1]['variant']);
    eq('https://cdn.shopify.com/s/files/1/a.jpg?v=1&width=120', $o['items'][0]['image']);
    eq('', $o['items'][1]['image']);
    eq('', $o['refunded'], 'no refund row when nothing was refunded');
    truthy($o['discounts'] !== '', 'discount shown');
    eq(['Jane Doe', '1 rue de Rivoli', '75001 Paris', 'France'], $o['address']);
    eq('https://shop.example/status/abc', $o['status_url']);
});

test('line total uses the discounted price', function () {
    $o = OrderPresenter::order(sampleOrder(), 'my-store');
    truthy(strpos($o['items'][0]['line_total'], '130.50') !== false || strpos($o['items'][0]['line_total'], '130,50') !== false, $o['items'][0]['line_total']);
});

test('dangerous tracking links are dropped but the number stays', function () {
    $o = OrderPresenter::order(sampleOrder(), 'my-store');
    eq('https://track.example/6A123', $o['tracking'][0]['url']);
    eq('666', $o['tracking'][1]['number']);
    eq('', $o['tracking'][1]['url']);
    eq('success', $o['tracking'][0]['status']['tone']);
    truthy($o['tracking'][0]['delivered_at'] !== '');
});

test('handles sparse orders without notices', function () {
    $o = OrderPresenter::order(['name' => '#5'], 'my-store');
    eq('#5', $o['name']);
    eq('', $o['admin_url']);
    eq([], $o['items']);
    eq('Pending', $o['financial']['label']);
    eq('Unfulfilled', $o['fulfillment']['label']);
});

test('refunds and cancellations', function () {
    $o = OrderPresenter::order(sampleOrder([
        'cancelledAt' => '2026-10-02T00:00:00Z',
        'displayFinancialStatus' => 'PARTIALLY_REFUNDED',
        'totalRefundedSet' => ['shopMoney' => ['amount' => '20.00', 'currencyCode' => 'EUR']],
    ]), 'my-store');
    eq(true, $o['cancelled']);
    truthy($o['refunded'] !== '');
    eq('Partially refunded', $o['financial']['label']);
});

test('unknown statuses degrade gracefully', function () {
    $s = OrderPresenter::fulfillmentStatus('SOMETHING_NEW');
    eq('Something new', $s['label']);
    eq('neutral', $s['tone']);
});

test('maps a customer', function () {
    $c = OrderPresenter::customer([
        'legacyResourceId' => '77', 'displayName' => 'Jane Doe', 'email' => 'j@x.co', 'numberOfOrders' => '4',
        'amountSpent' => ['amount' => '480.00', 'currencyCode' => 'EUR'], 'tags' => ['vip'], 'createdAt' => '2025-01-01T00:00:00Z',
    ], 'my-store');
    eq('https://admin.shopify.com/store/my-store/customers/77', $c['admin_url']);
    eq(4, $c['orders_count']);
    eq(['vip'], $c['tags']);
});

echo "\nCustomer sync mapping\n";

class FakeCustomer
{
    public $received;
    public $dirty = true;
    public $phones = [];
    public $saved = false;
    public function setData($data, $replace = true, $save = false) { $this->received = [$data, $replace, $save]; }
    public function isDirty() { return $this->dirty; }
    public function save() { $this->saved = true; }
    public function getPhones() { return $this->phones; }
}

test('maps Shopify fields and only fills empty fields', function () {
    $c = new FakeCustomer();
    $changed = CustomerSync::apply($c, [
        'firstName' => 'Jane', 'lastName' => 'Doe', 'phone' => '+33612345678',
        'defaultAddress' => [
            'company' => 'ACME', 'address1' => '1 rue de Rivoli', 'address2' => 'Apt 4', 'city' => 'Paris',
            'province' => 'IDF', 'zip' => '75001', 'countryCodeV2' => 'FR',
        ],
    ]);
    truthy($changed);
    truthy($c->saved);
    eq(false, $c->received[1], 'replace_data must be false (fill empty only)');
    eq('Jane', $c->received[0]['first_name']);
    eq('1 rue de Rivoli Apt 4', $c->received[0]['address']);
    eq('FR', $c->received[0]['country']);
    eq('+33612345678', $c->received[0]['phone']);
});

test('does not add a second phone number', function () {
    $c = new FakeCustomer();
    $c->phones = [['value' => '0102030405']];
    CustomerSync::apply($c, ['firstName' => 'Jane', 'phone' => '+33612345678']);
    truthy(!isset($c->received[0]['phone']));
});

test('does not save when nothing changed, nor when Shopify has nothing', function () {
    $c = new FakeCustomer(); $c->dirty = false;
    eq(false, CustomerSync::apply($c, ['firstName' => 'Jane']));
    eq(false, $c->saved);

    $c = new FakeCustomer();
    eq(false, CustomerSync::apply($c, []));
    eq(null, $c->received);
});

test('truncates values to the column sizes', function () {
    $d = CustomerSync::dataFromShopify([
        'defaultAddress' => ['zip' => '12345678901234567890', 'countryCodeV2' => 'FRA'],
    ]);
    eq(12, strlen($d['zip']));
    eq('FR', $d['country']);
});

echo "\nWorkflows and Tags\n";

function wfOverview(array $customer = null, array $orders = [])
{
    return ['customer' => $customer, 'orders' => $orders];
}

test('adds the conditions and actions without touching the existing ones', function () {
    $c = WorkflowRules::addConditions(['conversation' => ['title' => 'Conversation', 'items' => ['status' => ['title' => 'Status']]]]);
    truthy(isset($c['conversation']['items']['status']));
    foreach (['shopify_customer', 'shopify_customer_tag', 'shopify_orders_count', 'shopify_total_spent', 'shopify_payment_status', 'shopify_fulfillment_status'] as $k) {
        truthy(isset($c['conversation']['items'][$k]), $k);
        truthy(in_array('conversation.created_by_customer', $c['conversation']['items'][$k]['triggers'], true), $k);
    }
    eq('Paid', $c['conversation']['items']['shopify_payment_status']['values']['PAID']);
    $a = WorkflowRules::addActions(['dummy' => ['items' => ['note' => ['title' => 'Note']]]], false);
    truthy(isset($a['dummy']['items']['note']) && isset($a['dummy']['items']['shopify_note']));
    eq(false, isset($a['dummy']['items']['shopify_copy_tags']));
    $a = WorkflowRules::addActions([], true);
    truthy(isset($a['dummy']['items']['shopify_copy_tags']));
    eq(true, WorkflowRules::isShopifyType('shopify_customer'));
    eq(false, WorkflowRules::isShopifyType('tag'));
    eq(false, WorkflowRules::isShopifyType(null));
});

test('customer exists / does not exist', function () {
    $o = wfOverview(['displayName' => 'Jane']);
    eq(true, WorkflowRules::evaluate('shopify_customer', 'yes', [], $o));
    eq(false, WorkflowRules::evaluate('shopify_customer', 'no', [], $o));
    eq(false, WorkflowRules::evaluate('shopify_customer', 'yes', [], wfOverview()));
    eq(true, WorkflowRules::evaluate('shopify_customer', 'no', [], wfOverview()));
});

test('customer tags follow the same rules as the Tags module', function () {
    $o = wfOverview(['tags' => ['VIP', 'wholesale-eu']]);
    eq(true, WorkflowRules::evaluate('shopify_customer_tag', 'equal', 'vip', $o));
    eq(false, WorkflowRules::evaluate('shopify_customer_tag', 'equal', 'wholesale', $o));
    eq(true, WorkflowRules::evaluate('shopify_customer_tag', 'contains', 'wholesale', $o));
    eq(true, WorkflowRules::evaluate('shopify_customer_tag', 'contains', 'gold, vip', $o));   // any of them
    eq(false, WorkflowRules::evaluate('shopify_customer_tag', 'contains', 'gold, silver', $o));
    eq(true, WorkflowRules::evaluate('shopify_customer_tag', 'not_contains', 'gold, silver', $o));
    eq(false, WorkflowRules::evaluate('shopify_customer_tag', 'not_contains', 'gold, vip', $o)); // all must be absent
    eq(false, WorkflowRules::evaluate('shopify_customer_tag', 'contains', '', $o));
    eq(true, WorkflowRules::evaluate('shopify_customer_tag', 'not_contains', 'vip', wfOverview()));
});

test('number of orders and total spent', function () {
    $o = wfOverview(['numberOfOrders' => '4', 'amountSpent' => ['amount' => '480.00', 'currencyCode' => 'EUR']]);
    eq(true, WorkflowRules::evaluate('shopify_orders_count', 'greater', '3', $o));
    eq(false, WorkflowRules::evaluate('shopify_orders_count', 'greater', '4', $o));
    eq(true, WorkflowRules::evaluate('shopify_orders_count', 'equal', '4', $o));
    eq(true, WorkflowRules::evaluate('shopify_orders_count', 'less', '5', $o));
    eq(false, WorkflowRules::evaluate('shopify_orders_count', 'greater', 'abc', $o));
    eq(true, WorkflowRules::evaluate('shopify_total_spent', 'greater', '100,5', $o));
    eq(false, WorkflowRules::evaluate('shopify_total_spent', 'less', '100', $o));
    eq(false, WorkflowRules::evaluate('shopify_total_spent', 'greater', '1', wfOverview()));
    // No customer profile but orders found by email: count those.
    eq(true, WorkflowRules::evaluate('shopify_orders_count', 'equal', '2', wfOverview(null, [['name' => '#1'], ['name' => '#2']])));
    eq(true, WorkflowRules::evaluate('shopify_orders_count', 'equal', '0', wfOverview()));
});

test('status of the latest order', function () {
    $o = wfOverview(null, [['displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'UNFULFILLED'], ['displayFinancialStatus' => 'REFUNDED', 'displayFulfillmentStatus' => 'FULFILLED']]);
    eq(true, WorkflowRules::evaluate('shopify_payment_status', 'equal', 'PAID', $o));
    eq(false, WorkflowRules::evaluate('shopify_payment_status', 'not_equal', 'PAID', $o));
    eq(true, WorkflowRules::evaluate('shopify_fulfillment_status', 'equal', 'UNFULFILLED', $o));
    eq(true, WorkflowRules::evaluate('shopify_fulfillment_status', 'not_equal', 'FULFILLED', $o));
    eq(false, WorkflowRules::evaluate('shopify_payment_status', 'equal', 'PAID', wfOverview()));
    eq(false, WorkflowRules::evaluate('shopify_payment_status', 'not_equal', 'PAID', wfOverview()));
    eq(false, WorkflowRules::evaluate('shopify_unknown', 'equal', 'x', $o));
});

test('tags to copy are cleaned and limited', function () {
    $tags = WorkflowRules::tagsToCopy(wfOverview(['tags' => ['VIP', 'vip', ' <b>x</b> ', '', str_repeat('a', 101)]]));
    eq(['VIP', 'vip', 'x'], $tags);
    eq([], WorkflowRules::tagsToCopy(wfOverview()));
    eq(20, count(WorkflowRules::tagsToCopy(wfOverview(['tags' => array_map(function ($i) { return 't'.$i; }, range(1, 40))]))));
});

test('the note lists customer and orders and escapes everything', function () {
    $settings = ['handle' => 'my-store'];
    $html = WorkflowRules::noteHtml($settings, wfOverview(
        ['displayName' => '<script>alert(1)</script>', 'email' => 'a@b.c', 'legacyResourceId' => '7', 'numberOfOrders' => '2', 'tags' => ['<i>vip</i>']],
        [sampleOrder(['name' => '#9"><img src=x onerror=alert(2)>'])]
    ));
    truthy(strpos($html, '<script') === false && strpos($html, '<img') === false && strpos($html, '<i>vip') === false, $html);
    truthy(strpos($html, 'https://admin.shopify.com/store/my-store/customers/7') !== false);
    truthy(strpos($html, 'Paid') !== false);
    eq('', WorkflowRules::noteHtml($settings, wfOverview()));
});

echo "\n";
echo $failed === 0 ? "All $passed tests passed.\n" : "$failed FAILED, $passed passed.\n";
exit($failed === 0 ? 0 : 1);

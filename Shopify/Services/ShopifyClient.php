<?php

namespace Modules\Shopify\Services;

/**
 * Minimal Shopify Admin GraphQL client.
 *
 * Authentication, in order of preference:
 *  - client credentials grant (apps created in the Shopify Dev Dashboard):
 *    Client ID + Client secret are exchanged for a 24h token that is cached
 *    and renewed automatically;
 *  - a static Admin API access token ("shpat_...") for legacy custom apps
 *    created before 2026-01-01 that still work.
 *
 * Security notes:
 *  - the shop must be a *.myshopify.com host: credentials are never sent
 *    anywhere else;
 *  - redirects are never followed and only HTTPS is allowed, so the token
 *    cannot be forwarded to another host;
 *  - exception messages never contain credentials or customer data.
 *
 * Compatible with PHP 7.1 (FreeScout minimum).
 */
class ShopifyClient
{
    /** Renew a cached token this many seconds before it expires. */
    const TOKEN_SAFETY_MARGIN = 300;

    /** Search results are limited to this many orders. */
    const MAX_ORDERS = 5;

    const ORDER_FIELDS = '
        id legacyResourceId name createdAt cancelledAt cancelReason
        displayFinancialStatus displayFulfillmentStatus returnStatus
        email note tags sourceName statusPageUrl
        totalPriceSet { shopMoney { amount currencyCode } }
        subtotalPriceSet { shopMoney { amount currencyCode } }
        totalDiscountsSet { shopMoney { amount currencyCode } }
        totalShippingPriceSet { shopMoney { amount currencyCode } }
        totalTaxSet { shopMoney { amount currencyCode } }
        totalRefundedSet { shopMoney { amount currencyCode } }
        shippingAddress { name company address1 address2 city province provinceCode zip country countryCodeV2 phone }
        fulfillments(first: 10) {
            displayStatus deliveredAt estimatedDeliveryAt
            trackingInfo { company number url }
        }
        lineItems(first: 30) {
            nodes {
                title variantTitle sku quantity
                image { url }
                originalUnitPriceSet { shopMoney { amount currencyCode } }
                discountedUnitPriceSet { shopMoney { amount currencyCode } }
            }
        }';

    const CUSTOMER_FIELDS = '
        id legacyResourceId displayName firstName lastName email phone
        numberOfOrders createdAt tags note
        amountSpent { amount currencyCode }
        defaultAddress { company address1 address2 city province provinceCode zip countryCodeV2 phone }';

    /** @var string */
    private $shop;
    /** @var string */
    private $apiVersion;
    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $accessToken;
    /** @var TokenStore */
    private $store;
    /** @var callable */
    private $transport;

    /**
     * @param array      $config    shop, api_version, client_id, client_secret, access_token
     * @param TokenStore $store
     * @param callable   $transport function($method, $url, array $headers, $body): array [int $status, string $body]
     *
     * @throws ShopifyException
     */
    public function __construct(array $config, TokenStore $store, $transport = null)
    {
        $shop = self::normalizeShop(isset($config['shop']) ? $config['shop'] : '');
        if (!$shop) {
            throw new ShopifyException('Invalid shop domain, expected something like my-store.myshopify.com', ShopifyException::TYPE_CONFIG);
        }

        $version = isset($config['api_version']) ? trim($config['api_version']) : '';
        if (!self::isValidApiVersion($version)) {
            throw new ShopifyException('Invalid API version, expected something like 2026-10', ShopifyException::TYPE_CONFIG);
        }

        $this->shop         = $shop;
        $this->apiVersion   = $version;
        $this->clientId     = isset($config['client_id']) ? trim($config['client_id']) : '';
        $this->clientSecret = isset($config['client_secret']) ? trim($config['client_secret']) : '';
        $this->accessToken  = isset($config['access_token']) ? trim($config['access_token']) : '';

        if ($this->accessToken === '' && ($this->clientId === '' || $this->clientSecret === '')) {
            throw new ShopifyException('Missing credentials: provide a Client ID and Client secret (or an Admin API access token)', ShopifyException::TYPE_CONFIG);
        }

        $this->store     = $store;
        $this->transport = $transport ?: self::curlTransport();
    }

    // ------------------------------------------------------------------
    // Validation helpers
    // ------------------------------------------------------------------

    /**
     * Turn whatever the admin typed into a canonical "handle.myshopify.com",
     * or return null if it is not a valid Shopify shop domain.
     *
     * Accepts: "handle", "handle.myshopify.com", "https://handle.myshopify.com/admin",
     * "https://admin.shopify.com/store/handle/orders".
     *
     * @param string $input
     * @return string|null
     */
    public static function normalizeShop($input)
    {
        $input = strtolower(trim((string) $input));
        if ($input === '') {
            return null;
        }

        // Admin URL: admin.shopify.com/store/{handle}
        if (preg_match('#^(?:https?://)?admin\.shopify\.com/store/([a-z0-9][a-z0-9-]*)(?:[/?\#].*)?$#', $input, $m)) {
            $input = $m[1].'.myshopify.com';
        }

        $input = preg_replace('#^https?://#', '', $input);
        $input = preg_replace('#[/?\#].*$#', '', $input);

        if (strpos($input, '.') === false) {
            $input .= '.myshopify.com';
        }

        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,58}[a-z0-9])?\.myshopify\.com$/', $input)) {
            return null;
        }

        return $input;
    }

    /**
     * @param string $version
     * @return bool
     */
    public static function isValidApiVersion($version)
    {
        return (bool) preg_match('/^(?:\d{4}-(?:01|04|07|10)|unstable)$/', (string) $version);
    }

    /**
     * Build a Shopify search term: field:"value" with the value escaped.
     *
     * @param string $field
     * @param string $value
     * @return string
     */
    public static function searchTerm($field, $value)
    {
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], trim((string) $value));

        return $field.':"'.$value.'"';
    }

    /**
     * Case-insensitive comparison of two email addresses.
     */
    public static function sameEmail($a, $b)
    {
        $a = strtolower(trim((string) $a));

        return $a !== '' && $a === strtolower(trim((string) $b));
    }

    /**
     * Only http(s) links may be rendered in the UI (blocks javascript: URLs).
     *
     * @param string $url
     * @return string Empty string if the URL is not safe.
     */
    public static function safeUrl($url)
    {
        $url = trim((string) $url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function getShop()
    {
        return $this->shop;
    }

    /** "my-store" from "my-store.myshopify.com" (used in admin URLs). */
    public function getHandle()
    {
        return preg_replace('/\.myshopify\.com$/', '', $this->shop);
    }

    public function usesClientCredentials()
    {
        return $this->accessToken === '';
    }

    // ------------------------------------------------------------------
    // Authentication
    // ------------------------------------------------------------------

    /**
     * Return a valid access token, requesting a new one if needed.
     *
     * @param bool $forceRefresh Ignore the cached token.
     * @return string
     *
     * @throws ShopifyException
     */
    public function getAccessToken($forceRefresh = false)
    {
        if ($this->accessToken !== '') {
            return $this->accessToken;
        }

        $key = $this->tokenCacheKey();

        if (!$forceRefresh) {
            $cached = $this->store->get($key);
            if (is_array($cached) && !empty($cached['token'])) {
                return $cached['token'];
            }
        }

        $token = $this->requestToken();

        $ttl = max(60, $token['expires_in'] - self::TOKEN_SAFETY_MARGIN);
        $this->store->put($key, $token, $ttl);

        return $token['token'];
    }

    public function forgetToken()
    {
        $this->store->forget($this->tokenCacheKey());
    }

    /**
     * The key changes when the secret is rotated, so an old token is never reused.
     */
    private function tokenCacheKey()
    {
        return 'shopify:token:'.sha1($this->shop.'|'.$this->clientId.'|'.$this->clientSecret);
    }

    /**
     * Client credentials grant.
     * https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/client-credentials-grant
     *
     * @return array token, scope, expires_in
     * @throws ShopifyException
     */
    private function requestToken()
    {
        list($status, $body) = $this->send(
            'POST',
            'https://'.$this->shop.'/admin/oauth/access_token',
            ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
            http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ])
        );

        $data = json_decode($body, true);
        $data = is_array($data) ? $data : [];

        if ($status === 200 && !empty($data['access_token'])) {
            return [
                'token'      => (string) $data['access_token'],
                'scope'      => isset($data['scope']) ? (string) $data['scope'] : '',
                'expires_in' => isset($data['expires_in']) ? (int) $data['expires_in'] : 86399,
            ];
        }

        $error = isset($data['error']) && is_string($data['error']) ? $data['error'] : '';

        if ($error === 'shop_not_permitted') {
            throw new ShopifyException(
                'Client credentials cannot be used on this shop: the app and the store must belong to the same Shopify organization.',
                ShopifyException::TYPE_NOT_PERMITTED,
                $status
            );
        }
        if ($status === 404) {
            throw new ShopifyException('Shop not found.', ShopifyException::TYPE_SHOP, $status);
        }
        if ($status === 429) {
            throw new ShopifyException('Shopify is rate limiting token requests.', ShopifyException::TYPE_THROTTLED, $status);
        }
        if (in_array($status, [400, 401, 403], true)) {
            throw new ShopifyException(
                'Shopify refused the Client ID / Client secret'.($error !== '' ? ' ('.$this->clean($error).')' : '').'.',
                ShopifyException::TYPE_AUTH,
                $status
            );
        }

        throw new ShopifyException('Unexpected response while requesting a token (HTTP '.$status.').', ShopifyException::TYPE_API, $status);
    }

    // ------------------------------------------------------------------
    // GraphQL
    // ------------------------------------------------------------------

    /**
     * Run a GraphQL query and return its `data`.
     *
     * A rejected token (401) is renewed once and the request retried.
     *
     * @param string $query
     * @param array  $variables
     * @return array
     *
     * @throws ShopifyException
     */
    public function query($query, array $variables = [])
    {
        $payload = json_encode([
            'query'     => $query,
            'variables' => (object) $variables,
        ]);

        $retried = false;

        while (true) {
            $token = $this->getAccessToken($retried);

            list($status, $body) = $this->send(
                'POST',
                'https://'.$this->shop.'/admin/api/'.$this->apiVersion.'/graphql.json',
                [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'X-Shopify-Access-Token: '.$token,
                ],
                $payload
            );

            if ($status === 401 && $this->usesClientCredentials() && !$retried) {
                $retried = true;
                $this->forgetToken();
                continue;
            }

            return $this->parseResponse($status, $body);
        }
    }

    /**
     * @param int    $status
     * @param string $body
     * @return array
     * @throws ShopifyException
     */
    private function parseResponse($status, $body)
    {
        if ($status === 401) {
            throw new ShopifyException(
                $this->usesClientCredentials()
                    ? 'Shopify rejected the token even after renewing it. Check the Client ID and Client secret.'
                    : 'Shopify rejected the Admin API access token.',
                ShopifyException::TYPE_AUTH,
                401
            );
        }
        if ($status === 403) {
            throw new ShopifyException('Access denied'.$this->detail($body).'.', ShopifyException::TYPE_SCOPE, 403);
        }
        if ($status === 404) {
            throw new ShopifyException('Shop or API version not found.', ShopifyException::TYPE_SHOP, 404);
        }
        if ($status === 429) {
            throw new ShopifyException('Shopify API rate limit exceeded.', ShopifyException::TYPE_THROTTLED, 429);
        }
        if ($status !== 200) {
            throw new ShopifyException('Shopify returned HTTP '.$status.'.', ShopifyException::TYPE_API, $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ShopifyException('Shopify returned an invalid response.', ShopifyException::TYPE_API, $status);
        }

        if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
            $messages = [];
            foreach ($decoded['errors'] as $error) {
                $code = is_array($error) && isset($error['extensions']['code']) ? $error['extensions']['code'] : '';
                $text = is_array($error) && isset($error['message']) ? (string) $error['message'] : (is_string($error) ? $error : '');

                if ($code === 'THROTTLED') {
                    throw new ShopifyException('Shopify API rate limit exceeded.', ShopifyException::TYPE_THROTTLED, 200);
                }
                if ($code === 'ACCESS_DENIED') {
                    throw new ShopifyException($this->clean($text !== '' ? $text : 'Access denied'), ShopifyException::TYPE_SCOPE, 200);
                }
                if ($text !== '') {
                    $messages[] = $this->clean($text);
                }
            }
            throw new ShopifyException(
                $messages ? implode(' | ', array_slice($messages, 0, 3)) : 'The Shopify API returned an error.',
                ShopifyException::TYPE_API,
                200
            );
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) {
            throw new ShopifyException('Shopify returned no data.', ShopifyException::TYPE_API, $status);
        }

        return $decoded['data'];
    }

    // ------------------------------------------------------------------
    // High level queries
    // ------------------------------------------------------------------

    /**
     * Check credentials and report which scopes the token has.
     *
     * @return array shop_name, domain, scopes[], has_orders, has_customers, has_all_orders
     * @throws ShopifyException
     */
    public function testConnection()
    {
        // Always use a brand new token: the granted scopes are fixed when a token
        // is issued, so a cached token (valid up to 24 h) would keep reporting the
        // permissions the app had before it was updated.
        $this->forgetToken();

        $data = $this->query('{ shop { name myshopifyDomain } currentAppInstallation { accessScopes { handle } } }');

        $scopes = [];
        if (isset($data['currentAppInstallation']['accessScopes']) && is_array($data['currentAppInstallation']['accessScopes'])) {
            foreach ($data['currentAppInstallation']['accessScopes'] as $scope) {
                if (!empty($scope['handle'])) {
                    $scopes[] = (string) $scope['handle'];
                }
            }
        }

        return [
            'shop_name'      => isset($data['shop']['name']) ? (string) $data['shop']['name'] : '',
            'domain'         => isset($data['shop']['myshopifyDomain']) ? (string) $data['shop']['myshopifyDomain'] : $this->shop,
            'scopes'         => $scopes,
            'has_orders'     => in_array('read_orders', $scopes, true) || in_array('write_orders', $scopes, true),
            'has_customers'  => in_array('read_customers', $scopes, true) || in_array('write_customers', $scopes, true),
            'has_all_orders' => in_array('read_all_orders', $scopes, true),
        ];
    }

    /**
     * Find the Shopify customer with this email and their latest orders.
     * Falls back to a plain order search when the customer has no profile
     * (or when the app lacks the read_customers scope).
     *
     * @param string $email
     * @param int    $limit
     * @return array customer (array|null), orders (array), notice (string)
     * @throws ShopifyException
     */
    public function findByEmail($email, $limit = self::MAX_ORDERS)
    {
        $limit = max(1, min(10, (int) $limit));
        $notice = '';

        try {
            $data = $this->query(
                'query ($q: String!, $n: Int!) {
                    customers(first: 1, query: $q) {
                        nodes {'.self::CUSTOMER_FIELDS.'
                            orders(first: $n, sortKey: CREATED_AT, reverse: true) {
                                nodes {'.self::ORDER_FIELDS.'}
                            }
                        }
                    }
                }',
                ['q' => self::searchTerm('email', $email), 'n' => $limit]
            );

            // Never trust a fuzzy search hit: the address must be exactly the one we asked for,
            // otherwise another person's data could be shown or copied into the FreeScout customer.
            $customer = null;
            if (isset($data['customers']['nodes']) && is_array($data['customers']['nodes'])) {
                foreach ($data['customers']['nodes'] as $node) {
                    if (is_array($node) && self::sameEmail(isset($node['email']) ? $node['email'] : '', $email)) {
                        $customer = $node;
                        break;
                    }
                }
            }
            if ($customer) {
                $orders = isset($customer['orders']['nodes']) ? $customer['orders']['nodes'] : [];
                unset($customer['orders']);

                return ['customer' => $customer, 'orders' => $orders, 'notice' => ''];
            }
        } catch (ShopifyException $e) {
            // Only the missing read_customers scope is tolerated: orders can still be shown.
            if ($e->type !== ShopifyException::TYPE_SCOPE || stripos($e->getMessage(), 'customer') === false) {
                throw $e;
            }
            $notice = 'missing_customers_scope';
        }

        $data = $this->query(
            'query ($q: String!, $n: Int!) {
                orders(first: $n, query: $q, sortKey: CREATED_AT, reverse: true) {
                    nodes {'.self::ORDER_FIELDS.'}
                }
            }',
            ['q' => self::searchTerm('email', $email), 'n' => $limit]
        );

        $orders = [];
        if (isset($data['orders']['nodes']) && is_array($data['orders']['nodes'])) {
            foreach ($data['orders']['nodes'] as $node) {
                if (is_array($node) && self::sameEmail(isset($node['email']) ? $node['email'] : '', $email)) {
                    $orders[] = $node;
                }
            }
        }

        return ['customer' => null, 'orders' => $orders, 'notice' => $notice];
    }

    /**
     * Find one order by its name (e.g. "#1001").
     *
     * @param string $name
     * @return array|null
     * @throws ShopifyException
     */
    public function findOrderByName($name)
    {
        $data = $this->query(
            'query ($q: String!) {
                orders(first: 3, query: $q) {
                    nodes {'.self::ORDER_FIELDS.'}
                }
            }',
            ['q' => self::searchTerm('name', $name)]
        );

        // The search may be fuzzy: only an order with exactly this name is accepted.
        if (isset($data['orders']['nodes']) && is_array($data['orders']['nodes'])) {
            foreach ($data['orders']['nodes'] as $node) {
                if (is_array($node) && isset($node['name']) && strcasecmp(trim((string) $node['name']), trim((string) $name)) === 0) {
                    return $node;
                }
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Transport
    // ------------------------------------------------------------------

    /**
     * @return array [int $status, string $body]
     * @throws ShopifyException
     */
    private function send($method, $url, array $headers, $body)
    {
        try {
            $result = call_user_func($this->transport, $method, $url, $headers, $body);
        } catch (\Exception $e) {
            throw new ShopifyException('Could not reach Shopify: '.$this->clean($e->getMessage()), ShopifyException::TYPE_NETWORK);
        }

        if (!is_array($result) || count($result) < 2) {
            throw new ShopifyException('Could not reach Shopify.', ShopifyException::TYPE_NETWORK);
        }

        return [(int) $result[0], (string) $result[1]];
    }

    /**
     * Default transport: cURL, HTTPS only, no redirects.
     *
     * @return \Closure
     */
    public static function curlTransport()
    {
        return function ($method, $url, array $headers, $body) {
            $ch = curl_init($url);

            if (class_exists('Helper') && method_exists('Helper', 'setCurlDefaultOptions')) {
                \Helper::setCurlDefaultOptions($ch); // timeouts, proxy, TLS settings of FreeScout.
            } else {
                curl_setopt($ch, CURLOPT_TIMEOUT, 20);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            }

            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_USERAGENT, 'FreeScout-Shopify');
            if (defined('CURLOPT_PROTOCOLS')) {
                curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS);
            }
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }

            $response = curl_exec($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error    = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException($error !== '' ? $error : 'cURL error');
            }

            return [$status, (string) $response];
        };
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Extract a short, safe message from an error body.
     */
    private function detail($body)
    {
        $data = json_decode($body, true);
        $text = '';
        if (is_array($data)) {
            if (isset($data['errors']) && is_string($data['errors'])) {
                $text = $data['errors'];
            } elseif (isset($data['errors'][0]['message'])) {
                $text = (string) $data['errors'][0]['message'];
            } elseif (isset($data['error_description'])) {
                $text = (string) $data['error_description'];
            }
        }

        return $text !== '' ? ': '.$this->clean($text) : '';
    }

    /**
     * Keep messages short and make sure no secret ever slips into them.
     */
    private function clean($text)
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $text));

        foreach ([$this->clientSecret, $this->accessToken] as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[hidden]', $text);
            }
        }

        return function_exists('mb_substr') ? mb_substr($text, 0, 300) : substr($text, 0, 300);
    }
}

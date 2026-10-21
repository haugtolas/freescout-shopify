<?php

namespace Modules\Shopify\Services;

/**
 * Glue between FreeScout (settings, cache, translations) and the Shopify client.
 */
class ShopifyManager
{
    const DEFAULT_API_VERSION = '2026-10';
    const MASK                = '********';
    const CACHE_MINUTES       = 60;
    const EMPTY_CACHE_MINUTES = 5;
    const MAX_EMAILS          = 5;

    // ------------------------------------------------------------------
    // Secrets
    // ------------------------------------------------------------------

    /**
     * Read a stored secret: encrypted values are decrypted, plain values
     * (for example typed in .env by hand) are returned as they are.
     *
     * @param mixed $value
     * @return string
     */
    public static function secret($value)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            return '';
        }
        try {
            $plain = decrypt($value);

            return is_string($plain) ? $plain : $value;
        } catch (\Exception $e) {
            return $value;
        }
    }

    public static function encryptSecret($plain)
    {
        $plain = trim((string) $plain);

        return $plain === '' ? '' : encrypt($plain);
    }

    /** What to display in a password field instead of the real secret. */
    public static function mask($stored)
    {
        return self::secret($stored) !== '' ? self::MASK : '';
    }

    public static function isMask($value)
    {
        return is_string($value) && preg_match('/^\*+$/', $value) === 1;
    }

    /**
     * Value to store for a secret field submitted from a form.
     *
     * @param string $submitted Value posted by the form.
     * @param string $stored    Currently stored (encrypted) value.
     * @return string
     */
    public static function secretFromForm($submitted, $stored)
    {
        $submitted = trim((string) $submitted);
        if (self::isMask($submitted)) {
            return (string) $stored; // untouched.
        }

        return self::encryptSecret($submitted);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * Global settings, as saved in .env / config.
     *
     * @return array
     */
    public static function globalRaw()
    {
        return [
            'shop_domain'    => config('shopify.shop_domain'),
            'client_id'      => config('shopify.client_id'),
            'client_secret'  => config('shopify.client_secret'),
            'access_token'   => config('shopify.access_token'),
            'api_version'    => config('shopify.api_version'),
            'sync_customers' => config('shopify.sync_customers'),
            'link_orders'    => config('shopify.link_orders'),
            'restrict_orders' => config('shopify.restrict_orders'),
            'order_prefix'   => config('shopify.order_prefix'),
        ];
    }

    /**
     * Settings stored on a mailbox (JSON column), secrets still encrypted.
     *
     * @param \App\Mailbox|null $mailbox
     * @return array
     */
    public static function mailboxRaw($mailbox)
    {
        if (empty($mailbox) || empty($mailbox->shopify)) {
            return [];
        }
        $data = json_decode($mailbox->shopify, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Validate raw settings and return the usable version, or null if the
     * connection is not completely configured.
     *
     * @param array  $raw
     * @param string $scope   "global" or "mailbox"
     * @param int    $scopeId
     * @return array|null
     */
    public static function resolve(array $raw, $scope = 'global', $scopeId = 0)
    {
        $shop = ShopifyClient::normalizeShop(isset($raw['shop_domain']) ? $raw['shop_domain'] : '');
        if (!$shop) {
            return null;
        }

        $clientId     = trim((string) (isset($raw['client_id']) ? $raw['client_id'] : ''));
        $clientSecret = self::secret(isset($raw['client_secret']) ? $raw['client_secret'] : '');
        $token        = self::secret(isset($raw['access_token']) ? $raw['access_token'] : '');

        if ($token === '' && ($clientId === '' || $clientSecret === '')) {
            return null;
        }

        $version = trim((string) (isset($raw['api_version']) ? $raw['api_version'] : ''));
        if (!ShopifyClient::isValidApiVersion($version)) {
            $version = self::DEFAULT_API_VERSION;
        }

        return [
            'shop'           => $shop,
            'handle'         => preg_replace('/\.myshopify\.com$/', '', $shop),
            'client_id'      => $clientId,
            'client_secret'  => $clientSecret,
            'access_token'   => $token,
            'api_version'    => $version,
            'sync_customers' => self::bool(isset($raw['sync_customers']) ? $raw['sync_customers'] : null, true),
            'link_orders'    => self::bool(isset($raw['link_orders']) ? $raw['link_orders'] : null, true),
            'restrict_orders' => self::bool(isset($raw['restrict_orders']) ? $raw['restrict_orders'] : null, false),
            'order_prefix'   => self::prefix(isset($raw['order_prefix']) ? $raw['order_prefix'] : null),
            'scope'          => $scope,
            'key'            => $scope.':'.$scopeId,
        ];
    }

    /**
     * Settings to use for a mailbox: its own if complete, otherwise the global ones.
     *
     * @param \App\Mailbox|null $mailbox
     * @return array|null
     */
    public static function settingsFor($mailbox = null)
    {
        if ($mailbox) {
            $own = self::resolve(self::mailboxRaw($mailbox), 'mailbox', $mailbox->id);
            if ($own) {
                return $own;
            }
        }

        return self::resolve(self::globalRaw(), 'global', 0);
    }

    public static function isConfigured($mailbox = null)
    {
        return self::settingsFor($mailbox) !== null;
    }

    public static function bool($value, $default)
    {
        if ($value === null) {
            return (bool) $default;
        }
        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Order name prefix, "#" by default. Can be empty or something like "SHOP-".
     */
    public static function prefix($value)
    {
        if ($value === null) {
            return '#';
        }
        $value = preg_replace('/[^A-Za-z0-9#_.\-]/', '', (string) $value);

        return substr($value, 0, 12);
    }

    // ------------------------------------------------------------------
    // Data
    // ------------------------------------------------------------------

    /**
     * @param array $settings
     * @return ShopifyClient
     * @throws ShopifyException
     */
    public static function client(array $settings)
    {
        return new ShopifyClient([
            'shop'          => $settings['shop'],
            'api_version'   => $settings['api_version'],
            'client_id'     => $settings['client_id'],
            'client_secret' => $settings['client_secret'],
            'access_token'  => $settings['access_token'],
        ], new LaravelTokenStore());
    }

    /**
     * Email addresses to look up for a conversation (main one first).
     *
     * @param \App\Conversation $conversation
     * @return array
     */
    public static function emailsFor($conversation)
    {
        $emails = [];
        if (!empty($conversation->customer_email)) {
            $emails[] = $conversation->customer_email;
        }
        if ($conversation->customer) {
            foreach ($conversation->customer->emails_cached()->get() as $email) {
                $emails[] = $email->email;
            }
        }

        $unique = [];
        foreach ($emails as $email) {
            $email = strtolower(trim((string) $email));
            if ($email !== '' && !in_array($email, $unique, true)) {
                $unique[] = $email;
            }
        }

        return array_slice($unique, 0, self::MAX_EMAILS);
    }

    public static function cacheKey(array $settings, $email)
    {
        return 'shopify:v2:'.sha1($settings['shop'].'|'.strtolower(trim($email)));
    }

    /**
     * Customer + latest orders for the first email that matches something.
     * Results are cached for an hour; synchronizes the FreeScout customer.
     *
     * @param array              $settings
     * @param \App\Customer|null $customer  FreeScout customer to enrich.
     * @param array              $emails
     * @param bool               $refresh   Ignore the cache.
     * @return array customer, orders, notice, error (array|null)
     */
    public static function overview(array $settings, $customer, array $emails, $refresh = false)
    {
        $result = ['customer' => null, 'orders' => [], 'notice' => '', 'error' => null];
        $client = null;
        $firstError = null;
        $found = false;

        foreach ($emails as $email) {
            $key = self::cacheKey($settings, $email);
            if ($refresh) {
                \Cache::forget($key);
            }

            $entry = \Cache::get($key);
            if (!is_array($entry)) {
                try {
                    if (!$client) {
                        $client = self::client($settings);
                    }
                    $entry = $client->findByEmail($email);
                    $minutes = (!empty($entry['customer']) || !empty($entry['orders'])) ? self::CACHE_MINUTES : self::EMPTY_CACHE_MINUTES;
                    \Cache::put($key, $entry, now()->addMinutes($minutes));
                } catch (ShopifyException $e) {
                    if (!$firstError) {
                        $firstError = $e;
                    }
                    if ($e->type === ShopifyException::TYPE_CONFIG || $e->type === ShopifyException::TYPE_AUTH || $e->type === ShopifyException::TYPE_NOT_PERMITTED) {
                        break; // no point in trying the other addresses.
                    }
                    continue;
                }
            }

            if (!empty($entry['customer']) || !empty($entry['orders'])) {
                $result['customer'] = isset($entry['customer']) ? $entry['customer'] : null;
                $result['orders']   = isset($entry['orders']) ? $entry['orders'] : [];
                $result['notice']   = isset($entry['notice']) ? $entry['notice'] : '';
                $found = true;
                break;
            }
            if (!empty($entry['notice'])) {
                $result['notice'] = $entry['notice'];
            }
        }

        if (!$found && $firstError) {
            $result['error'] = self::describeError($firstError);
        }

        if ($found && $customer && $settings['sync_customers'] && !empty($result['customer'])) {
            try {
                CustomerSync::apply($customer, $result['customer']);
            } catch (\Exception $e) {
                \Log::warning('[Shopify] Customer sync failed: '.$e->getMessage());
            }
        }

        return $result;
    }

    /**
     * @param array  $settings
     * @param string $name Order name such as "#1001".
     * @return array|null
     * @throws ShopifyException
     */
    public static function findOrder(array $settings, $name)
    {
        // Short cache: agents often click the same number several times.
        $key = 'shopify:v2:order:'.sha1($settings['shop'].'|'.strtolower($name));
        $cached = \Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $node = self::client($settings)->findOrderByName($name);
        if ($node) {
            \Cache::put($key, $node, now()->addMinutes(2));
        }

        return $node;
    }

    /**
     * Does this order belong to one of the customer's email addresses?
     *
     * @param array $node   Order as returned by Shopify.
     * @param array $emails Addresses of the conversation's customer.
     * @return bool
     */
    public static function orderBelongsTo(array $node, array $emails)
    {
        foreach ($emails as $email) {
            if (ShopifyClient::sameEmail(isset($node['email']) ? $node['email'] : '', $email)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn what an agent typed ("1001", "#1001", "SHOP-1001") into an order name.
     *
     * @param string $input
     * @param string $prefix
     * @return string Empty if the input is not acceptable.
     */
    public static function orderName($input, $prefix)
    {
        $input = trim((string) $input);
        if ($input === '' || !preg_match('/^[#A-Za-z0-9._\- ]{1,40}$/', $input)) {
            return '';
        }

        $digits = ltrim($input, '#');
        if (ctype_digit($digits)) {
            return $prefix.$digits;
        }

        return $input;
    }

    /**
     * Run the connection test and prepare the flash messages describing it.
     *
     * @param array $settings
     * @return array flash key (flash_success|flash_warning|flash_error) => message
     */
    public static function testReport(array $settings)
    {
        try {
            $info = self::client($settings)->testConnection();
        } catch (ShopifyException $e) {
            $error = self::describeError($e);
            $text = $error['message'];
            if (in_array($e->type, [ShopifyException::TYPE_SCOPE, ShopifyException::TYPE_API], true)) {
                $text .= ' ('.$e->getMessage().')';
            }

            return ['flash_error' => __('Could not connect to Shopify.').' '.$text];
        }

        $flash = [];
        $name = $info['shop_name'] !== '' ? $info['shop_name'] : $info['domain'];
        $flash['flash_success'] = __('Connected to :shop.', ['shop' => $name])
            .($info['scopes'] ? ' '.__('Granted permissions: :scopes.', ['scopes' => implode(', ', $info['scopes'])]) : '');

        $missing = [];
        if (!$info['has_orders']) {
            $missing[] = 'read_orders';
        }
        if (!$info['has_customers']) {
            $missing[] = 'read_customers';
        }

        $warnings = [];
        if ($missing) {
            $warnings[] = __('Missing permissions: :scopes. Add them to the app version in the Shopify Dev Dashboard, release it and reinstall the app.', ['scopes' => implode(', ', $missing)]);
        } elseif (!$info['has_all_orders']) {
            $warnings[] = __('Orders older than 60 days will not appear because the read_all_orders permission is not granted.')
                .' '.__('If you already added it in the Dev Dashboard, release a new app version and accept the update for your store in the Shopify admin (Settings → Apps).');
        }
        if ($warnings) {
            $flash['flash_warning'] = implode(' ', $warnings);
        }

        return $flash;
    }

    /**
     * @param array $flash
     */
    public static function flash(array $flash)
    {
        foreach ($flash as $key => $message) {
            session()->flash($key, $message);
        }
    }

    // ------------------------------------------------------------------
    // Errors
    // ------------------------------------------------------------------

    /**
     * @param ShopifyException $e
     * @return array type, message (translated, actionable), detail (technical)
     */
    public static function describeError(ShopifyException $e)
    {
        switch ($e->type) {
            case ShopifyException::TYPE_CONFIG:
                $message = __('The Shopify connection is not configured correctly.');
                break;
            case ShopifyException::TYPE_AUTH:
                $message = __('Shopify refused the credentials. Check the Client ID and Client secret (or the access token).');
                break;
            case ShopifyException::TYPE_SCOPE:
                $message = __('The Shopify app does not have the required permission. Add the scope in the Dev Dashboard, release a new app version and reinstall the app.');
                break;
            case ShopifyException::TYPE_NOT_PERMITTED:
                $message = __('The app and the store must belong to the same Shopify organization (create the store from the Dev Dashboard).');
                break;
            case ShopifyException::TYPE_SHOP:
                $message = __('Shop not found. Use the my-store.myshopify.com domain.');
                break;
            case ShopifyException::TYPE_THROTTLED:
                $message = __('Shopify is limiting requests. Please try again in a moment.');
                break;
            case ShopifyException::TYPE_NETWORK:
                $message = __('Could not reach Shopify.');
                break;
            default:
                $message = __('Shopify returned an error.');
        }

        return ['type' => $e->type, 'message' => $message, 'detail' => $e->getMessage()];
    }

    // ------------------------------------------------------------------
    // View model
    // ------------------------------------------------------------------

    /**
     * Prepare everything the sidebar templates need.
     *
     * @param array $settings
     * @param array $overview Result of overview().
     * @return array customer (array|null), orders (array), error, notice, shop_url
     */
    public static function present(array $settings, array $overview)
    {
        $orders = [];
        foreach ($overview['orders'] as $node) {
            if (is_array($node)) {
                $orders[] = OrderPresenter::order($node, $settings['handle']);
            }
        }

        return [
            'customer' => !empty($overview['customer']) ? OrderPresenter::customer($overview['customer'], $settings['handle']) : null,
            'orders'   => $orders,
            'error'    => $overview['error'],
            'notice'   => $overview['notice'],
            'shop_url' => 'https://admin.shopify.com/store/'.rawurlencode($settings['handle']),
        ];
    }
}

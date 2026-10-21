<?php

namespace Modules\Shopify\Services;

/**
 * Token store backed by the Laravel cache (file/redis/database, whatever
 * FreeScout is configured with). The token is a 24-hour bearer credential, so it
 * is encrypted with the application key before it is cached.
 */
class LaravelTokenStore implements TokenStore
{
    public function get($key)
    {
        $value = \Cache::get($key);
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return decrypt($value);
        } catch (\Exception $e) {
            // Application key changed or cache corrupted: just ask for a new token.
            return null;
        }
    }

    public function put($key, $value, $seconds)
    {
        // Laravel 5.5 takes minutes or a DateTime; a DateTime works on every version.
        \Cache::put($key, encrypt($value), now()->addSeconds((int) $seconds));
    }

    public function forget($key)
    {
        \Cache::forget($key);
    }
}

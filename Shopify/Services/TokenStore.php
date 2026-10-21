<?php

namespace Modules\Shopify\Services;

/**
 * Where access tokens are kept between requests.
 */
interface TokenStore
{
    /**
     * @param string $key
     * @return mixed|null
     */
    public function get($key);

    /**
     * @param string $key
     * @param mixed  $value
     * @param int    $seconds Time to live.
     */
    public function put($key, $value, $seconds);

    /**
     * @param string $key
     */
    public function forget($key);
}

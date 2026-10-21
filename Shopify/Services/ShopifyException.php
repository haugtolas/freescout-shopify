<?php

namespace Modules\Shopify\Services;

/**
 * Error raised by the Shopify client.
 *
 * The message is technical and never contains credentials. The `type`
 * is what the UI uses to pick a translated, actionable explanation.
 */
class ShopifyException extends \Exception
{
    const TYPE_CONFIG        = 'config';
    const TYPE_AUTH          = 'auth';
    const TYPE_SCOPE         = 'scope';
    const TYPE_NOT_PERMITTED = 'not_permitted';
    const TYPE_SHOP          = 'shop';
    const TYPE_THROTTLED     = 'throttled';
    const TYPE_NETWORK       = 'network';
    const TYPE_API           = 'api';

    /** @var string */
    public $type;

    public function __construct($message, $type = self::TYPE_API, $code = 0)
    {
        parent::__construct($message, $code);
        $this->type = $type;
    }
}

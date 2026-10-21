<?php

namespace Modules\Shopify\Services;

/**
 * Shopify conditions and actions for the "Workflows" module, plus the optional
 * copy of the Shopify customer tags into the "Tags" module.
 *
 * The decisions are made here with plain arrays so they can be tested without
 * FreeScout; ShopifyServiceProvider only connects them to the Workflows hooks.
 */
class WorkflowRules
{
    const PREFIX = 'shopify_';

    // Events after which a condition can be checked (same as the "Channel" condition).
    private static $triggers = [
        'conversation.created_by_user',
        'conversation.user_forwarded',
        'conversation.created_by_customer',
        'conversation.customer_replied',
        'conversation.user_replied',
        'conversation.moved',
    ];

    private static $financial = ['PAID', 'PARTIALLY_PAID', 'PENDING', 'AUTHORIZED', 'PARTIALLY_REFUNDED', 'REFUNDED', 'VOIDED', 'EXPIRED'];

    private static $fulfillment = ['UNFULFILLED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'IN_PROGRESS', 'ON_HOLD', 'SCHEDULED', 'PENDING_FULFILLMENT', 'OPEN', 'REQUEST_DECLINED', 'RESTOCKED'];

    public static function isShopifyType($type)
    {
        return is_string($type) && strpos($type, self::PREFIX) === 0;
    }

    // ------------------------------------------------------------------
    // Configuration shown in the Workflows editor
    // ------------------------------------------------------------------

    /**
     * @param array $conditions Filter value of "workflows.conditions_config".
     * @return array
     */
    public static function addConditions(array $conditions)
    {
        $numeric = [
            'equal'   => self::t('Is equal to'),
            'greater' => self::t('Is greater than'),
            'less'    => self::t('Is less than'),
        ];
        $items = [
            'shopify_customer' => [
                'title'     => self::t('Shopify customer'),
                'operators' => ['yes' => self::t('Exists in Shopify'), 'no' => self::t('Does not exist in Shopify')],
                'values'    => [],
                'triggers'  => self::$triggers,
            ],
            'shopify_customer_tag' => [
                'title'     => self::t('Shopify customer tag(s)'),
                'operators' => [
                    'equal'        => self::t('Is equal to'),
                    'contains'     => self::t('Contains'),
                    'not_contains' => self::t('Does not contain'),
                ],
                'triggers'  => self::$triggers,
            ],
            'shopify_orders_count' => [
                'title'     => self::t('Shopify number of orders'),
                'operators' => $numeric,
                'triggers'  => self::$triggers,
            ],
            'shopify_total_spent' => [
                'title'     => self::t('Shopify total spent (shop currency)'),
                'operators' => ['greater' => $numeric['greater'], 'less' => $numeric['less']],
                'triggers'  => self::$triggers,
            ],
            'shopify_payment_status' => [
                'title'     => self::t('Shopify last order: payment'),
                'operators' => ['equal' => self::t('Is equal to'), 'not_equal' => self::t('Is not equal to')],
                'values'    => self::statusValues(self::$financial, 'financial'),
                'triggers'  => self::$triggers,
            ],
            'shopify_fulfillment_status' => [
                'title'     => self::t('Shopify last order: fulfillment'),
                'operators' => ['equal' => self::t('Is equal to'), 'not_equal' => self::t('Is not equal to')],
                'values'    => self::statusValues(self::$fulfillment, 'fulfillment'),
                'triggers'  => self::$triggers,
            ],
        ];

        if (!isset($conditions['conversation']['items'])) {
            $conditions['conversation'] = ['title' => self::t('Conversation'), 'items' => []];
        }
        $conditions['conversation']['items'] = array_merge($conditions['conversation']['items'], $items);

        return $conditions;
    }

    /**
     * @param array $actions Filter value of "workflows.actions_config".
     * @param bool  $tagsAvailable Is the Tags module active?
     * @return array
     */
    public static function addActions(array $actions, $tagsAvailable)
    {
        $new = [
            'shopify_note' => ['title' => self::t('Add a note with the Shopify customer summary'), 'values' => []],
        ];
        if ($tagsAvailable) {
            $new['shopify_copy_tags'] = ['title' => self::t('Add the Shopify customer tags as conversation tags'), 'values' => []];
        }
        if (!isset($actions['dummy']['items'])) {
            $actions['dummy'] = ['items' => []];
        }
        $actions['dummy']['items'] = array_merge($actions['dummy']['items'], $new);

        return $actions;
    }

    private static function statusValues(array $codes, $kind)
    {
        $values = [];
        foreach ($codes as $code) {
            $status = $kind === 'financial' ? OrderPresenter::financialStatus($code) : OrderPresenter::fulfillmentStatus($code);
            $values[$code] = $status['label'];
        }

        return $values;
    }

    // ------------------------------------------------------------------
    // Evaluation
    // ------------------------------------------------------------------

    /**
     * @param string $type     One of the shopify_* conditions.
     * @param string $operator
     * @param mixed  $value    What the workflow author typed or chose.
     * @param array  $overview customer (raw node|null), orders (raw nodes, newest first).
     * @return bool
     */
    public static function evaluate($type, $operator, $value, array $overview)
    {
        $customer = !empty($overview['customer']) && is_array($overview['customer']) ? $overview['customer'] : null;
        $orders   = !empty($overview['orders']) && is_array($overview['orders']) ? array_values($overview['orders']) : [];
        $value    = is_scalar($value) ? trim((string) $value) : '';

        switch ($type) {
            case 'shopify_customer':
                return $operator === 'yes' ? $customer !== null : $customer === null;

            case 'shopify_customer_tag':
                return self::compareTags($customer && isset($customer['tags']) && is_array($customer['tags']) ? $customer['tags'] : [], $value, $operator);

            case 'shopify_orders_count':
                $count = $customer && isset($customer['numberOfOrders']) ? (int) $customer['numberOfOrders'] : count($orders);

                return self::compareNumber($count, $value, $operator);

            case 'shopify_total_spent':
                if (!$customer || !isset($customer['amountSpent']['amount'])) {
                    return false;
                }

                return self::compareNumber((float) $customer['amountSpent']['amount'], $value, $operator);

            case 'shopify_payment_status':
            case 'shopify_fulfillment_status':
                if (!$orders) {
                    return false;
                }
                $field = $type === 'shopify_payment_status' ? 'displayFinancialStatus' : 'displayFulfillmentStatus';
                $last  = isset($orders[0][$field]) ? strtoupper((string) $orders[0][$field]) : '';
                if ($last === '') {
                    return false;
                }

                return $operator === 'not_equal' ? $last !== strtoupper($value) : $last === strtoupper($value);
        }

        return false;
    }

    /**
     * Same logic as the Tags module: for "contains"/"equal" any listed tag is enough,
     * for "does not contain" all of them must be absent.
     */
    private static function compareTags(array $tags, $value, $operator)
    {
        $tags = array_map(function ($t) { return mb_strtolower(trim((string) $t)); }, $tags);
        $wanted = array_values(array_filter(array_map(function ($t) { return mb_strtolower(trim($t)); }, explode(',', $value)), 'strlen'));
        if (!$wanted) {
            return false;
        }

        foreach ($wanted as $one) {
            $has = false;
            foreach ($tags as $tag) {
                if ($operator === 'equal' ? $tag === $one : strpos($tag, $one) !== false) {
                    $has = true;
                    break;
                }
            }
            if ($operator === 'not_contains') {
                if ($has) {
                    return false;
                }
            } elseif ($has) {
                return true;
            }
        }

        return $operator === 'not_contains';
    }

    private static function compareNumber($actual, $value, $operator)
    {
        $value = str_replace(',', '.', $value);
        if ($value === '' || !is_numeric($value)) {
            return false;
        }
        $value = (float) $value;

        switch ($operator) {
            case 'greater':
                return $actual > $value;
            case 'less':
                return $actual < $value;
            case 'equal':
                return abs($actual - $value) < 0.005;
        }

        return false;
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    /**
     * Tag names to copy from the Shopify customer.
     *
     * @param array $overview
     * @return array
     */
    public static function tagsToCopy(array $overview)
    {
        $tags = [];
        if (!empty($overview['customer']['tags']) && is_array($overview['customer']['tags'])) {
            foreach ($overview['customer']['tags'] as $tag) {
                $tag = trim(strip_tags((string) $tag));
                if ($tag !== '' && mb_strlen($tag) <= 100) {
                    $tags[] = $tag;
                }
            }
        }

        return array_slice(array_values(array_unique($tags)), 0, 20);
    }

    /**
     * HTML of the internal note. Every piece of Shopify data is escaped.
     *
     * @param array  $settings
     * @param array  $overview customer and orders as returned by ShopifyManager::overview().
     * @return string Empty when there is nothing to say.
     */
    public static function noteHtml(array $settings, array $overview)
    {
        $data = ShopifyManager::present($settings, $overview + ['customer' => null, 'orders' => [], 'error' => null, 'notice' => '']);
        if (!$data['customer'] && !$data['orders']) {
            return '';
        }

        $e = function ($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); };
        $link = function ($url, $text) use ($e) {
            return $url !== '' ? '<a href="'.$e($url).'" target="_blank" rel="noopener noreferrer">'.$e($text).'</a>' : $e($text);
        };

        $html = '<strong>Shopify</strong>';
        if ($data['customer']) {
            $c = $data['customer'];
            $html .= '<br>'.$link($c['admin_url'], $c['name'] !== '' ? $c['name'] : $c['email']);
            $line = [self::t('Orders').': '.$c['orders_count']];
            if ($c['total_spent'] !== '') {
                $line[] = self::t('Spent').': '.$c['total_spent'];
            }
            if ($c['tags']) {
                $line[] = implode(', ', $c['tags']);
            }
            $html .= '<br>'.$e(implode(' · ', $line));
        }
        if ($data['orders']) {
            $html .= '<ul>';
            foreach ($data['orders'] as $o) {
                $html .= '<li>'.$link($o['admin_url'], $o['name']).' — '.$e($o['created_at']).' — '.$e($o['total'])
                    .' — '.$e($o['financial']['label']).', '.$e($o['fulfillment']['label']).'</li>';
            }
            $html .= '</ul>';
        }

        return $html;
    }

    private static function t($text)
    {
        return function_exists('__') ? __($text) : $text;
    }
}

<?php

namespace Modules\Shopify\Services;

/**
 * Copies data from the matching Shopify customer into the FreeScout customer.
 *
 * Rules:
 *  - only EMPTY FreeScout fields are filled, nothing the team typed is overwritten;
 *  - nothing is created or deleted, the customer is saved only if something changed;
 *  - FreeScout's own Customer::setData() does the filling.
 */
class CustomerSync
{
    /** \App\Customer::PHONE_TYPE_MOBILE */
    const PHONE_TYPE_MOBILE = 4;

    /**
     * Map a Shopify customer node to FreeScout customer attributes.
     *
     * @param array $c Shopify customer node.
     * @return array
     */
    public static function dataFromShopify(array $c)
    {
        $data = [];
        $address = isset($c['defaultAddress']) && is_array($c['defaultAddress']) ? $c['defaultAddress'] : [];

        $set = function ($key, $value, $max = 255) use (&$data) {
            $value = trim((string) $value);
            if ($value !== '') {
                $data[$key] = function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
            }
        };

        $set('first_name', isset($c['firstName']) ? $c['firstName'] : '');
        $set('last_name', isset($c['lastName']) ? $c['lastName'] : '');
        $set('company', isset($address['company']) ? $address['company'] : '');
        $set('city', isset($address['city']) ? $address['city'] : '');
        $set('state', isset($address['province']) ? $address['province'] : '');
        $set('zip', isset($address['zip']) ? $address['zip'] : '', 12);
        $set('country', isset($address['countryCodeV2']) ? $address['countryCodeV2'] : '', 2);

        $street = trim((isset($address['address1']) ? $address['address1'] : '').' '.(isset($address['address2']) ? $address['address2'] : ''));
        $set('address', $street);

        $phone = isset($c['phone']) && $c['phone'] ? $c['phone'] : (isset($address['phone']) ? $address['phone'] : '');
        $set('phone', $phone, 40);

        return $data;
    }

    /**
     * @param \App\Customer $customer
     * @param array         $shopifyCustomer
     * @return bool True if the FreeScout customer was modified.
     */
    public static function apply($customer, array $shopifyCustomer)
    {
        if (!is_object($customer) || !method_exists($customer, 'setData')) {
            return false;
        }

        $data = self::dataFromShopify($shopifyCustomer);
        if (!$data) {
            return false;
        }

        // The phone is added by hand: setData() would label it "Work", and a Shopify
        // phone number is far more often a mobile. Never add a second number.
        $phone = isset($data['phone']) ? $data['phone'] : '';
        unset($data['phone']);
        if ($data) {
            $customer->setData($data, false, false);
        }
        if ($phone !== '' && method_exists($customer, 'addPhone')
            && !(method_exists($customer, 'getPhones') && count($customer->getPhones()))
        ) {
            $customer->addPhone($phone, self::PHONE_TYPE_MOBILE);
        }

        if ($customer->isDirty()) {
            $customer->save();

            return true;
        }

        return false;
    }
}

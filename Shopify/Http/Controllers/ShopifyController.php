<?php

namespace Modules\Shopify\Http\Controllers;

use App\Conversation;
use App\Mailbox;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Shopify\Services\OrderPresenter;
use Modules\Shopify\Services\ShopifyClient;
use Modules\Shopify\Services\ShopifyException;
use Modules\Shopify\Services\ShopifyManager;

class ShopifyController extends Controller
{
    /**
     * Mailbox Shopify settings page (admins only, see routes).
     */
    public function mailboxSettings($id)
    {
        $mailbox = Mailbox::findOrFail($id);
        $stored  = ShopifyManager::mailboxRaw($mailbox);

        return view('shopify::mailbox_settings', [
            'mailbox'  => $mailbox,
            'settings' => [
                'shopify.shop_domain'    => isset($stored['shop_domain']) ? $stored['shop_domain'] : '',
                'shopify.client_id'      => isset($stored['client_id']) ? $stored['client_id'] : '',
                // The real secrets are never sent back to the browser.
                'shopify.client_secret'  => ShopifyManager::mask(isset($stored['client_secret']) ? $stored['client_secret'] : ''),
                'shopify.access_token'   => ShopifyManager::mask(isset($stored['access_token']) ? $stored['access_token'] : ''),
                'shopify.api_version'    => isset($stored['api_version']) && $stored['api_version'] !== '' ? $stored['api_version'] : ShopifyManager::DEFAULT_API_VERSION,
                'shopify.sync_customers' => ShopifyManager::bool(isset($stored['sync_customers']) ? $stored['sync_customers'] : null, true),
                'shopify.link_orders'    => ShopifyManager::bool(isset($stored['link_orders']) ? $stored['link_orders'] : null, true),
                'shopify.restrict_orders' => ShopifyManager::bool(isset($stored['restrict_orders']) ? $stored['restrict_orders'] : null, false),
                'shopify.order_prefix'   => ShopifyManager::prefix(isset($stored['order_prefix']) ? $stored['order_prefix'] : null),
            ],
            'global_configured' => ShopifyManager::resolve(ShopifyManager::globalRaw()) !== null,
        ]);
    }

    public function mailboxSettingsSave($id, Request $request)
    {
        $mailbox = Mailbox::findOrFail($id);
        $stored  = ShopifyManager::mailboxRaw($mailbox);

        // Form fields are named settings[shopify.xxx].
        $input = [];
        foreach ((array) $request->input('settings', []) as $key => $value) {
            $input[preg_replace('/^shopify\./', '', $key)] = is_string($value) ? $value : '';
        }
        $get = function ($key) use ($input) {
            return isset($input[$key]) ? trim($input[$key]) : '';
        };

        $shopInput = $get('shop_domain');
        $shop      = ShopifyClient::normalizeShop($shopInput);

        if ($shopInput === '') {
            // Empty = this mailbox uses the global connection.
            $mailbox->shopify = null;
            $mailbox->save();
            \Session::flash('flash_success_floating', __('Settings updated'));

            return redirect()->route('mailboxes.shopify', ['id' => $id]);
        }

        if (!$shop) {
            \Session::flash('flash_error', __('Invalid shop domain. Use something like my-store.myshopify.com.'));

            return redirect()->route('mailboxes.shopify', ['id' => $id]);
        }

        $version = $get('api_version');
        if (!ShopifyClient::isValidApiVersion($version)) {
            $version = ShopifyManager::DEFAULT_API_VERSION;
        }

        $settings = [
            'shop_domain'    => $shop,
            'client_id'      => $get('client_id'),
            'client_secret'  => ShopifyManager::secretFromForm(isset($input['client_secret']) ? $input['client_secret'] : '', isset($stored['client_secret']) ? $stored['client_secret'] : ''),
            'access_token'   => ShopifyManager::secretFromForm(isset($input['access_token']) ? $input['access_token'] : '', isset($stored['access_token']) ? $stored['access_token'] : ''),
            'api_version'    => $version,
            'sync_customers' => !empty($input['sync_customers']),
            'link_orders'    => !empty($input['link_orders']),
            'restrict_orders' => !empty($input['restrict_orders']),
            'order_prefix'   => ShopifyManager::prefix(isset($input['order_prefix']) ? $input['order_prefix'] : ''),
        ];

        $mailbox->shopify = json_encode($settings);
        $mailbox->save();

        $resolved = ShopifyManager::resolve($settings, 'mailbox', $mailbox->id);
        if ($resolved) {
            ShopifyManager::flash(ShopifyManager::testReport($resolved));
        } else {
            \Session::flash('flash_warning', __('Enter a Client ID and Client secret (or an Admin API access token) to connect.'));
        }

        return redirect()->route('mailboxes.shopify', ['id' => $id]);
    }

    /**
     * Ajax controller used by the conversation sidebar.
     *
     * The conversation decides everything: the mailbox, the Shopify store
     * and the customer emails. The browser never sends an email address, so
     * a user cannot use this endpoint to look up an arbitrary person.
     */
    public function ajax(Request $request)
    {
        $response = ['status' => 'error', 'msg' => ''];

        $user = auth()->user();
        $conversation = Conversation::find((int) $request->input('conversation_id'));

        if (!$user || !$conversation || !$user->can('view', $conversation)) {
            $response['msg'] = __('Not authorized');

            return response()->json($response, 403);
        }

        $settings = ShopifyManager::settingsFor($conversation->mailbox);
        if (!$settings) {
            $response['msg'] = __('Shopify is not configured for this mailbox.');

            return response()->json($response);
        }

        switch ($request->input('action')) {

            case 'orders':
                $overview = ShopifyManager::overview(
                    $settings,
                    $conversation->customer,
                    ShopifyManager::emailsFor($conversation),
                    (bool) $request->input('refresh')
                );

                $response['html'] = view('shopify::partials/orders_list', [
                    'data'     => ShopifyManager::present($settings, $overview),
                    'settings' => $settings,
                ])->render();
                $response['status'] = 'success';
                break;

            case 'order':
                // Search one order by number: typed in the search box or clicked in a message.
                $name = ShopifyManager::orderName($request->input('number'), $settings['order_prefix']);
                if ($name === '') {
                    $response['msg'] = __('Enter an order number.');
                    break;
                }

                try {
                    $node = ShopifyManager::findOrder($settings, $name);
                } catch (ShopifyException $e) {
                    $error = ShopifyManager::describeError($e);
                    $response['msg'] = $error['message'];
                    break;
                }

                // Same answer whether the order does not exist or belongs to someone else.
                if (!$node || ($settings['restrict_orders'] && !ShopifyManager::orderBelongsTo($node, ShopifyManager::emailsFor($conversation)))) {
                    $response['msg'] = __('Order :number not found.', ['number' => $name]);
                    break;
                }

                $response['html'] = view('shopify::partials/order_detail', [
                    'order'    => OrderPresenter::order($node, $settings['handle']),
                    // Warn the agent when the order was placed with another email address.
                    'mismatch' => !ShopifyManager::orderBelongsTo($node, ShopifyManager::emailsFor($conversation)),
                ])->render();
                $response['title'] = isset($node['name']) ? (string) $node['name'] : $name;
                $response['status'] = 'success';
                break;

            default:
                $response['msg'] = 'Unknown action';
                break;
        }

        if ($response['status'] == 'error' && empty($response['msg'])) {
            $response['msg'] = __('Unknown error occurred.');
        }

        return response()->json($response);
    }
}

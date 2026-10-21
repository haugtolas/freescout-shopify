<?php

namespace Modules\Shopify\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Shopify\Jobs\PrefetchCustomer;
use Modules\Shopify\Services\ShopifyClient;
use Modules\Shopify\Services\ShopifyManager;
use Modules\Shopify\Services\WorkflowRules;

// Module alias.
// Key of the settings section ("Manage > Settings > Shopify").
if (!defined('SHOPIFY_MODULE')) {
    define('SHOPIFY_MODULE', 'shopify');
}

// Module alias (module.json). It must stay different from "shopify": FreeScout's
// official modules directory lists a module with that alias and would replace this
// module's icon with its own.
if (!defined('SHOPIFY_MODULE_ALIAS')) {
    define('SHOPIFY_MODULE_ALIAS', 'shopifydesk');
}

class ShopifyServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Boot the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->hooks();
    }

    /**
     * Module hooks.
     */
    public function hooks()
    {
        // Module CSS and JS on every page (the sidebar is rendered by AJAX).
        \Eventy::addFilter('stylesheets', function ($styles) {
            $styles[] = \Module::getPublicPath(SHOPIFY_MODULE_ALIAS).'/css/module.css';

            return $styles;
        });

        \Eventy::addFilter('javascripts', function ($javascripts) {
            $javascripts[] = \Module::getPublicPath(SHOPIFY_MODULE_ALIAS).'/js/laroute.js';
            $javascripts[] = \Module::getPublicPath(SHOPIFY_MODULE_ALIAS).'/js/module.js';

            return $javascripts;
        });

        // Item in the mailbox settings menu.
        \Eventy::addAction('mailboxes.settings.menu', function ($mailbox) {
            if (auth()->user()->isAdmin()) {
                echo \View::make('shopify::partials/settings_menu', ['mailbox' => $mailbox])->render();
            }
        }, 34);

        // Global settings section.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections[SHOPIFY_MODULE] = ['title' => 'Shopify', 'icon' => 'shopping-cart', 'order' => 550];

            return $sections;
        }, 35);

        \Eventy::addFilter('settings.section_params', function ($params, $section) {
            if ($section != SHOPIFY_MODULE) {
                return $params;
            }

            // Saved into .env. Secrets are encrypted and never echoed back to the browser.
            $params['settings'] = [
                'shopify.shop_domain'    => ['env' => 'SHOPIFY_SHOP_DOMAIN'],
                'shopify.client_id'      => ['env' => 'SHOPIFY_CLIENT_ID'],
                'shopify.client_secret'  => ['env' => 'SHOPIFY_CLIENT_SECRET', 'safe_password' => true, 'encrypt' => true],
                'shopify.access_token'   => ['env' => 'SHOPIFY_ACCESS_TOKEN', 'safe_password' => true, 'encrypt' => true],
                'shopify.api_version'    => ['env' => 'SHOPIFY_API_VERSION'],
                'shopify.sync_customers' => ['env' => 'SHOPIFY_SYNC_CUSTOMERS'],
                'shopify.link_orders'    => ['env' => 'SHOPIFY_LINK_ORDERS'],
                'shopify.restrict_orders' => ['env' => 'SHOPIFY_RESTRICT_ORDERS'],
                'shopify.order_prefix'   => ['env' => 'SHOPIFY_ORDER_PREFIX'],
            ];

            return $params;
        }, 20, 2);

        \Eventy::addFilter('settings.view', function ($view, $section) {
            return $section != SHOPIFY_MODULE ? $view : 'shopify::settings';
        }, 20, 2);

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            if ($section != SHOPIFY_MODULE) {
                return $settings;
            }

            $settings['shopify.shop_domain']    = config('shopify.shop_domain');
            $settings['shopify.client_id']      = config('shopify.client_id');
            $settings['shopify.client_secret']  = ShopifyManager::mask(config('shopify.client_secret'));
            $settings['shopify.access_token']   = ShopifyManager::mask(config('shopify.access_token'));
            $settings['shopify.api_version']    = config('shopify.api_version') ?: ShopifyManager::DEFAULT_API_VERSION;
            $settings['shopify.sync_customers'] = ShopifyManager::bool(config('shopify.sync_customers'), true);
            $settings['shopify.link_orders']    = ShopifyManager::bool(config('shopify.link_orders'), true);
            $settings['shopify.restrict_orders'] = ShopifyManager::bool(config('shopify.restrict_orders'), false);
            $settings['shopify.order_prefix']   = ShopifyManager::prefix(config('shopify.order_prefix'));

            // Mailboxes that have their own Shopify connection.
            $mailboxes = [];
            foreach (\Auth::user()->mailboxesCanView(true) as $mailbox) {
                if (ShopifyManager::resolve(ShopifyManager::mailboxRaw($mailbox)) !== null) {
                    $mailboxes[] = $mailbox;
                }
            }
            $settings['mailboxes_enabled'] = $mailboxes;

            return $settings;
        }, 20, 2);

        // Clean up what was typed before it is written to .env.
        \Eventy::addFilter('settings.before_save', function ($request, $section, $settings) {
            if ($section != SHOPIFY_MODULE) {
                return $request;
            }

            $input = $request->settings ?: [];

            $shop = isset($input['shopify.shop_domain']) ? trim($input['shopify.shop_domain']) : '';
            if ($shop !== '') {
                $normalized = ShopifyClient::normalizeShop($shop);
                if (!$normalized) {
                    session()->flash('flash_error', __('Invalid shop domain. Use something like my-store.myshopify.com.'));
                }
                $input['shopify.shop_domain'] = $normalized ?: '';
            }

            $version = isset($input['shopify.api_version']) ? trim($input['shopify.api_version']) : '';
            $input['shopify.api_version'] = ShopifyClient::isValidApiVersion($version) ? $version : ShopifyManager::DEFAULT_API_VERSION;

            $input['shopify.order_prefix'] = ShopifyManager::prefix(isset($input['shopify.order_prefix']) ? $input['shopify.order_prefix'] : '');

            $request->merge(['settings' => $input]);

            return $request;
        }, 20, 3);

        // Test the connection right after saving. The values come from the form
        // (the cached config is not refreshed yet).
        \Eventy::addFilter('settings.after_save', function ($response, $request, $section, $settings) {
            if ($section != SHOPIFY_MODULE) {
                return $response;
            }

            $input = $request->settings ?: [];
            $field = function ($key) use ($input) {
                return isset($input['shopify.'.$key]) ? $input['shopify.'.$key] : '';
            };
            $secret = function ($key) use ($field) {
                // Unchanged secrets come back as asterisks: use the stored one.
                return ShopifyManager::isMask($field($key)) ? config('shopify.'.$key) : $field($key);
            };

            $resolved = ShopifyManager::resolve([
                'shop_domain'    => $field('shop_domain'),
                'client_id'      => $field('client_id'),
                'client_secret'  => $secret('client_secret'),
                'access_token'   => $secret('access_token'),
                'api_version'    => $field('api_version'),
                'sync_customers' => $field('sync_customers'),
                'link_orders'    => $field('link_orders'),
                'restrict_orders' => $field('restrict_orders'),
                'order_prefix'   => $field('order_prefix'),
            ]);

            if ($resolved) {
                ShopifyManager::flash(ShopifyManager::testReport($resolved));
            } elseif ($field('shop_domain') !== '') {
                $request->session()->flash('flash_warning', __('Enter a Client ID and Client secret (or an Admin API access token) to connect.'));
            }

            return $response;
        }, 20, 4);

        // Customer + recent orders in the conversation sidebar.
        \Eventy::addAction('conversation.after_prev_convs', function ($customer, $conversation, $mailbox) {
            if (!$customer) {
                return;
            }
            $settings = ShopifyManager::settingsFor($mailbox);
            if (!$settings) {
                return;
            }
            $emails = ShopifyManager::emailsFor($conversation);
            if (!$emails) {
                return;
            }

            // If the data is already cached the sidebar is rendered immediately,
            // otherwise the browser loads it (no Shopify call delays the page).
            $data = null;
            foreach ($emails as $email) {
                $entry = \Cache::get(ShopifyManager::cacheKey($settings, $email));
                if (is_array($entry) && (!empty($entry['customer']) || !empty($entry['orders']))) {
                    $data = ShopifyManager::present($settings, [
                        'customer' => isset($entry['customer']) ? $entry['customer'] : null,
                        'orders'   => isset($entry['orders']) ? $entry['orders'] : [],
                        'notice'   => isset($entry['notice']) ? $entry['notice'] : '',
                        'error'    => null,
                    ]);
                    break;
                }
            }

            echo \View::make('shopify::partials/orders', [
                'data'            => $data,
                'settings'        => $settings,
                'conversation_id' => $conversation->id,
            ])->render();
        }, 12, 3);

        // Menu item in the customer's gear menu.
        \Eventy::addAction('conversation.customer.menu', function ($customer, $conversation) {
            if (!ShopifyManager::isConfigured($conversation->mailbox)) {
                return;
            }
            ?>
                <li role="presentation" class="col3-hidden"><a data-toggle="collapse" href=".shopify-collapse-orders" tabindex="-1" role="menuitem"><?php echo e(__('Shopify')) ?></a></li>
            <?php
        }, 12, 2);

        // New conversation from a customer: look the customer up in the background.
        \Eventy::addAction('conversation.created_by_customer', function ($conversation, $thread, $customer) {
            try {
                if (!$customer) {
                    return;
                }
                $settings = ShopifyManager::settingsFor($conversation->mailbox);
                if ($settings && $settings['sync_customers']) {
                    PrefetchCustomer::dispatch($customer->id, $conversation->mailbox_id)->onQueue('default');
                }
            } catch (\Exception $e) {
                \Log::warning('[Shopify] Could not queue the customer lookup: '.$e->getMessage());
            }
        }, 20, 3);

        $this->workflowHooks();
    }

    /** @var array Shopify data looked up during this request, by conversation. */
    private static $overviews = [];

    /**
     * Conditions and actions for the Workflows module, and the copy of the
     * Shopify customer tags into the Tags module. Harmless when those modules
     * are not installed: nobody calls these filters.
     */
    private function workflowHooks()
    {
        \Eventy::addFilter('workflows.conditions_config', function ($conditions) {
            return WorkflowRules::addConditions(is_array($conditions) ? $conditions : []);
        }, 20, 1);

        \Eventy::addFilter('workflows.actions_config', function ($actions) {
            return WorkflowRules::addActions(is_array($actions) ? $actions : [], self::tagsAvailable());
        }, 20, 1);

        \Eventy::addFilter('workflow.check_condition', function ($result, $type, $operator, $value, $conversation, $workflow) {
            if (!WorkflowRules::isShopifyType($type)) {
                return $result;
            }
            $overview = self::overviewForWorkflow($conversation);

            // Shopify unreachable or not configured: the condition is simply not met.
            return $overview === null ? false : WorkflowRules::evaluate($type, (string) $operator, $value, $overview);
        }, 20, 6);

        \Eventy::addFilter('workflow.perform_action', function ($performed, $type, $operator, $value, $conversation, $workflow) {
            if (!WorkflowRules::isShopifyType($type)) {
                return $performed;
            }
            try {
                $overview = self::overviewForWorkflow($conversation);
                if ($overview === null) {
                    return $performed;
                }

                if ($type === 'shopify_copy_tags' && self::tagsAvailable()) {
                    foreach (WorkflowRules::tagsToCopy($overview) as $name) {
                        \Modules\Tags\Entities\Tag::attachByName($name, $conversation->id);
                    }

                    return true;
                }

                if ($type === 'shopify_note') {
                    $html = WorkflowRules::noteHtml(ShopifyManager::settingsFor($conversation->mailbox), $overview);
                    if ($html !== '') {
                        $conversation->createUserThread(\Workflow::getUser(), $html, [
                            'type' => \App\Thread::TYPE_NOTE,
                            'meta' => ['workflow_id' => $workflow->id],
                        ]);

                        return true;
                    }
                }
            } catch (\Exception $e) {
                \Log::warning('[Shopify] Workflow action failed: '.$e->getMessage());
            }

            return $performed;
        }, 20, 6);
    }

    private static function tagsAvailable()
    {
        return class_exists('Modules\\Tags\\Entities\\Tag') && \Module::isActive('tags');
    }

    /**
     * Shopify customer and orders for a conversation (no customer sync), or null
     * when Shopify is not configured or cannot be reached.
     *
     * @param \App\Conversation $conversation
     * @return array|null
     */
    private static function overviewForWorkflow($conversation)
    {
        $id = (int) $conversation->id;
        if (array_key_exists($id, self::$overviews)) {
            return self::$overviews[$id];
        }

        $overview = null;
        try {
            $settings = ShopifyManager::settingsFor($conversation->mailbox);
            $emails   = $settings ? ShopifyManager::emailsFor($conversation) : [];
            if ($settings && $emails) {
                $result = ShopifyManager::overview($settings, null, $emails);
                if (empty($result['error'])) {
                    $overview = $result;
                }
            }
        } catch (\Exception $e) {
            \Log::warning('[Shopify] Workflow lookup failed: '.$e->getMessage());
        }

        return self::$overviews[$id] = $overview;
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        $this->registerTranslations();
    }

    /**
     * @return void
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('shopify.php'),
        ], 'config');
        $this->mergeConfigFrom(
            __DIR__.'/../Config/config.php', 'shopify'
        );
    }

    /**
     * @return void
     */
    public function registerViews()
    {
        $viewPath   = resource_path('views/modules/shopify');
        $sourcePath = __DIR__.'/../Resources/views';

        $this->publishes([
            $sourcePath => $viewPath,
        ], 'views');

        $this->loadViewsFrom(array_merge(array_map(function ($path) {
            return $path.'/modules/shopify';
        }, \Config::get('view.paths')), [$sourcePath]), 'shopify');
    }

    /**
     * @return void
     */
    public function registerTranslations()
    {
        $this->loadJsonTranslationsFrom(__DIR__.'/../Resources/lang');
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [];
    }
}

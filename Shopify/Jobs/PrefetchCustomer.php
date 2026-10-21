<?php

namespace Modules\Shopify\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Shopify\Services\ShopifyManager;

/**
 * Runs when a customer starts a new conversation: looks the customer up in
 * Shopify, fills the empty fields of the FreeScout customer and warms the
 * cache so the sidebar is instant when an agent opens the conversation.
 *
 * It runs in the queue so a slow Shopify never delays fetching emails.
 */
class PrefetchCustomer implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $customerId;
    public $mailboxId;

    public $timeout = 60;
    public $tries   = 1;

    public function __construct($customerId, $mailboxId)
    {
        $this->customerId = (int) $customerId;
        $this->mailboxId  = (int) $mailboxId;
    }

    public function handle()
    {
        try {
            $mailbox  = \App\Mailbox::find($this->mailboxId);
            $customer = \App\Customer::find($this->customerId);
            if (!$mailbox || !$customer) {
                return;
            }

            $settings = ShopifyManager::settingsFor($mailbox);
            if (!$settings || !$settings['sync_customers']) {
                return;
            }

            $emails = [];
            foreach ($customer->emails_cached()->get() as $email) {
                $emails[] = strtolower(trim($email->email));
            }
            $emails = array_slice(array_values(array_unique(array_filter($emails))), 0, ShopifyManager::MAX_EMAILS);

            if ($emails) {
                ShopifyManager::overview($settings, $customer, $emails);
            }
        } catch (\Exception $e) {
            // Never fail the queue because of Shopify. No customer data in the log.
            \Log::warning('[Shopify] Prefetch failed: '.$e->getMessage());
        }
    }
}

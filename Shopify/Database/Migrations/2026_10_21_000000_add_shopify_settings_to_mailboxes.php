<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-mailbox Shopify connection: a JSON text column (secrets are stored encrypted).
 * Safe to run on a database that already has the column.
 */
class AddShopifySettingsToMailboxes extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('mailboxes', 'shopify')) {
            return;
        }

        Schema::table('mailboxes', function (Blueprint $table) {
            $table->text('shopify')->nullable();
        });
    }

    public function down()
    {
        if (Schema::hasColumn('mailboxes', 'shopify')) {
            Schema::table('mailboxes', function (Blueprint $table) {
                $table->dropColumn('shopify');
            });
        }
    }
}

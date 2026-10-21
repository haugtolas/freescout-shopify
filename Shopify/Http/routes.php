<?php

Route::group(['middleware' => 'web', 'prefix' => \Helper::getSubdirectory(), 'namespace' => 'Modules\Shopify\Http\Controllers'], function () {
    // Every route requires a logged-in user: the AJAX endpoint returns customer data.
    Route::post('/shopify/ajax', ['uses' => 'ShopifyController@ajax', 'middleware' => ['auth', 'throttle:120,1'], 'laroute' => true])->name('shopify.ajax');

    Route::get('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettings', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify');
    Route::post('/mailbox/shopify/{id}', ['uses' => 'ShopifyController@mailboxSettingsSave', 'middleware' => ['auth', 'roles'], 'roles' => ['admin']])->name('mailboxes.shopify.save');
});

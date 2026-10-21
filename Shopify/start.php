<?php

/*
|--------------------------------------------------------------------------
| Register Namespaces And Routes
|--------------------------------------------------------------------------
|
| When a module starts, this file is executed automatically: it loads the
| routes of the module.
|
*/

if (!app()->routesAreCached()) {
    require __DIR__.'/Http/routes.php';
}

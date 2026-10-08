<?php

namespace App\Http\Middleware;

/**
 * SetLocale once more, after the dealer is known (so its language applies to users who have none
 * of their own). A separate class because Laravel drops a middleware that is already in the stack.
 */
class SetLocaleForTenant extends SetLocale {}

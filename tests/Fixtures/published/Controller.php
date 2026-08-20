<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * The base controller a Laravel application publishes the admin stubs into.
 *
 * The stubs are shipped under `App\Http\Controllers\Admin` and extend this,
 * because that is where they land. The package's suite therefore has to supply
 * one — and having to supply it is the point: it proves the stubs compile as
 * published rather than as some package-internal variant of themselves.
 */
abstract class Controller
{
    use AuthorizesRequests;
}

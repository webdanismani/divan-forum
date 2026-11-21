<?php

namespace App\Http\Controllers\Admin;

use App\Forum\Security\Duplicates;
use App\Http\Controllers\Controller;

/** Accounts that share a browser or an IP address: likely the same person behind several names. */
class DuplicateController extends Controller
{
    public function index(Duplicates $duplicates)
    {
        return view('admin.duplicates', [
            'byDevice' => $duplicates->byDevice(),
            'byIp' => $duplicates->byIp(),
        ]);
    }
}

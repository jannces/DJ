<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

class AuditLogController extends Controller
{

    public function index()
    {
        abort(503, 'This module is being provisioned.');
    }
}

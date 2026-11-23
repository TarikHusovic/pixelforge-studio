<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    // TODO(admin-report agent): implement per the brief.
    public function __invoke(Request $request): Response
    {
        return Inertia::render('admin/Report');
    }
}

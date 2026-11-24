<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/** The two pages the owner uses. They are empty shells: the data is loaded by the browser, with the owner's key. */
class PageController extends Controller
{
    public function home(): View
    {
        return view('home');
    }

    public function workspace(int $id): View
    {
        return view('workspace', ['id' => $id]);
    }
}

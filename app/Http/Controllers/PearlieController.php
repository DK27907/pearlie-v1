<?php

namespace App\Http\Controllers;

use App\Services\PearlieServiceV2 as PearlieService;
use Illuminate\Http\Request;

class PearlieController extends Controller
{
    protected PearlieService $pearlie;

    public function __construct(PearlieService $pearlie)
    {
        $this->pearlie = $pearlie;
    }

    public function index()
    {
        return view('pearlie');
    }

    public function chat(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:1000',
        ]);

        $sessionId = $request->session()->getId();

        $result = $this->pearlie->processMessage(
            $request->input('message'),
            $sessionId
        );

        return response()->json($result);
    }
}
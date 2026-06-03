<?php

namespace App\Http\Controllers;

use App\Models\Version;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            Version::orderByDesc('id')->paginate((int) $request->query('per_page', 20))
        );
    }

    public function store(Request $request)
    {
        $this->validate($request, [
            'name' => 'required|string|max:255',
            'game' => 'nullable|string|max:191',
            'metadata' => 'nullable|array',
        ]);

        $version = Version::create($request->only('name', 'game', 'metadata'));

        return response()->json($version, 201);
    }

    public function show($versionId)
    {
        return response()->json(Version::findOrFail($versionId));
    }
}

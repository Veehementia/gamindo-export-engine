<?php

namespace App\Http\Controllers;

use App\Models\ExportTemplate;
use App\Models\Version;
use App\Services\Export\ExportDefinitionValidator;
use Illuminate\Http\Request;

/**
 * (Bonus) CRUD dei template di export salvabili e riutilizzabili.
 */
class TemplateController extends Controller
{
    /** @var ExportDefinitionValidator */
    private $validator;

    public function __construct(ExportDefinitionValidator $validator)
    {
        $this->validator = $validator;
    }

    public function index($versionId)
    {
        Version::findOrFail($versionId);

        return response()->json(
            ExportTemplate::where('version_id', $versionId)->orderBy('name')->get()
        );
    }

    public function store(Request $request, $versionId)
    {
        Version::findOrFail($versionId);

        $this->validate($request, [
            'name' => 'required|string|max:191',
            'description' => 'nullable|string',
            'definition' => 'required|array',
        ]);

        // Validiamo la definizione con lo stesso motore degli export.
        $definition = $this->validator->validate($request->input('definition'));

        $template = ExportTemplate::updateOrCreate(
            ['version_id' => (int) $versionId, 'name' => $request->input('name')],
            ['description' => $request->input('description'), 'definition' => $definition]
        );

        return response()->json($template, 201);
    }

    public function show($templateId)
    {
        return response()->json(ExportTemplate::findOrFail($templateId));
    }

    public function destroy($templateId)
    {
        ExportTemplate::findOrFail($templateId)->delete();

        return response()->json(null, 204);
    }
}

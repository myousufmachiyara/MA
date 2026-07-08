<?php

namespace App\Http\Controllers;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    public function index()
    {
        $locations = Location::orderByDesc('is_default')->orderBy('name')->get();
        return view('locations.index', compact('locations'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'code'       => ['required', 'string', 'max:20', Rule::unique('locations')->whereNull('deleted_at')],
            'address'    => 'nullable|string|max:250',
            'contact_no' => 'nullable|string|max:50',
        ]);

        if ($request->boolean('is_default')) {
            Location::where('is_default', true)->update(['is_default' => false]);
        }

        Location::create(array_merge($validated, [
            'is_default' => $request->boolean('is_default'),
            'is_active'  => true,
            'created_by' => auth()->id(),
        ]));

        return redirect()->route('locations.index')->with('success', 'Location created successfully.');
    }

    public function edit($id)
    {
        return response()->json(Location::findOrFail($id));
    }

    public function update(Request $request, $id)
    {
        $location = Location::findOrFail($id);

        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'code'       => ['required', 'string', 'max:20', Rule::unique('locations')->ignore($id)->whereNull('deleted_at')],
            'address'    => 'nullable|string|max:250',
            'contact_no' => 'nullable|string|max:50',
        ]);

        if ($request->boolean('is_default')) {
            Location::where('id', '!=', $id)->update(['is_default' => false]);
        }

        $location->update(array_merge($validated, ['is_default' => $request->boolean('is_default')]));

        return redirect()->route('locations.index')->with('success', 'Location updated successfully.');
    }

    public function toggleActive($id)
    {
        $location = Location::findOrFail($id);

        if ($location->is_default && $location->is_active) {
            return back()->with('error', 'Cannot deactivate the default location. Set another location as default first.');
        }

        $location->update(['is_active' => !$location->is_active]);
        return back()->with('success', $location->is_active ? 'Location activated.' : 'Location deactivated.');
    }

    public function destroy($id)
    {
        $location = Location::findOrFail($id);

        if ($location->is_default) {
            return back()->with('error', 'Cannot delete the default location.');
        }

        if ($location->stocks()->where('quantity', '>', 0)->exists()) {
            return back()->with('error', 'Cannot delete a location that still holds stock. Transfer stock out first.');
        }

        $location->delete();
        return back()->with('success', 'Location deleted successfully.');
    }
}
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ServiceType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $types = ServiceType::where('is_active', true)->orderBy('name')->get();
        return response()->json(['data' => $types]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:hardware,software',
            'default_estimated_cost' => 'required|numeric|min:0',
            'default_cost_price' => 'nullable|numeric|min:0',
        ]);

        $serviceType = ServiceType::create([
            'name' => trim($data['name']),
            'category' => $data['category'],
            'default_estimated_cost' => $data['default_estimated_cost'],
            'default_cost_price' => $data['default_cost_price'] ?? 0,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Tarif / jenis servis berhasil ditambahkan.',
            'data' => $serviceType,
        ], 201);
    }
}

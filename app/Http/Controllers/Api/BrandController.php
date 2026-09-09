<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $brands = Brand::where('is_active', true)->orderBy('name')->get();
        return response()->json(['data' => $brands]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'code' => 'nullable|string|max:50',
        ]);

        $brand = Brand::create([
            'name' => trim($data['name']),
            'code' => !empty($data['code']) ? strtoupper(trim($data['code'])) : strtoupper(substr(trim($data['name']), 0, 3)),
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Merk/Brand berhasil ditambahkan.',
            'data' => $brand,
        ], 201);
    }
}

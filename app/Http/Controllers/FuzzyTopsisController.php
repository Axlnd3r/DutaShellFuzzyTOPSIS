<?php

namespace App\Http\Controllers;

use App\Services\Inference\FuzzyTopsisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class FuzzyTopsisController extends Controller
{
    public function infer(Request $request, FuzzyTopsisService $service): JsonResponse
    {
        try {
            $result = $service->infer($request->all());
            return response()->json($result);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Terjadi kesalahan saat menjalankan Fuzzy TOPSIS.',
                'error' => $exception->getMessage(),
            ], 500);
        }
    }
}


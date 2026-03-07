<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\KonsultasiController;
use App\Services\AppwriteService;
use Appwrite\Query;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

// Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
//     return $request->user();
// });

Route::post('/v1/konsultasi', [KonsultasiController::class, 'diagnose']);

Route::get('/test-appwrite', function(AppwriteService $appwrite) {
    try {
        $db = $appwrite->database();

        // Ambil database ID dan collection ID dari .env
        $databaseId = env('APPWRITE_DATABASE_ID');
        $collectionId = env('APPWRITE_BASIS_PENGETAHUAN_COLLECTION_ID');

        if (!$databaseId || !$collectionId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Database ID atau Collection ID belum di-set di .env'
            ], 400);
        }

        // Cek apakah database dan collection bisa diakses
        $documents = $db->listDocuments($databaseId, $collectionId, [Query::limit(1)]);

        return response()->json([
            'status' => 'success',
            'database_id' => $databaseId,
            'collection_id' => $collectionId,
            'documents_found' => count($documents->documents ?? []),
        ]);
    } catch (\Appwrite\AppwriteException $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ], 500);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'message' => $e->getMessage(),
        ], 500);
    }
});
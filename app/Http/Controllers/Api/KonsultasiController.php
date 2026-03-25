<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AppwriteService;
use App\Services\DempsterShaferService;
use Appwrite\Query;
use Appwrite\ID;
use Illuminate\Support\Facades\Log;

class KonsultasiController extends Controller
{
    public function __construct(
        protected AppwriteService $appwrite,
        protected DempsterShaferService $ds
    ) {}

   public function diagnose(Request $request)
{
    $request->validate([
        'gejala' => 'required|array|min:1',
        'gejala.*.id' => 'required|string',
        'gejala.*.nama_gejala' => 'required|string',
        'pengguna_id' => 'nullable|string',
    ]);

    $db = $this->appwrite->database();

    $databaseId    = config('appwrite.database_id');
    $basisColId    = config('appwrite.basis_collection_id');
    $penyakitColId = config('appwrite.penyakit_collection_id');
    $konsultasiId  = config('appwrite.konsultasi_collection_id');

    $evidences = [];
    $selectedGejala = [];

    foreach ($request->gejala as $item) {

        $gejalaId   = $item['id'];
        $namaGejala = $item['nama_gejala'];

        $selectedGejala[] = $namaGejala;

        $response = $db->listDocuments(
            $databaseId,
            $basisColId,
            [Query::equal('gejala', $gejalaId)]
        );

        $rules = $response['documents'] ?? [];

        if (empty($rules)) continue;

        // mapping ke format DS
        $formattedRules = [];

        foreach ($rules as $rule) {

            $penyakitId = $rule['penyakit'] ?? null;

            if (!$penyakitId) continue;

            $formattedRules[] = [
                'subset' => [$penyakitId], // bisa dikembangkan multi subset
                'bobot_keyakinan' => (float)$rule['bobot_keyakinan']
            ];
        }

        $mass = $this->ds->buildMass($formattedRules);
        $evidences[] = $mass;

        Log::info("RULES RESULT:", $rules);
        Log::info("Mass untuk gejala $gejalaId:", $mass);
        Log::info("Semua evidence:", $evidences);
    }

    if (empty($evidences)) {
        return response()->json([
            'message' => 'Tidak ada evidence yang bisa diproses'
        ], 422);
    }

    // combine semua evidence
    $result = $this->ds->calculate($evidences);

    while ($result['conflict'] > 0.85 && count($evidences) > 2) {
        array_pop($evidences); // hapus gejala terakhir
        $result = $this->ds->calculate($evidences);
    }


    if ($result['conflict'] > 0.85) {

    return response()->json([
            'message' => 'Gejala tidak konsisten, silakan pilih gejala yang lebih relevan',
            'conflict' => $result['conflict']
        ], 422);
    }

    $finalMass = $result['mass'];
    $conflict  = $result['conflict'];

    // hitung belief
    $belief = $this->ds->calculateBelief($finalMass);

    if (empty($belief)) {
        $belief = $this->ds->calculatePlausibility($finalMass);
    }

    arsort($belief);

    $total = array_sum($belief) ?: 1;

    $penyakitIds = array_keys($belief);

    // HASIL UTAMA
    $utamaId = $penyakitIds[0] ?? null;
    $persenUtama = $utamaId
        ? round(($belief[$utamaId] / $total) * 100, 2)
        : 0;

    // HASIL KEDUA
    $keduaId = $penyakitIds[1] ?? null;
    $persenKedua = $keduaId
        ? round(($belief[$keduaId] / $total) * 100, 2)
        : 0;

    // GET DETAIL PENYAKIT
    $getDetail = function($id) use ($db, $databaseId, $penyakitColId) {
    try {
        $doc = $db->getDocument($databaseId, $penyakitColId, $id);

        return [
            'id' => $id,
            'kode' => $doc['kode_penyakit'] ?? '',
            'nama' => $doc['nama_penyakit'] ?? '-',
            'deskripsi' => $doc['deskripsi'] ?? '',
            'penyebab' => $doc['penyebab'] ?? '',
            'penanganan' => $doc['penanganan'] ?? '',
            'rekomendasi' => $doc['rekomendasi'] ?? '',
        ];
    } catch (\Throwable $e) {
        Log::error("Gagal ambil detail penyakit: " . $e->getMessage());
        return null;
    }
};

$detailPenyakit = [];

$namaUtama = null;
$namaKedua = null;

if ($utamaId) {
    $detail = $getDetail($utamaId);
    if ($detail) {
        $detailPenyakit[$utamaId] = $detail;
        $namaUtama = $detail['nama']; // ambil nama
    }
}

if ($keduaId && $keduaId !== $utamaId) {
    $detail = $getDetail($keduaId);
    if ($detail) {
        $detailPenyakit[$keduaId] = $detail;
        $namaKedua = $detail['nama'];
    }
}

try {
    $db->createDocument(
    $databaseId,
    $konsultasiId,
    ID::unique(),
    [
        'pengguna_id' => $request->pengguna_id,
        'gejala_dipilih' => $selectedGejala,

        'hasil_utama' => $namaUtama,
        'persentase_utama' => $persenUtama,

        'hasil_kedua' => $namaKedua,
        'persentase_kedua' => $persenKedua,

        'conflict' => $conflict,
    ]
);
} catch (\Throwable $e) {
    Log::error("Gagal simpan konsultasi: " . $e->getMessage());
}

   return response()->json([
    'hasil_utama' => $namaUtama,
    'persentase_utama' => $persenUtama,
    'hasil_kedua' => $namaKedua,
    'persentase_kedua' => $persenKedua,
    'conflict' => $conflict,
    'detail_penyakit' => $detailPenyakit,
]);
}
}
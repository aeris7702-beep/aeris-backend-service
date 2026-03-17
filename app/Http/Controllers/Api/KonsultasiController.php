<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AppwriteService;
use App\Services\EvidenceBuilderService;
use App\Services\DempsterShaferService;
use Appwrite\Query;
use Appwrite\ID;
use Illuminate\Support\Facades\Log;

class KonsultasiController extends Controller
{
    public function __construct(
        protected AppwriteService $appwrite,
        protected EvidenceBuilderService $builder,
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

        Log::info('KONSULTASI DIMULAI', [
            'pengguna_id' => $request->pengguna_id,
            'gejala' => $request->gejala
        ]);

        $db = $this->appwrite->database();

        $databaseId    = config('appwrite.database_id');
        $basisColId    = config('appwrite.basis_collection_id');
        $konsultasiId  = config('appwrite.konsultasi_collection_id');
        $penyakitColId = config('appwrite.penyakit_collection_id');

        $combinedMass = null;
        $selectedGejala = [];

        foreach ($request->gejala as $item) {

            $gejalaId   = $item['id'];
            $namaGejala = $item['nama_gejala'];
            $penggunaId = $request->input('pengguna_id');

            $selectedGejala[] = $namaGejala;

            $response = $db->listDocuments(
                $databaseId,
                $basisColId,
                [Query::equal('gejala', $gejalaId)]
            );

            $rules = $response['documents'] ?? [];

            if (empty($rules)) {
                continue;
            }

            $mass = $this->builder->build($rules);

            $combinedMass = is_null($combinedMass)
                ? $mass
                : $this->ds->combine($combinedMass, $mass);
        }

        if (empty($combinedMass)) {
            return response()->json([
                'message' => 'Tidak ditemukan basis pengetahuan yang sesuai'
            ], 422);
        }

        // HITUNG BELIEF 
        $belief = $this->calculateBelief($combinedMass);

        // Hapus noise
        $belief = array_filter($belief, fn($v) => $v > 0.0001);

        arsort($belief);

        $totalBelief = array_sum($belief);
        if ($totalBelief <= 0) {
            $totalBelief = 1;
        }

        $penyakitIds = array_keys($belief);

        // DIAGNOSIS UTAMA
        $hasilPenyakitId = $penyakitIds[0] ?? null;
        $persentaseHasil = $hasilPenyakitId
            ? round(($belief[$hasilPenyakitId] / $totalBelief) * 100, 2)
            : 0;

        $hasilPenyakitNama = '-';
        $detailPenyakit = [
            'deskripsi'   => '-',
            'penyebab'    => '-',
            'penanganan'  => '-',
            'rekomendasi' => '-',
        ];

        if ($hasilPenyakitId) {
            try {
                $doc = $db->getDocument(
                    $databaseId,
                    $penyakitColId,
                    $hasilPenyakitId
                );

                $hasilPenyakitNama = $doc['nama_penyakit'] ?? '-';
                $detailPenyakit = [
                    'deskripsi'   => $doc['deskripsi'] ?? '',
                    'penyebab'    => $doc['penyebab'] ?? '',
                    'penanganan'  => $doc['penanganan'] ?? '',
                    'rekomendasi' => $doc['rekomendasi'] ?? '',
                ];
            } catch (\Throwable $e) {}
        }

        // DIAGNOSIS KEDUA
        $kemungkinanPenyakitId = $penyakitIds[1] ?? null;
        $kemungkinanPenyakitNama = '-';
        $persentaseKemungkinan = $kemungkinanPenyakitId
            ? round(($belief[$kemungkinanPenyakitId] / $totalBelief) * 100, 2)
            : 0;

        if ($kemungkinanPenyakitId) {
            try {
                $doc = $db->getDocument(
                    $databaseId,
                    $penyakitColId,
                    $kemungkinanPenyakitId
                );

                $kemungkinanPenyakitNama = $doc['nama_penyakit'] ?? '-';
            } catch (\Throwable $e) {}
        }

        Log::info('HASIL DIAGNOSIS', [
            'utama' => $hasilPenyakitNama,
            'persen' => $persentaseHasil,
            'kedua' => $kemungkinanPenyakitNama,
            'persen_kedua' => $persentaseKemungkinan
        ]);

        // SIMPAN KE DATABASE
        try {
            $db->createDocument(
                $databaseId,
                $konsultasiId,
                ID::unique(),
                [
                    'idPengguna'               => $penggunaId,
                    'daftar_gejala'           => $selectedGejala,
                    'hasil_diagnosis'         => $hasilPenyakitNama,
                    'persentase_hasil'        => $persentaseHasil,
                    'kemungkinan_diagnosis'   => $kemungkinanPenyakitNama,
                    'persentase_kemungkinan'  => $persentaseKemungkinan,
                    'tanggal_konsultasi'      => now()->toIso8601String(),
                ]
            );
        } catch (\Throwable $e) {}

        // RESPONSE
        return response()->json([
            'hasil_diagnosis' => [
                'id'   => $hasilPenyakitId,
                'nama_penyakit' => $hasilPenyakitNama,
            ],
            'persentase_hasil' => $persentaseHasil,
            'kemungkinan_diagnosis' => $kemungkinanPenyakitNama,
            'persentase_kemungkinan' => $persentaseKemungkinan,
            'detail_penyakit' => $detailPenyakit,
        ]);
    }

    // FUNGSI BELIEF (WAJIB)
    private function calculateBelief(array $mass): array
    {
        $belief = [];

        foreach ($mass as $hypothesis => $value) {
            if ($hypothesis === 'theta') continue;

            $parts = explode(',', $hypothesis);

            foreach ($parts as $p) {
                if (!isset($belief[$p])) {
                    $belief[$p] = 0;
                }

                $belief[$p] += $value;
            }
        }

        return $belief;
    }
}
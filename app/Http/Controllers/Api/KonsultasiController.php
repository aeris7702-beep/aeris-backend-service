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
    ) {
    }

    public function diagnose(Request $request)
    {
        $request->validate([
            'gejala' => 'required|array|min:1',
            'gejala.*.id' => 'required|string',
            'gejala.*.nama_gejala' => 'required|string',
            'pengguna_id' => 'nullable|string',
        ]);

        // Tambahan Stabilitas Urutan gejala berdasarkan kode_gejala
        $gejala = collect($request->gejala)
            ->sortBy('kode_gejala')
            ->values()
            ->all();


        $db = $this->appwrite->database();

        $databaseId    = config('appwrite.database_id');
        $basisColId    = config('appwrite.basis_collection_id');
        $penyakitColId = config('appwrite.penyakit_collection_id');
        $konsultasiId  = config('appwrite.konsultasi_collection_id');

        $evidences = [];
        $selectedGejala = [];

        foreach ($gejala as $item) {
            // foreach ($request->gejala as $item) {
            $gejalaId   = $item['id'];
            $namaGejala = $item['nama_gejala'];
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
            // mapping ke format DS
            $formattedRules = [];

            foreach ($rules as $rule) {
                $penyakitId = $rule['penyakit'] ?? null;
                if (!$penyakitId) {
                    continue;
                }
                $formattedRules[] = [
                    'subset' => [$penyakitId],
                    'bobot_keyakinan' => (float)$rule['bobot_keyakinan']
                ];
            }

            $mass = $this->ds->buildMass($formattedRules);
            $evidences[] = $mass;

        }

        if (empty($evidences)) {
            return response()->json([
                'message' => 'Tidak ada evidence yang bisa diproses'
            ], 422);
        }

        // combine semua evidence
        $result = $this->ds->calculate($evidences);

        $finalMass = $result['mass'];
        $conflict  = $result['conflict'];

        // hitung belief
        $belief = $this->ds->calculateBelief($finalMass);
        $plausibility = $this->ds->calculatePlausibility($finalMass);
        $allKeys = array_unique(array_merge(
            array_keys($belief),
            array_keys($plausibility)
        ));

        $diagnosisDetail = [];

        foreach ($allKeys as $penyakitId) {
            if ($penyakitId === 'theta') {
                continue;
            }
            $bel = $belief[$penyakitId] ?? 0;
            $pl  = $plausibility[$penyakitId] ?? 0;
            $mid = ($bel + $pl) / 2;
            $diagnosisDetail[$penyakitId] = [
                'belief' => round($bel * 100, 2),
                'plausibility' => round($pl * 100, 2),
                'interval' => [
                    'min' => round($bel * 100, 2),
                    'max' => round($pl * 100, 2),
                ],
                'score' => round($mid * 100, 2),
                'interpretasi' => $this->ds->interpretScore($bel, $pl),
            ];
        }

        // $status = 'Normal';

        if ($conflict > 0.8) {
            $status = 'Konflik tinggi - kemungkinan multi penyakit';
        } elseif ($conflict > 0.6) {
            $status = 'Diagnosis kurang pasti';
        } else {
            $status = 'Diagnosis cukup kuat';
        }

        $ranking = [];
        foreach ($diagnosisDetail as $id => $data) {
            $ranking[$id] = $data['score'];
        }

        arsort($ranking);

        $top = array_slice($ranking, 0, 3, true);
        $topKeys = array_keys($top);
        $utamaId = $topKeys[0] ?? null;
        $keduaId = $topKeys[1] ?? null;

        $total = array_sum($ranking) ?: 1;

        $persenUtama = $utamaId
            ? round(($ranking[$utamaId] / $total) * 100, 2)
            : 0;

        $persenKedua = $keduaId
            ? round(($ranking[$keduaId] / $total) * 100, 2)
            : 0;

        // Detail Penyakit
        $getDetail = function ($id) use ($db, $databaseId, $penyakitColId) {
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
                $namaUtama = $detail['nama'];
            }
        }

        // Detail penyakit Full
        // if ($keduaId && $keduaId !== $utamaId) {
        //     $detail = $getDetail($keduaId);
        //     if ($detail) {
        //         $detailPenyakit[$keduaId] = $detail;
        //         $namaKedua = $detail['nama'];
        //     }
        // }

        // Hanya Menampilkan 1 Detail penyakit Saja
        if ($keduaId && $keduaId !== $utamaId) {
            $detail = $getDetail($keduaId);

            if ($detail) {
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
         'diagnosis_detail' => $diagnosisDetail,
]);
    }
}

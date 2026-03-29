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

        // Kondisi Jika Konflik terlalu besar/gejala bertentangan
        //     if ($result['conflict'] > 0.85) {
        //     return response()->json([
        //         'message' => 'Gejala saling bertentangan, hasil diagnosis tidak cukup pasti',
        //         'conflict' => $result['conflict'],
        //         'saran' => 'Silakan kurangi atau perbaiki pilihan gejala'
        //     ], 422);
        // }
        // if ($result['conflict'] > 0.85) {

        // return response()->json([
        //         'message' => 'Gejala tidak konsisten, silakan pilih gejala yang lebih relevan',
        //         'conflict' => $result['conflict']
        //     ], 422);
        // }

        $finalMass = $result['mass'];
        $conflict  = $result['conflict'];

        // hitung belief
        $belief = $this->ds->calculateBelief($finalMass);
        $plausibility = $this->ds->calculatePlausibility($finalMass);

        //     if (empty($belief) || max($belief) < 0.2) {
        //     $belief = $this->ds->calculatePlausibility($finalMass);
        // }

        // $maxBelief = 0;
        // if (!empty($belief)) {
        //     $maxBelief = max($belief);
        // } else {
        //     $belief = $this->ds->calculatePlausibility($finalMass);
        //     if (!empty($belief)) {
        //         $maxBelief = max($belief);
        //     }
        // }


        $status = 'Normal';

        if ($conflict > 0.8) {
            $status = 'Konflik tinggi - kemungkinan multi penyakit';
        } elseif ($conflict > 0.6) {
            $status = 'Diagnosis kurang pasti';
        } else {
            $status = 'Diagnosis cukup kuat';
        }
        // Belief dan Plausibility berbeda
        // // $penyakitIds = array_keys($belief);

        // // HASIL UTAMA
        // // $utamaId = $penyakitIds[0] ?? null;
        // arsort($belief);
        // $utamaId = array_key_first($belief);

        // // HASIL KEDUA
        // // $keduaId = $penyakitIds[1] ?? null;
        // unset($plausibility[$utamaId]);
        // if (!empty($plausibility)) {
        //     arsort($plausibility);
        //     $keduaId = array_key_first($plausibility);
        // } else {
        //     $keduaId = null;
        // }

        // // Persentase Perhitungan
        // // $total = array_sum($belief) ?: 1;
        // $totalBelief = array_sum($belief) ?: 1;
        // $totalPl = array_sum($plausibility) ?: 1;

        // $persenUtama = $utamaId
        //     ? round(($belief[$utamaId] / $totalBelief) * 100, 2)
        //     : 0;

        // $persenKedua = $keduaId
        // ? round($plausibility[$keduaId] * 100, 2)
        // : 0;


        // Belief dan plausibiliy Gabungan
        // 4. Urutkan berdasarkan belief
        $ranking = $finalMass;
        unset($ranking['theta']);
        arsort($ranking);

        $top = array_slice($ranking, 0, 3, true);

        if (max($ranking) == 0) {
            $ranking = $this->ds->calculatePlausibility($finalMass);
        }


        // $beliefKeys = array_keys($belief);
        $topKeys = array_keys($top);

        $utamaId = $topKeys[0] ?? null;
        $keduaId = $topKeys[1] ?? null;

        // $utamaId = $beliefKeys[0] ?? null;
        // $keduaId = $beliefKeys[1] ?? null;

        // 5. Persentase berdasarkan total belief
        $totalBelief = array_sum($belief) ?: 1;
        $persenUtama = $utamaId ? round(($belief[$utamaId] / $totalBelief) * 100, 2) : 0;
        $persenKedua = $keduaId ? round(($belief[$keduaId] / $totalBelief) * 100, 2) : 0;

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

        if ($keduaId && $keduaId !== $utamaId) {
            $detail = $getDetail($keduaId);
            if ($detail) {
                $detailPenyakit[$keduaId] = $detail;
                $namaKedua = $detail['nama'];
            }
        }

        // try {
        //     $db->createDocument(
        //     $databaseId,
        //     $konsultasiId,
        //     ID::unique(),
        //     [
        //         'pengguna_id' => $request->pengguna_id,
        //         'gejala_dipilih' => $selectedGejala,

        //         'hasil_utama' => $namaUtama,
        //         'persentase_utama' => $persenUtama,

        //         'hasil_kedua' => $namaKedua,
        //         'persentase_kedua' => $persenKedua,

        //         'conflict' => $conflict,
        //     ]
        // );
        // } catch (\Throwable $e) {
        //     Log::error("Gagal simpan konsultasi: " . $e->getMessage());
        // }

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

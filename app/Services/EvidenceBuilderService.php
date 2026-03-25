<?php

namespace App\Services;
use Illuminate\Support\Facades\Log;

class EvidenceBuilderService
{
    /**
     * Membentuk Basic Probability Assignment (BPA)
     *
     * Fungsi:
     * Mengubah data gejala dari basis pengetahuan menjadi nilai keyakinan
     * terhadap penyakit tertentu.
     *
     * Tujuan:
     * Agar gejala yang dipilih pengguna bisa direpresentasikan dalam bentuk
     * angka keyakinan yang dapat dihitung oleh metode Dempster-Shafer.
     */

    public function build(array $rules): array
{
    Log::info('EVIDENCE BUILDER - START', [
        'total_rules' => count($rules)
    ]);

    $mass = [];
    $penyakitSet = [];
    $maxWeight = 0.0;

    foreach ($rules as $rule) {
        if (!isset($rule['bobot_keyakinan'], $rule['penyakit'])) {
            continue;
        }

        $wRel = (float) $rule['bobot_keyakinan'];
        if ($wRel <= 0) continue;

        if ($wRel > 1) $wRel = 1;

        $penyakitId = is_array($rule['penyakit'])
            ? ($rule['penyakit']['$id'] ?? null)
            : $rule['penyakit'];

        if (!$penyakitId) continue;

        $penyakitSet[] = $penyakitId;

        // Ambil bobot terbesar
        $maxWeight = max($maxWeight, $wRel);
    }

    // Jika tidak ada data
    if (empty($penyakitSet)) {
        return ['theta' => 1.0];
    }

    // Buat subset (ini kunci DS)
    sort($penyakitSet);
    $key = implode(',', array_unique($penyakitSet));

    $mass[$key] = $maxWeight;

    // Sisanya ke theta
    $mass['theta'] = 1 - $maxWeight;

    Log::info('EVIDENCE BUILDER - FINAL MASS FUNCTION', [
        'mass_function' => $mass
    ]);

    return $mass;
}
} 
<?php

namespace App\Services;

class EvidenceBuilderService
{
    /**
     * Membangun mass function (Basic Probability Assignment)
     * dari satu gejala.
     *
     * Frame of Discernment (Θ):
     * Seluruh penyakit dalam basis pengetahuan.
     *
     * Rumus:
     *   m(P) = bobot_keyakinan
     *   m(Θ) = 1 − Σ m(P)
     */

    public function build(array $rules): array
    {
        $mass = [];
        $total = 0.0;

        foreach ($rules as $rule) {

            if (!isset($rule['bobot_keyakinan'], $rule['penyakit'])) {
                continue;
            }

            $wRel = (float) $rule['bobot_keyakinan'];

            // Memastikan bobot berada di range valid
            if ($wRel <= 0.0) {
                continue;
            }

            if ($wRel > 1.0) {
                $wRel = 1.0;
            }

            $penyakitId = is_array($rule['penyakit'])
                ? ($rule['penyakit']['$id'] ?? null)
                : $rule['penyakit'];

            if (!$penyakitId) {
                continue;
            }

            $mass[$penyakitId] = ($mass[$penyakitId] ?? 0.0) + $wRel;
            $total += $wRel;
        }

        /**
         * Normalisasi jika total > 1
         */
        if ($total > 1.0) {
            foreach ($mass as $k => $v) {
                $mass[$k] = $v / $total;
            }
            $total = 1.0;
        }

        /**
         * Menghindari Dari floating precision error
         */
        $total = min(max($total, 0.0), 1.0);

        /**
         * Jika Sisa massa dialokasikan ke Θ
         */
        $mass['theta'] = max(0.0, 1.0 - $total);

        return $mass;
    }
} 
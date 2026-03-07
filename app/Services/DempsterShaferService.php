<?php

namespace App\Services;

class DempsterShaferService
{
    /**
     * Kombinasi dua mass function m1 ⊕ m2
     * Pendekatan singleton sesuai teori Dempster-Shafer.
     */

    public function combine(array $m1, array $m2): array
    {
        $result = [];
        $K = 0.0;

        foreach ($m1 as $h1 => $v1) {
            foreach ($m2 as $h2 => $v2) {

                $product = $v1 * $v2;

                if ($product <= 0.0) {
                    continue;
                }

                /**
                 * mengkonversi hipotesis menjadi himpunan
                 */
                $set1 = ($h1 === 'theta') ? ['theta'] : explode(',', $h1);
                $set2 = ($h2 === 'theta') ? ['theta'] : explode(',', $h2);

                /**
                 * Jika salah satu theta menjadi hasil adalah himpunan lain
                 */
                if ($h1 === 'theta') {
                    $intersection = $set2;
                } elseif ($h2 === 'theta') {
                    $intersection = $set1;
                } else {
                    $intersection = array_values(array_intersect($set1, $set2));
                }

                /**
                 * Apabila irisan kosong menjadi konflik
                 */
                if (empty($intersection)) {
                    $K += $product;
                    continue;
                }

                sort($intersection);
                $key = implode(',', $intersection);

                $result[$key] = ($result[$key] ?? 0.0) + $product;
            }
        }

        /**
         * Menyatukan konflik agar stabil numerik
         */
        $K = min(max($K, 0.0), 1.0);

        /**
         * Apabila konflik sangat tinggi menjadi ketidakpastian penuh
         */
        if ($K >= 0.999999) {
            return ['theta' => 1.0];
        }

        $normalizer = 1.0 - $K;

        /**
         * Menghindari pembagian 0
         */
        if ($normalizer <= 0.0) {
            return ['theta' => 1.0];
        }

        /**
         * Normalisasikan hasil
         */
        foreach ($result as $k => $v) {
            $value = $v / $normalizer;
            $result[$k] = min(max($value, 0.0), 1.0);
        }

        /**
         * Memastikan total tetap 1
         */
        $sum = array_sum($result);

        if ($sum > 0.0) {
            foreach ($result as $k => $v) {
                $result[$k] = $v / $sum;
            }
        }

        return $result;
    }
}
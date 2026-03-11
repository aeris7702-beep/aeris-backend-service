<?php

namespace App\Services;

class DempsterShaferService
{
    /**
     * Proses Menggabungkan dua evidence menggunakan aturan Dempster
     *
     * Fungsi:
     * Menggabungkan keyakinan dari dua gejala yang berbeda.
     *
     * Tujuan:
     * Mendapatkan tingkat keyakinan baru yang lebih kuat terhadap
     * suatu penyakit berdasarkan beberapa gejala.
     */

    public function combine(array $m1, array $m2): array
    {
    // Proses Menyimpan hasil kombinasi evidence
    // Tujuannya untuk Menyimpan nilai keyakinan akhir setiap penyakit
    $result = [];

    // Proses Menyimpan nilai konflik antar evidence
    // Tujuannya untuk Mengetahui seberapa besar gejala saling bertentangan

    $conflict = 0.0;

    foreach ($m1 as $h1 => $v1) {
        foreach ($m2 as $h2 => $v2) {

            /**
            * Proses Mengalikan massa evidence
            *
            * Fungsi:
            * Menggabungkan kekuatan keyakinan dari dua gejala.
            *
            * Tujuan:
            * Menghitung kontribusi kedua gejala terhadap diagnosis.
            */
            $product = $v1 * $v2;

            if ($product == 0) {
                continue;
            }

            $set1 = $h1 === 'theta' ? ['theta'] : explode(',', $h1);
            $set2 = $h2 === 'theta' ? ['theta'] : explode(',', $h2);

            /**
            * Proses Menghitung irisan hipotesis
            *
            * Fungsi:
            * Menentukan penyakit yang didukung oleh kedua evidence.
            *
            * Tujuan:
            * Memperkuat diagnosis jika dua gejala mendukung penyakit yang sama.
            */
            if ($h1 === 'theta') {
                $intersection = $set2;
            } elseif ($h2 === 'theta') {
                $intersection = $set1;
            } else {
                $intersection = array_intersect($set1, $set2);
            }

            /**
            * Proses Menghitung konflik evidence
            *
            * Fungsi:
            * Mengukur pertentangan antara dua gejala.
            *
            * Tujuan:
            * Jika dua gejala mendukung penyakit berbeda,
            * maka terjadi konflik informasi.
            */
            if (empty($intersection)) {
                $conflict += $product;
                continue;
            }

            sort($intersection);
            $key = implode(',', $intersection);

            /**
            * Proses Menyimpan hasil kombinasi
            *
            * Fungsi:
            * Menambahkan kontribusi evidence terhadap penyakit.
            *
            * Tujuan:
            * Mengakumulasi keyakinan dari berbagai gejala.
            */
            $result[$key] = ($result[$key] ?? 0) + $product;
        }
    }

    /**
    * Proses Menghitung faktor normalisasi
    *
    * Fungsi:
    * Menghilangkan pengaruh konflik dalam hasil akhir.
    *
    * Tujuan:
    * Agar total keyakinan tetap valid.
    */
    $normalizer = 1 - $conflict;

    /**
    * Proses Menangani konflik total
    *
    * Fungsi:
    * Jika semua evidence bertentangan, sistem tetap memberikan
    * kemungkinan diagnosis.
    *
    * Tujuan:
    * Menghindari kondisi sistem tidak memberikan hasil.
    */
    if ($normalizer <= 0) {
        $union = [];
        foreach ($m1 as $k => $v) {
            if ($k === 'theta') {
                continue;
            }
            $union[$k] = ($union[$k] ?? 0.0) + $v;
        }
        foreach ($m2 as $k => $v) {
            if ($k === 'theta') {
                continue;
            }
            $union[$k] = ($union[$k] ?? 0.0) + $v;
        }

        $sumUnion = array_sum($union);

        if ($sumUnion <= 0.0) {
            return ['theta' => 1.0];
        }

        /**
        * Proses Redistribusi massa
        *
        * Fungsi:
        * Membagi kembali keyakinan secara proporsional.
        *
        * Tujuan:
        * Sistem tetap memberikan kemungkinan penyakit.
        */
        foreach ($union as $k => $v) {
            $union[$k] = $v / $sumUnion;
        }

        return $union;
    }

    /**
    * Proses Normalisasi hasil kombinasi
    *
    * Fungsi:
    * Menghitung nilai akhir keyakinan penyakit.
    *
    * Tujuan:
    * Menghasilkan distribusi keyakinan yang valid.
    */
    foreach ($result as $k => $v) {
        $result[$k] = $v / $normalizer;
    }

    /**
    * Proses Stabilisasi hasil akhir
    *
    * Fungsi:
    * Memastikan total massa sama dengan 1.
    *
    * Tujuan:
    * Menjaga konsistensi perhitungan metode.
    */
    $sum = array_sum($result);
    if ($sum > 0) {
        foreach ($result as $k => $v) {
            $result[$k] = $v / $sum;
        }
    }

    return $result;
    }
}
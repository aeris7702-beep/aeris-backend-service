<?php

namespace App\Services;

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
        // Proses Menyimpan nilai massa untuk setiap penyakit
        // Tujuannya untuk Menyimpan tingkat keyakinan terhadap tiap hipotesis penyakit
        $mass = [];

        // Proses Menghitung total seluruh nilai keyakinan
        // Tujuannya Digunakan untuk menentukan sisa massa ketidaktahuan (theta)
        $total = 0.0; // Total massa untuk semua hipotesis penyakit

        foreach ($rules as $rule) {

            // Proses Validasi data aturan
            // Tujuannya Memastikan aturan memiliki bobot keyakinan dan penyakit
            if (!isset($rule['bobot_keyakinan'], $rule['penyakit'])) {
                continue;
            }
            
            // Proses Mengambil bobot keyakinan dari rule
            // Tujuannya untuk Menentukan seberapa kuat gejala mendukung penyakit
            $wRel = (float) $rule['bobot_keyakinan'];

            // Proses Mengabaikan bobot tidak valid
            // Tujuannya Menghindari nilai keyakinan yang tidak logis
            if ($wRel <= 0.0) {
                continue;
            }

            // Proses Membatasi nilai maksimal bobot
            // Tujuannya agar Menjaga nilai tetap dalam rentang teori (0–1)
            if ($wRel > 1.0) {
                $wRel = 1.0;
            }

            // Proses Mengambil ID penyakit
            // Tujuannya untuk Menentukan penyakit yang didukung oleh gejala
            $penyakitId = is_array($rule['penyakit'])
                ? ($rule['penyakit']['$id'] ?? null)
                : $rule['penyakit'];

            if (!$penyakitId) {
                continue;
            }

            /**
             * Proses Menambahkan massa ke penyakit
             *
             * Fungsi:
             * Memberikan nilai keyakinan pada penyakit tertentu.
             *
             * Tujuan:
             * Menunjukkan bahwa gejala tersebut mendukung penyakit tersebut.
             */
            $mass[$penyakitId] = ($mass[$penyakitId] ?? 0.0) + $wRel;
           
            // Proses Menjumlahkan total keyakinan
            // Tujuannya Untuk menghitung sisa massa ketidaktahuan
            $total += $wRel;
        }

        /**
         * Proses dalam Normalisasi jika total melebihi 1
         *
         * Fungsi:
         * Menyesuaikan semua nilai agar tetap valid secara matematis.
         *
         * Tujuan:
         * Dalam teori Dempster-Shafer total massa tidak boleh melebihi 1.
         */
        if ($total > 1.0) {
            foreach ($mass as $k => $v) {
                $mass[$k] = $v / $total;
            }
            $total = 1.0;
        }

        /**
         * Proses Menstabilkan nilai floating
         *
         * Fungsi:
         * Menghindari kesalahan pembulatan angka komputer.
         *
         * Tujuan:
         * Menjaga hasil perhitungan tetap akurat.
         */
        $total = min(max($total, 0.0), 1.0);

        /**
         * Proses Menambahkan massa ketidaktahuan (Theta)
         *
         * Fungsi:
         * Menyimpan bagian keyakinan yang belum bisa dipastikan
         * ke penyakit tertentu.
         *
         * Tujuan:
         * Dalam kondisi nyata, tidak semua gejala langsung
         * menentukan penyakit secara pasti.
         */
        $mass['theta'] = max(0.0, 1.0 - $total);

        return $mass;
    }
} 
<?php

namespace App\Services;

class DempsterShaferService
{
    /**
     * Membuat Fungsi Massa (SSF)
     * Setiap gejala jadi 1 evidence
     */
    public function buildMass(array $rules): array
    {
        $mass = [];
        $total = 0;

        foreach ($rules as $rule) {

            $subset = $rule['subset'];
            sort($subset);

            $key = implode(',', $subset);
            $bobot = (float) $rule['bobot_keyakinan'];

            if ($bobot <= 0) {
                continue;
            }

            if (!isset($mass[$key])) {
                $mass[$key] = 0;
            }

            $mass[$key] += $bobot;
            $total += $bobot;
        }

        // theta = 1 - total
        $theta = 1 - $total;

        if ($theta < 0) {
            // normalisasi
            foreach ($mass as $k => $v) {
                $mass[$k] = $v / $total;
            }
            $theta = max(1 - $total, 0.2);
        }

        $mass['theta'] = $theta;

        return $mass;
    }

    /**
     * Intersection
     */
    private function intersect($a, $b)
    {
        if ($a === 'theta') {
            return $b;
        }
        if ($b === 'theta') {
            return $a;
        }

        $setA = explode(',', $a);
        $setB = explode(',', $b);

        $intersect = array_intersect($setA, $setB);
        if (empty($intersect)) {
            return null;
        }
        // if (empty($intersect)) {
        //     return 'theta';
        // }

        sort($intersect);

        return implode(',', $intersect);
    }

    /**
     * Kombinasi 2 Massa (Dempster Rule)
     */
    public function combine(array $m1, array $m2): array
    {
        $result = [];
        $conflict = 0;

        foreach ($m1 as $h1 => $v1) {
            foreach ($m2 as $h2 => $v2) {

                $nilai = $v1 * $v2;
                $intersection = $this->intersect($h1, $h2);

                // if ($intersection === null) {
                //     $intersection = 'theta';
                // }else {
                //     if (!isset($result[$intersection])) {
                //         $result[$intersection] = 0;
                //     }
                //     $result[$intersection] += $nilai;
                // }

                if ($intersection === null) {
                    $conflict += $nilai;
                } else {
                    if (!isset($result[$intersection])) {
                        $result[$intersection] = 0;
                    }
                    $result[$intersection] += $nilai;
                }
            }
        }

        // Tambahan
        if (!isset($result['theta'])) {
            $result['theta'] = 0;
        }

        // Normalisasi
        // $normalizer = 1 - $conflict;
        $normalizer = max(1 - $conflict, 0.0001);

        if ($conflict > 0.9) {
            return [
                'mass' => $this->softmaxFallback($m1, $m2),
                'conflict' => $conflict
            ];
        }

        // if ($normalizer <= 0) {
        //     return [
        //         'mass' => ['theta' => 1],
        //         'conflict' => $conflict
        //     ];
        // }

        foreach ($result as $k => $v) {
            $result[$k] = $v / $normalizer;
        }

        return [
            'mass' => $result,
            'conflict' => $conflict
        ];
    }

    /**
     * Kombinasi Bertahap setelah proses kombinasi 2 massa
     * m1 ⊕ m2 ⊕ m3 ⊕ ...
     */
    public function calculate(array $evidences): array
    {
        if (empty($evidences)) {
            return [
                'mass' => [],
                'conflict' => 0
            ];
        }

        $currentMass = array_shift($evidences);
        $totalConflict = 0;

        foreach ($evidences as $evidence) {

            $res = $this->combine($currentMass, $evidence);

            $currentMass = $res['mass'];
            $totalConflict = $res['conflict'];
            // $totalConflict += $res['conflict'];
        }

        return [
            'mass' => $currentMass,
            'conflict' => $totalConflict
        ];
    }

    /**
     * Belief
     */
    public function calculateBelief(array $mass): array
    {
        $belief = [];

        foreach ($mass as $A => $vA) {

            if ($A === 'theta') {
                continue;
            }

            $setA = explode(',', $A);

            foreach ($mass as $B => $vB) {

                if ($B === 'theta') {
                    continue;
                }

                $setB = explode(',', $B);

                // B ⊆ A
                if (empty(array_diff($setB, $setA))) {

                    if (!isset($belief[$A])) {
                        $belief[$A] = 0;
                    }

                    $belief[$A] += $vB;
                }
            }
        }

        return $belief;
    }

    /**
     * Plausibility
     */
    public function calculatePlausibility(array $mass): array
    {
        $pl = [];

        foreach ($mass as $A => $vA) {

            if ($A === 'theta') {
                continue;
            }

            foreach ($mass as $B => $vB) {

                if ($B === 'theta') {
                    $pl[$A] = ($pl[$A] ?? 0) + $vB;
                    continue;
                }

                $intersect = array_intersect(
                    explode(',', $A),
                    explode(',', $B)
                );

                if (!empty($intersect)) {
                    $pl[$A] = ($pl[$A] ?? 0) + $vB;
                }
            }
        }

        return $pl;
    }

    private function softmaxFallback($m1, $m2)
    {
        $combined = [];

        foreach ($m1 as $k => $v) {
            if ($k !== 'theta') {
                $combined[$k] = ($combined[$k] ?? 0) + $v;
            }
        }

        foreach ($m2 as $k => $v) {
            if ($k !== 'theta') {
                $combined[$k] = ($combined[$k] ?? 0) + $v;
            }
        }

        $total = array_sum($combined) ?: 1;

        foreach ($combined as $k => $v) {
            $combined[$k] = $v / $total;
        }

        return $combined;
    }
}

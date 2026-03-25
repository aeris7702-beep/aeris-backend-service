<?php

namespace App\Services;

class DempsterShaferService
{
    /**
     * Build Mass Function (SUPPORT SUBSET)
     */
    public function buildMass(array $rules): array
    {
        $mass = [];
        $total = 0;

        foreach ($rules as $rule) {

            // subset: ['P1'] atau ['P1','P2']
            $subset = $rule['subset'];
            sort($subset);

            $key = implode(',', $subset);
            $bobot = (float) $rule['bobot_keyakinan'];

            if ($bobot < 0) {
                throw new \Exception("Bobot tidak boleh negatif");
            }

            if (!isset($mass[$key])) {
                $mass[$key] = 0;
            }

            $mass[$key] += $bobot;
            $total += $bobot;
        }

        if ($total > 1) {
            foreach ($mass as $k => $v) {
                $mass[$k] = $v / $total;
            }
            $total = 1;
        }

        // Θ (ignorance)
        $mass['theta'] = 1 - $total;

        return $mass;
    }

    /**
     * INTERSECTION (FULL SUBSET SUPPORT)
     */
    private function intersect($a, $b)
    {
        if ($a === 'theta') return $b;
        if ($b === 'theta') return $a;

        $setA = explode(',', $a);
        $setB = explode(',', $b);

        $intersect = array_intersect($setA, $setB);

        if (empty($intersect)) return null;

        sort($intersect);

        return implode(',', $intersect);
    }

    /**
     * KOMBINASI DEMPSTER + KONFLIK
     */
    public function combine(array $m1, array $m2): array
    {
        $result = [];
        $conflict = 0;

        foreach ($m1 as $h1 => $v1) {
            foreach ($m2 as $h2 => $v2) {

                $intersection = $this->intersect($h1, $h2);
                $nilai = $v1 * $v2;

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

        $normalization = 1 - $conflict;

        if ($normalization == 0) {
            return [
                'mass' => ['theta' => 1],
                'conflict' => 1
            ];
        }

        foreach ($result as $k => $v) {
            $result[$k] = $v / $normalization;
        }

        return [
            'mass' => $result,
            'conflict' => $conflict
        ];
    }

    /**
     * ITERASI MULTI EVIDENCE
     */
    public function calculate(array $evidences): array
    {
        if (empty($evidences)) {
            return [
                'mass' => [],
                'conflict' => 0
            ];
        }

        $combined = array_shift($evidences);
        $totalConflict = 0;

        foreach ($evidences as $mass) {
            $res = $this->combine($combined, $mass);

            $combined = $res['mass'];
            $totalConflict += $res['conflict'];
        }

        return [
            'mass' => $combined,
            'conflict' => $totalConflict
        ];
    }

    public function calculateBelief(array $mass): array
{
    $belief = [];

    foreach ($mass as $A => $vA) {

        if ($A === 'theta') continue;

        $setA = explode(',', $A);

        foreach ($mass as $B => $vB) {

            if ($B === 'theta') continue;

            $setB = explode(',', $B);

            // cek apakah B subset dari A
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
     * PLAUSIBILITY FUNCTION
     */
    public function calculatePlausibility(array $mass): array
    {
        $pl = [];

        foreach ($mass as $A => $vA) {

            if ($A === 'theta') continue;

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

    /**
     * MULTI RESULT + THRESHOLD
     */
    public function getRanking(array $mass, float $threshold = 0.6): array
    {
        unset($mass['theta']);

        if (empty($mass)) {
            return [
                'status' => 'tidak_pasti',
                'data' => []
            ];
        }

        arsort($mass);

        $results = [];

        foreach ($mass as $k => $v) {
            $results[] = [
                'penyakit' => $k,
                'nilai' => $v
            ];
        }

        // cek threshold
        $filtered = array_filter($results, function ($r) use ($threshold) {
            return $r['nilai'] >= $threshold;
        });

        return [
            'status' => empty($filtered) ? 'tidak_pasti' : 'ok',
            'data' => $results
        ];
    }

    /**
     * INTERPRETASI KONFLIK
     */
    public function interpretConflict($k)
    {
        if ($k < 0.3) return 'rendah';
        if ($k < 0.7) return 'sedang';
        return 'tinggi';
    }
}
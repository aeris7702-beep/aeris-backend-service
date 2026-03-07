<?php

namespace App\Helpers;

class CertaintyScaleHelper
{
    public static function weight(string $level): float
    {
        return match ($level) {
            'sangat_tinggi' => 1.00,
            'tinggi'        => 0.80,
            'sedang'        => 0.60,
            'rendah'        => 0.40,
            'sangat_rendah' => 0.20,
            default         => 0.0,
        };
    }
}

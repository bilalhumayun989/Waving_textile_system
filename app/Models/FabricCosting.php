<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FabricCosting extends Model
{
    protected $fillable = ['name', 'quantity', 'read', 'pick', 'warp_count', 'weft_count', 'width', 'yarn_warp_rate', 'yarn_weft_rate',
        'conversion_rate', 'warp_wt_40m', 'weft_wt_40m', 'warp_weight_1m', 'weft_weight_1m', 'total_weight_1m_lb', 'total_weight_1m_kg',
        'width_m', 'gsm', 'warp_bags', 'weft_bags', 'warp_amount_per_mtr', 'weft_amount_per_mtr', 'conversion_per_mtr',
        'fabric_rate_per_mtr', 'contract_value', 'conv_value', 'yarn_value', 'sale_tax_rate', 'sales_tax_amount'];

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function calculate(array $input): array
    {
        $quantity = (float) $input['quantity'];
        $read = (float) $input['read'];
        $pick = (float) $input['pick'];
        $warpCount = (float) $input['warp_count'];
        $weftCount = (float) $input['weft_count'];
        $width = (float) $input['width'];
        $warpRate = (float) $input['yarn_warp_rate'];
        $weftRate = (float) $input['yarn_weft_rate'];
        $conversionRate = (float) $input['conversion_rate'];
        $warpWeight = floor((($read * $width / $warpCount / 20 * 1.0936) / 40) * 10000) / 10000;
        $weftWeight = floor((($pick * $width / $weftCount / 20 * 1.0936) / 40) * 10000) / 10000;
        $warpAmount = floor($warpWeight * $warpRate * 10000) / 10000;
        $weftAmount = floor($weftWeight * $weftRate * 10000) / 10000;
        $conversionPerMeter = $pick * $conversionRate;
        $fabricRate = $warpAmount + $weftAmount + $conversionPerMeter;
        $contractValue = $fabricRate * $quantity;
        $taxRate = 0.17;

        return ['name' => $input['name'] ?? null, 'quantity' => $quantity, 'read' => $read, 'pick' => $pick,
            'warp_count' => $warpCount, 'weft_count' => $weftCount, 'width' => $width, 'yarn_warp_rate' => $warpRate,
            'yarn_weft_rate' => $weftRate, 'conversion_rate' => $conversionRate,
            'warp_wt_40m' => $warpWeight * 40, 'weft_wt_40m' => $weftWeight * 40, 'warp_weight_1m' => $warpWeight,
            'weft_weight_1m' => $weftWeight, 'total_weight_1m_lb' => $warpWeight + $weftWeight,
            'total_weight_1m_kg' => ($warpWeight + $weftWeight) / 2.2046, 'width_m' => $width * 0.0254,
            'gsm' => round((($read / $warpCount) + ($pick / $weftCount)) * 25.25),
            'warp_bags' => round($warpWeight * $quantity / 100, 2), 'weft_bags' => round($weftWeight * $quantity / 100, 2),
            'warp_amount_per_mtr' => $warpAmount, 'weft_amount_per_mtr' => $weftAmount,
            'conversion_per_mtr' => $conversionPerMeter, 'fabric_rate_per_mtr' => $fabricRate,
            'contract_value' => $contractValue, 'conv_value' => $conversionPerMeter * $quantity,
            'yarn_value' => ($warpAmount + $weftAmount) * $quantity, 'sale_tax_rate' => $taxRate,
            'sales_tax_amount' => $contractValue * $taxRate];
    }
}

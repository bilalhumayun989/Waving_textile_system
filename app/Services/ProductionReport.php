<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProductionReport
{
    /** Read an existing-system export without creating production-entry records. */
    public function records(): array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists('production.csv')) {
            return [];
        }
        $stream = $disk->readStream('production.csv');
        if (! is_resource($stream)) {
            return [];
        }
        $rows = [];
        try {
            $header = fgetcsv($stream, escape: '');
            if (! $header) {
                return [];
            }
            $header[0] = ltrim($header[0], "\xEF\xBB\xBF");
            if (array_diff(['date', 'reference', 'item', 'quantity', 'unit'], $header)) {
                return [];
            }
            while (($line = fgetcsv($stream, escape: '')) !== false) {
                if (count($line) !== count($header)) {
                    continue;
                }
                $row = array_combine($header, $line);
                $validator = Validator::make($row, ['date' => 'required|date_format:Y-m-d', 'reference' => 'required|string|max:100',
                    'item' => 'required|string|max:200', 'quantity' => 'required|numeric|min:0', 'unit' => 'required|string|max:30']);
                if ($validator->fails()) {
                    continue;
                }
                $source = $row['source_url'] ?? '';
                $rows[] = [...$validator->validated(), 'id' => count($rows) + 1, 'quantity' => (float) $row['quantity'],
                    'source_url' => filter_var($source, FILTER_VALIDATE_URL) && in_array(parse_url($source, PHP_URL_SCHEME), ['http', 'https']) ? $source : null];
            }
        } finally {
            fclose($stream);
        }

        return $rows;
    }
}

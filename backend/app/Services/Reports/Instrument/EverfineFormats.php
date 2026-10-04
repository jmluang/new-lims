<?php

namespace App\Services\Reports\Instrument;

use RuntimeException;

/** Adapted from the supplied haas_read.php; binary layouts and float decoding are preserved. */
const FILE_TAG = 'LED300_B';
const RECORD_TAG = 'LED300_DATA_A';
const TRAILER_TAG = 'OP_O';

final class Reader
{
    public int $pos = 0;

    public function __construct(private string $buf) {}

    private function take(int $n): string
    {
        if ($this->pos + $n > strlen($this->buf)) {
            throw new RuntimeException("EOF at {$this->pos}, need $n");
        }
        $s = substr($this->buf, $this->pos, $n);
        $this->pos += $n;

        return $s;
    }

    public function u8(): int
    {
        return ord($this->take(1));
    }

    public function u16(): int
    {
        return unpack('v', $this->take(2))[1];
    }

    public function u32(): int
    {
        return unpack('V', $this->take(4))[1];
    }

    public function i32(): int
    {
        $value = $this->u32();

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function f32(): float
    {
        return unpack('g', $this->take(4))[1];
    }

    /** Decode an MFC CString with a variable length prefix and GB18030 bytes. */
    public function cstring(): string
    {
        $len = $this->u8();
        if ($len === 0xFF) {
            $wide = $this->u16();
            if ($wide === 0xFFFE) {
                throw new RuntimeException('wide-char string not supported');
            }
            $len = ($wide === 0xFFFF) ? $this->u32() : $wide;
        }

        return $this->ansi($this->take($len));
    }

    public function expectTag(string $expected): void
    {
        $found = $this->cstring();
        if ($found !== $expected) {
            throw new RuntimeException("bad tag: expected '$expected', found '$found' at offset ".($this->pos - strlen($found) - 1));
        }
    }

    public function f32Array(int $n): array
    {
        $raw = $this->take(4 * $n);

        return array_values(unpack("g$n", $raw));
    }

    /** Convert Chinese Windows GB18030 text to UTF-8. */
    public function ansi(string $bytes): string
    {
        $out = @mb_convert_encoding($bytes, 'UTF-8', 'GB18030');

        return $out === false ? $bytes : $out;
    }
}

/** Find a length-prefixed MFC tag starting at a byte offset. */
function findFrom(string $haystack, int $from, string $needle): ?int
{
    $p = strpos($haystack, $needle, $from);

    return $p === false ? null : $p;
}

function tagPattern(string $tag): string
{
    return chr(strlen($tag)).$tag;
}

function scanStrings(string $bytes, int $minLen): array
{
    $out = [];
    $i = 0;
    $n = strlen($bytes);
    while ($i < $n) {
        $len = ord($bytes[$i]);
        if ($len >= $minLen && $len < 0xFF && $i + 1 + $len <= $n) {
            $cand = substr($bytes, $i + 1, $len);
            if (preg_match('/^[\x20-\x7E]*$/', $cand)) {
                $out[] = $cand;
                $i += 1 + $len;

                continue;
            }
        }
        $i++;
    }

    return $out;
}

function parseHaas(string $path): array
{
    $buf = file_get_contents($path);
    if ($buf === false) {
        throw new RuntimeException("cannot read $path");
    }
    $r = new Reader($buf);

    $r->expectTag(FILE_TAG);
    $reserved = $r->i32();
    $recordCount = $r->i32();
    if ($recordCount < 0 || $recordCount > 100) {
        throw new RuntimeException("implausible record count: $recordCount");
    }

    $recPat = tagPattern(RECORD_TAG);
    $trlPat = tagPattern(TRAILER_TAG);
    $records = [];

    for ($idx = 0; $idx < $recordCount; $idx++) {
        $offset = $r->pos;

        $r->expectTag(RECORD_TAG);
        $model = $r->cstring();
        $headReserved = [$r->i32(), $r->i32(), $r->i32(), $r->i32()];
        $scaleFlag = $r->f32();
        $f0 = $r->i32();
        $f1 = $r->i32();
        $wlStepHead = $r->f32();
        $fluxHead = $r->f32();
        $f2 = $r->i32();
        $f3 = $r->i32();

        $mode = $r->cstring();
        $sampleNo = $r->cstring();
        $maker = $r->cstring();
        $temperature = $r->cstring();
        $humidity = $r->cstring();
        $operator = $r->cstring();
        $timestamp = $r->cstring();
        $note = $r->cstring();

        $reservedBeforeIntTime = $r->i32();
        $integrationMs = $r->f32();
        $wlStep = $r->f32();
        $wlStart = $r->f32();
        $wlEnd = $r->f32();

        $countPos = $r->pos;
        $count = $r->i32();
        if ($count <= 1 || $count > 10000) {
            throw new RuntimeException("implausible spectrum point count $count at $countPos");
        }
        if (! is_finite($wlStep) || ! is_finite($wlStart) || ! is_finite($wlEnd) || $wlStep <= 0 || $wlStart <= 0 || $wlEnd <= $wlStart) {
            throw new RuntimeException('Invalid wavelength grid');
        }
        $expected = (int) round(($wlEnd - $wlStart) / $wlStep) + 1;
        if (abs($count - $expected) > 1) {
            throw new RuntimeException("point count mismatch: $count vs expected $expected");
        }
        $spectrum = $r->f32Array($count);

        $adFullScale = $r->i32();
        $adPeak = $r->f32();
        $fluxLm = $r->f32();
        if (abs($fluxLm - $fluxHead) > abs($fluxHead) * 1e-4 + 1e-6) {
            throw new RuntimeException("luminous flux copies disagree: $fluxHead vs $fluxLm");
        }
        $radiantW = $r->f32();
        $x = $r->f32();
        $y = $r->f32();
        $up = $r->f32();
        $vp = $r->f32();
        $cct = $r->f32();
        $redRatio = $r->f32();
        $dominant = $r->f32();
        $peakWl = $r->f32();
        $purity = $r->f32();
        $fwhm = $r->f32();
        $ra = $r->f32();
        $rIdx = $r->f32Array(15);
        $limits = $r->f32Array(3);

        $bodyEnd = $r->pos;
        $isLast = ($idx + 1 === $recordCount);
        if ($isLast) {
            $trailerEnd = findFrom($buf, $bodyEnd, $trlPat)
                ?? min($bodyEnd + 274, strlen($buf));
        } else {
            $trailerEnd = findFrom($buf, $bodyEnd, $recPat);
            if ($trailerEnd === null) {
                throw new RuntimeException("record $idx ends at $bodyEnd but next record tag missing");
            }
        }
        $r->pos = $trailerEnd;
        $trailerBytes = substr($buf, min($bodyEnd, strlen($buf)), max(0, $trailerEnd - $bodyEnd));

        $strings = scanStrings($trailerBytes, 4);
        $vendorUrl = '';
        $vendorName = '';
        foreach ($strings as $s) {
            if ($vendorUrl === '' && str_starts_with($s, 'http')) {
                $vendorUrl = $s;
            } elseif ($vendorName === '' && ! str_starts_with($s, 'http') && preg_match('/[A-Z]/', $s)) {
                $vendorName = $s;
            }
        }

        $selfAbs = null;
        $marker = "\xFF\xFF\xFF\xFF\x00\x00\x00\x00";
        $mp = strpos($trailerBytes, $marker);
        if ($mp !== false && $mp + 12 <= strlen($trailerBytes)) {
            $v = unpack('g', substr($trailerBytes, $mp + 8, 4))[1];
            if (is_finite($v) && $v >= 0.0 && $v <= 100.0) {
                $selfAbs = $v;
            }
        }

        // Read the short instrument number at the end of the record trailer.
        $instrumentNumber = '';
        for ($start = strlen($trailerBytes) - 1; $start >= 0; $start--) {
            $len = ord($trailerBytes[$start]);
            if ($len < 1 || $len > 32 || $start + 1 + $len > strlen($trailerBytes)) {
                continue;
            }
            $end = $start + 1 + $len;
            $val = substr($trailerBytes, $start + 1, $len);
            $rest = substr($trailerBytes, $end);
            if (preg_match('/^[A-Za-z0-9\-_]+$/', $val) && preg_match('/^\x00*$/', $rest)) {
                $instrumentNumber = $val;
                break;
            }
        }

        $records[] = [
            'offset' => $offset,
            'model' => $model,
            'mode' => $mode,
            'sample_no' => $sampleNo,
            'maker' => $maker,
            'temperature_c' => $temperature,
            'humidity_pct' => $humidity,
            'operator' => $operator,
            'timestamp' => $timestamp,
            'note' => $note,
            'integration_time_ms' => $integrationMs,
            'wl_start_nm' => $wlStart,
            'wl_end_nm' => $wlEnd,
            'wl_step_nm' => $wlStep,
            'spectrum' => $spectrum,
            'ad_full_scale' => $adFullScale,
            'ad_peak' => $adPeak,
            'colorimetry' => [
                'luminous_flux_lm' => $fluxLm,
                'radiant_flux_w' => $radiantW,
                'x' => $x, 'y' => $y,
                'u_prime' => $up, 'v_prime' => $vp,
                'cct_k' => $cct,
                'red_ratio_pct' => $redRatio,
                'dominant_wl_nm' => $dominant,
                'peak_wl_nm' => $peakWl,
                'purity_pct' => $purity,
                'fwhm_nm' => $fwhm,
                'ra' => $ra,
                'r' => $rIdx,
            ],
            'vendor_name' => $vendorName,
            'vendor_url' => $vendorUrl,
            'instrument_number' => $instrumentNumber,
            'self_absorption_coefficient' => $selfAbs,
            'unmapped' => [
                'head_reserved' => $headReserved,
                'scale_flag' => $scaleFlag,
                'flags' => [$f0, $f1, $f2, $f3],
            ],
            '_trailer_strings' => $strings,
        ];
    }

    return [
        'tag' => FILE_TAG,
        'reserved' => $reserved,
        'record_count' => $recordCount,
        'records' => $records,
    ];
}

/** Detect the angle-grid framing used by GOS; orientation remains heuristic. */
function gosBlockAt(string $buf, int $offset): ?array
{
    $length = strlen($buf);
    if ($offset + 8 > $length) {
        return null;
    }
    [$a, $b] = array_values(unpack('V2', $buf, $offset));
    if ($a < 16 || $a > 4000 || $b < 4 || $b > 4000) {
        return null;
    }
    $matrixOffset = $offset + 8 + ($a + $b) * 4;
    $end = $matrixOffset + $a * $b * 4;
    if ($end > $length) {
        return null;
    }

    $angles = array_values(unpack('g'.min($a, 8), $buf, $offset + 8));
    for ($i = 1; $i < count($angles); $i++) {
        if (! ($angles[$i] > $angles[$i - 1])) {
            return null;
        }
    }
    if (! ($angles[0] < 5 && max($angles) < 1000)) {
        return null;
    }
    foreach (unpack('g6', $buf, $matrixOffset) as $value) {
        if (is_nan($value) || abs($value) >= 1e9) {
            return null;
        }
    }

    return [
        'offset' => $offset, 'dim_a' => $a, 'dim_b' => $b,
        'angles_a' => array_values(unpack("g$a", $buf, $offset + 8)),
        'angles_b' => array_values(unpack("g$b", $buf, $offset + 8 + $a * 4)),
        // Report imports retain the original GOS file; matrix orientation is not established.
        'matrix' => [],
        'size' => $end - $offset,
    ];
}

function gosLooksLikeCString(string $buf, int $offset, int $maxLength = 64): bool
{
    if ($offset >= strlen($buf)) {
        return false;
    }
    $length = ord($buf[$offset]);
    if ($length < 1 || $length > $maxLength || $offset + 1 + $length > strlen($buf)) {
        return false;
    }
    $raw = substr($buf, $offset + 1, $length);
    if (! mb_check_encoding($raw, 'GB18030')) {
        return false;
    }
    $text = mb_convert_encoding($raw, 'UTF-8', 'GB18030');

    // Python str.isprintable permits ASCII space, but excludes other separators.
    return preg_match('/[\p{C}\p{Z}]/u', str_replace(' ', '', $text)) === 0;
}

/** Read the verified R_V1 boundary beyond the angle matrices. */
function gosElectricalTail(string $buf, int $dataEnd): array
{
    $marker = tagPattern('R_V1');
    $offset = strpos($buf, $marker, $dataEnd);
    if ($offset === false || $offset - 12 < $dataEnd) {
        throw new RuntimeException('R_V1 electrical-field boundary missing');
    }
    if (strpos($buf, $marker, $offset + strlen($marker)) !== false) {
        throw new RuntimeException('ambiguous R_V1 electrical-field boundary');
    }
    $r = new Reader($buf);
    $r->pos = $offset - 12;
    $frequency = $r->f32();
    if (! is_finite($frequency)) {
        throw new RuntimeException('invalid stored frequency');
    }
    $r->pos = $offset;
    $r->expectTag('R_V1');
    $r->i32(); // Mode.
    $r->i32(); // Subtype.
    $points = $r->i32();
    $remaining = strlen($buf) - $r->pos;
    if ($remaining < 20 || $points < 0 || $points > intdiv($remaining - 20, 4)) {
        throw new RuntimeException('invalid R_V1 array length');
    }
    $r->pos += $points * 4;
    $r->i32(); // THD availability; not imported by this reader.
    $r->f32(); // Voltage THD.
    $r->f32(); // Current THD.
    $hasDisplacement = $r->i32();
    $displacement = $r->f32();
    if (! in_array($hasDisplacement, [0, 1], true)) {
        throw new RuntimeException('invalid displacement-factor availability');
    }
    if ($hasDisplacement === 1 && ! is_finite($displacement)) {
        throw new RuntimeException('invalid stored displacement factor');
    }

    return ['frequency_hz' => $frequency, 'has_displacement_factor' => (bool) $hasDisplacement,
        'displacement_factor' => $hasDisplacement === 1 ? $displacement : null];
}

function parseGos(string $path): array
{
    $buf = file_get_contents($path);
    if ($buf === false) {
        throw new RuntimeException("cannot read $path");
    }
    $r = new Reader($buf);
    $r->expectTag('GODATA 100');
    $version = $r->cstring();
    $headInts = [$r->i32(), $r->i32(), $r->i32()];
    $model = $r->cstring();
    $headFloats = $r->f32Array(2);
    $extra = [$r->i32(), $r->i32()];
    $operator = $r->cstring();
    $temperature = $r->cstring();
    $humidity = $r->cstring();
    for ($i = 0; $i < 5; $i++) {
        $r->cstring();
    }
    $flux = $r->f32();
    $extra[] = $r->i32();
    $sample = $r->cstring();

    // GODATA 100 stores seven sample-detail CStrings before U, I, P and PF.
    // Confirmed against GOSoft 2.00.491 archive I/O (0x50CB75 / 0x50DF17)
    // and report labels for document members 0x7C, 0x80, 0x84 and 0x88.
    for ($i = 0; $i < 7; $i++) {
        $r->cstring();
    }
    $electrical = array_combine(['voltage', 'current', 'power', 'power_factor'], $r->f32Array(4));
    foreach ($electrical as $value) {
        if (! is_finite($value)) {
            throw new RuntimeException('GOS electrical header contains a non-finite measurement.');
        }
    }

    $first = strlen($buf);
    for ($offset = $r->pos; $offset < strlen($buf) - 8; $offset++) {
        if (gosBlockAt($buf, $offset) !== null) {
            $first = $offset;
            break;
        }
    }
    if ($first === strlen($buf)) {
        throw new RuntimeException('GOS angle data block missing');
    }
    $strings = [];
    while ($r->pos < $first) {
        if (gosLooksLikeCString($buf, $r->pos)) {
            $text = $r->cstring();
            if (trim($text) !== '') {
                $strings[] = $text;
            }
        } else {
            $r->pos++;
        }
    }
    $blocks = [];
    $dataEnd = $first;
    $r->pos = $first;
    while ($r->pos < strlen($buf) - 8) {
        $block = gosBlockAt($buf, $r->pos);
        if ($block !== null) {
            $r->pos += $block['size'];
            // Matrix values are intentionally omitted from the result, so use their byte span.
            $dataEnd = $r->pos;
            unset($block['size']);
            $blocks[] = $block;
        } else {
            $r->pos++;
        }
    }
    $tail = gosElectricalTail($buf, $dataEnd);

    $opTag = '';
    $opParams = [];
    $zoneStep = 0.0;
    $zonal = [];
    if (preg_match('/\x07(OP V\d{3})/', $buf, $match, PREG_OFFSET_CAPTURE)) {
        $offset = $match[0][1] + strlen($match[0][0]);
        if ($offset + 48 + 76 <= strlen($buf)) {
            $opTag = $match[1][0];
            $opParams = array_values(unpack('g3', $buf, $offset));
            $opParams = array_merge($opParams, array_values(unpack('g2', $buf, $offset + 24)));
            $opParams[] = unpack('g', $buf, $offset + 32)[1];
            $zoneStep = unpack('g', $buf, $offset + 28)[1];
            $values = array_values(unpack('g19', $buf, $offset + 48));
            $sum = 0.0;
            for ($i = 0; $i < count($values); $i++) {
                $sum += $values[$i];
                if ($flux != 0.0 && abs($sum - $flux) / $flux < 1e-5) {
                    $zonal = array_slice($values, 0, $i + 1);
                    break;
                }
            }
        }
    }
    $date = '';
    $time = '';
    $system = '';
    foreach ($strings as $text) {
        if ($date === '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $text)) {
            $date = $text;
        }
        if ($time === '' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text)) {
            $time = $text;
        }
        if ($system === '' && str_starts_with($text, 'EVERFINE')) {
            $system = $text;
        }
    }
    $lab = '';
    foreach ($strings as $text) {
        if (! in_array($text, [$date, $time, $system], true) && ! preg_match('/^[\d,.\s-]+$/', $text)) {
            $lab = $text;
            break;
        }
    }
    $zones = [];
    foreach ($zonal as $i => $value) {
        $zones[] = [
            'gamma_from' => $i * $zoneStep, 'gamma_to' => ($i + 1) * $zoneStep,
            'flux_lm' => $value, 'share_pct' => $flux != 0.0 ? 100 * $value / $flux : 0,
        ];
    }

    return [
        'kind' => 'gos', 'file' => basename($path), 'size' => strlen($buf),
        'magic' => 'GODATA 100', 'sw_version' => $version, 'instrument_model' => $model,
        'operator' => $operator, 'temperature' => $temperature, 'humidity' => $humidity,
        'flux_lm' => $flux, 'sample_name' => $sample, 'electrical' => $electrical,
        'lab' => $lab, 'test_date' => $date, 'test_time' => $time, 'system' => $system,
        'op_tag' => $opTag, 'op_params' => $opParams, 'zone_step' => $zoneStep,
        'zonal_flux_lm' => $zonal, 'zones' => $zones,
        'header_ints' => $headInts, 'header_floats' => $headFloats,
        'header_extra' => $extra, 'header_strings' => $strings, 'blocks' => $blocks,
        ...$tail,
    ];
}

/** Select by the binary tag rather than the filename extension. */
function parseMeasurement(string $path): array
{
    $head = file_get_contents($path, false, null, 0, 16);
    if ($head === false) {
        throw new RuntimeException("cannot read $path");
    }
    if (str_starts_with($head, tagPattern('GODATA 100'))) {
        return parseGos($path);
    }
    if (str_starts_with($head, tagPattern(FILE_TAG))) {
        return parseHaas($path);
    }
    throw new RuntimeException('unsupported measurement format');
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Raw spectral measurements, ordered independently from the scalar report fields. */
class Lm79SpectrumPoint extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['wavelength' => 'float', 'relative_power' => 'float', 'position' => 'integer'];
    }
}

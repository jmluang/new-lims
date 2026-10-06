<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PdfYanzhenjiaSetting extends Model
{
    public const SINGLETON_ID = 1;

    protected $table = 'pdf_yanzhenjia_settings';

    protected $fillable = ['enabled', 'appid', 'secret'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'secret' => 'encrypted',
        ];
    }

    public function hasCredentials(): bool
    {
        return filled($this->appid) && filled($this->secret);
    }
}

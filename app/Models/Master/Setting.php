<?php

namespace App\Models\Master;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $connection = 'master';
    protected $table = 'settings';
    public $timestamps = false;
    protected $guarded = [];
    protected $hidden = ['smtp_password'];

    protected function casts(): array
    {
        return [
            'entry_form' => 'boolean',
            'enable_logging' => 'boolean',
            'version' => 'decimal:1',
            'dark_mode' => 'boolean',
            'force_https' => 'boolean',
        ];
    }
}

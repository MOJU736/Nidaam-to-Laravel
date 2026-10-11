<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class DocumentTemplateSetting extends Model
{
    protected $connection = 'tenant';
    protected $table = 'document_template_settings';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'template_default_applied' => 'boolean',
            'template_retrofit_applied' => 'boolean',
        ];
    }
}

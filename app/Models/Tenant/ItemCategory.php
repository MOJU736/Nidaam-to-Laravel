<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class ItemCategory extends Model
{
    protected $connection = 'tenant';
    protected $table = 'item_categories';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(Item::class, 'category_id');
    }
}

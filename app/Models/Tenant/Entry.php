<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Entry extends Model
{
    protected $connection = 'tenant';
    protected $table = 'entries';
    public $timestamps = false;
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'dr_total' => 'decimal:2',
            'cr_total' => 'decimal:2',
        ];
    }

    public function entryType()
    {
        return $this->belongsTo(EntryType::class, 'entrytype_id');
    }

    public function items()
    {
        return $this->hasMany(EntryItem::class, 'entry_id');
    }

    public function tag()
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }
}

<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;

class Group extends Model
{
    protected $connection = 'tenant';
    protected $table = 'groups';
    public $timestamps = false;
    protected $guarded = [];

    public function parent()
    {
        return $this->belongsTo(Group::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Group::class, 'parent_id');
    }

    public function ledgers()
    {
        return $this->hasMany(Ledger::class, 'group_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CosineSimilarity extends Model
{
    protected $primaryKey = 'inf_id';
    public $timestamps = false;

    protected $table;

    public function setTableForUser($userId)
    {
        $this->table = 'inferensi_cs_user_' . $userId;
        return $this;
    }

    public function tableExists()
    {
        return DB::getSchemaBuilder()->hasTable($this->table);
    }

    public function getRules()
    {
        if ($this->tableExists()) {
            return DB::table($this->table)->get();
        }
        return collect([]);
    }
}


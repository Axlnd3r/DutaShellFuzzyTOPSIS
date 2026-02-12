<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RandomForestInference extends Model
{
    protected $primaryKey = 'inf_id';

    protected $fillable = [
        'case_goal',
        'rule_id',
        'rule_goal',
        'match_value',
        'cocok',
        'waktu',
    ];

    public $timestamps = false;

    protected $table;

    public function setTableForUser($userId)
    {
        $this->table = 'inferensi_rf_user_' . $userId;
        return $this;
    }

    public function tableExists()
    {
        return DB::getSchemaBuilder()->hasTable($this->table);
    }

    public function getRules()
    {
        if ($this->tableExists()) {
            return DB::table($this->table)
                ->orderByDesc('inf_id')
                ->limit(500)
                ->get();
        }

        return collect([]);
    }
}

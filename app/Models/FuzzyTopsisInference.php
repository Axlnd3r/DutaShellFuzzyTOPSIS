<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class FuzzyTopsisInference extends Model
{
    protected $primaryKey = 'inf_id';
    public $timestamps = false;

    protected $fillable = [
        'case_id',
        'case_goal',
        'rule_id',
        'rule_goal',
        'match_value',
        'score',
        'rank',
        's_plus',
        's_minus',
        'cocok',
        'user_id',
        'waktu',
    ];

    protected $table;

    public function setTableForUser($userId)
    {
        $this->table = 'inferensi_ft_user_' . $userId;
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

    public static function ensureTable(int $userId): string
    {
        $tableName = 'inferensi_ft_user_' . $userId;

        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            DB::statement("CREATE TABLE `{$tableName}` (
                `inf_id` INT(11) PRIMARY KEY NOT NULL AUTO_INCREMENT,
                `case_id` VARCHAR(100) NOT NULL,
                `case_goal` VARCHAR(200) DEFAULT NULL,
                `rule_id` VARCHAR(100) NOT NULL,
                `rule_goal` VARCHAR(200) DEFAULT NULL,
                `match_value` DECIMAL(10,6) NOT NULL DEFAULT 0,
                `score` DECIMAL(10,6) NOT NULL DEFAULT 0,
                `rank` INT(11) NOT NULL DEFAULT 0,
                `s_plus` DECIMAL(10,6) NOT NULL DEFAULT 0,
                `s_minus` DECIMAL(10,6) NOT NULL DEFAULT 0,
                `cocok` ENUM('1','0') NOT NULL DEFAULT '0',
                `user_id` INT(11) NOT NULL,
                `waktu` DECIMAL(16,14) NOT NULL DEFAULT 0
            )");
        }

        return $tableName;
    }
}

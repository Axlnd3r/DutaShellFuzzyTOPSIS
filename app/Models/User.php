<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasFactory;

    // Nama tabel
    protected $table = 'user';

    // Primary key tabel
    protected $primaryKey = 'user_id';

    // Field yang dapat diisi (mass assignable)
    protected $fillable = [
        'username',
        'password',
        'active',
        'role'
    ];

    // Menonaktifkan timestamps (created_at, updated_at)
    public $timestamps = false;

    // Jika ada enum untuk active, bisa dibuat accessor untuk mempermudah akses enum
    public function getActiveAttribute($value)
    {
<<<<<<< HEAD
        return $value === 'T' ? 'Active' : 'Inactive';
=======
        $normalized = strtolower((string) $value);
        return in_array($normalized, ['t', '1', 'active'], true) ? 'Active' : 'Inactive';
>>>>>>> 1caa14645c69b47910ab957c1380a891efae9714
    }

    // Jika kamu ingin menyembunyikan password saat diakses dalam response
    protected $hidden = [
        'password',
    ];
    
}

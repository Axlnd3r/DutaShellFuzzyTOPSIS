<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FCController extends Controller
{
    public function generateFC($user_id, $case_num) 
    {
        $user_id = Auth::id();  // Mendapatkan user_id dari user yang sedang login
        $case_num = $user_id;    // Menetapkan case_num sama dengan user_id

        $command = 'php "' . base_path('scripts/decision-tree/FC.php') . '" ' . $user_id . ' ' . $case_num;

        $output = shell_exec($command);

        return redirect('/history')->with('success', 'Forward Chaining executed!'); 
    
    }
}

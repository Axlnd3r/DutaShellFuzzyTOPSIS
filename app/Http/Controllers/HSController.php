<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class HSController extends Controller
{
    public function generateHS($user_id, $case_num)
    {
        $user_id = Auth::id();
        $case_num = $user_id;

        $command = 'php "' . base_path('scripts/decision-tree/hybrid_similarity.php') . '" ' . $user_id . ' ' . $case_num;

        $output = shell_exec($command . ' 2>&1');

        return redirect('/history')->with('success', 'Hybrid Similarity executed! ' . ($output ? '| Debug: ' . $output : ''));
    }
}


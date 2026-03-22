<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class CSController extends Controller
{
    public function generateCS($user_id, $case_num)
    {
        $user_id = Auth::id();
        $case_num = $user_id;

        $command = 'php "' . base_path('scripts/decision-tree/hybrid_similarity.php') . '" ' . $user_id . ' ' . $case_num . ' cosine';
        $output = shell_exec($command . ' 2>&1');

        return redirect('/history')->with('success', 'Cosine Similarity executed! ' . ($output ? '| Debug: ' . $output : ''));
    }
}


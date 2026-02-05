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
        $output = shell_exec($command);

        return view('admin.menu.inferensi', compact('output', 'case_num'))->with('success', 'Cosine Similarity executed!');
    }
}


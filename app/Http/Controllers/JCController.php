<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Auth;

class JCController extends Controller
{
    public function generateJC($user_id, $case_num)
    {
        $user_id = Auth::id();
        $case_num = $user_id;

        $command = 'php "' . base_path('scripts/decision-tree/hybrid_similarity.php') . '" ' . $user_id . ' ' . $case_num . ' jaccard';
        $output = shell_exec($command);

        return view('admin.menu.inferensi', compact('output', 'case_num'))->with('success', 'Jaccard Similarity executed!');
    }
}


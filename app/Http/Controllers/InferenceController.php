<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
<<<<<<< HEAD
use Illuminate\Support\Facades\Redirect;
=======
>>>>>>> 1caa14645c69b47910ab957c1380a891efae9714

class InferenceController extends Controller
{
    public function generateInference($user_id, $case_num)
    {
<<<<<<< HEAD
        $user_id = Auth::id();  // Mendapatkan user_id dari user yang sedang login
        $case_num = $user_id;    // Menetapkan case_num sama dengan user_id

        $command = 'php "' . base_path('scripts/decision-tree/matching_rule.php') . '" ' . $user_id . ' ' . $case_num;

=======
        $user_id  = Auth::user()->user_id;  // ← konsisten
        $case_num = $user_id;

        $command = 'php "' . base_path('scripts/decision-tree/matching_rule.php') . '" ' . $user_id . ' ' . $case_num;
>>>>>>> 1caa14645c69b47910ab957c1380a891efae9714
        $output = shell_exec($command);

        return view('admin.menu.inferensi', compact('output', 'case_num'))->with('success', 'Inference updated successfully!'); 
    }

    public function generate($user_id, $case_num)
    {
<<<<<<< HEAD
        $user_id = Auth::id();  // Mendapatkan user_id dari user yang sedang login
        $case_num = $user_id;    // Menetapkan case_num sama dengan user_id

        $command = 'php "' . base_path('scripts/decision-tree/matching_rule.php') . '" ' . $user_id . ' ' . $case_num;

        $output = shell_exec($command);

        // Kembalikan ke view inferensi
        return view('admin.menu.inferensi', compact('output', 'case_num'))->with('success', 'Inference updated successfully!');
    }

    public function evaluate(Request $request)
    {
        $user_id = Auth::id();
        $mode = $request->input('mode', 'hybrid');
        $eval = $request->input('eval', 'loocv');
        $param = $request->input('param');

        $script = base_path('scripts/decision-tree/eval_similarity.php');
        $cmd = 'php "' . $script . '" ' . $user_id . ' ' . $mode . ' ' . $eval;
        if (!empty($param)) {
            $cmd .= ' ' . $param;
        }

        $output = shell_exec($cmd);

        $matrix = null;
        if ($output && ($pos = strpos($output, 'MATRIX_JSON:')) !== false) {
            $jsonPart = substr($output, $pos + strlen('MATRIX_JSON:'));
            $decoded = json_decode($jsonPart, true);
            if ($decoded) {
                $matrix = $decoded;
            }
        }
        $cleanOutput = $output;
        if (($pos = strpos($output, 'MATRIX_JSON:')) !== false) {
            $cleanOutput = trim(substr($output, 0, $pos));
        }

        return Redirect::to('/inference')->with('eval_output', $cleanOutput ?: 'No output')->with('eval_matrix', $matrix);
    }

=======
        $user_id  = Auth::user()->user_id;  // ← konsisten
        $case_num = $user_id;

        $command = 'php "' . base_path('scripts/decision-tree/matching_rule.php') . '" ' . $user_id . ' ' . $case_num;
        $output = shell_exec($command);

        return view('admin.menu.inferensi', compact('output', 'case_num'))->with('success', 'Inference updated successfully!');
    }
>>>>>>> 1caa14645c69b47910ab957c1380a891efae9714
}

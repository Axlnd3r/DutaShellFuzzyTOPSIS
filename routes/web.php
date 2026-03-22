<?php

use App\Http\Controllers\AtributController;
use App\Http\Controllers\AtributValueController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BCController;
use App\Http\Controllers\CaseController;
use App\Http\Controllers\CaseUserController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\CSController;
use App\Http\Controllers\DecisionTreeController;
use App\Http\Controllers\FCController;
use App\Http\Controllers\FuzzyTopsisController;
use App\Http\Controllers\HSController;
use App\Http\Controllers\InferenceController;
use App\Http\Controllers\JCController;
use App\Http\Controllers\ProfileAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RandomForestController;
use App\Http\Controllers\RuleController;
use App\Http\Controllers\SVMController;
use App\Http\Controllers\EvaluationController;
use App\Http\Controllers\HybridSimController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// USER
// project
Route::get('/project', function () {
    return view('admin.menu.case');
})->middleware('auth');
Route::get('/project/edit', [CaseController::class, 'edit'])->name('admin.menu.case.edit')->middleware('auth');
Route::put('/project/update', [CaseController::class, 'update'])->name('admin.menu.case.update')->middleware('auth');

// atribut
Route::get('/attributte', [AtributController::class, 'index'])->name('admin.menu.attributte')->middleware('auth');
Route::get('/attributte/create', [AtributController::class, 'create'])->name('admin.menu.atribut.tambah')->middleware('auth');
Route::post('/attributte', [AtributController::class, 'store'])->name('admin.menu.atribut.store')->middleware('auth');
Route::get('/attributte/{id}/edit', [AtributController::class, 'edit'])->name('admin.menu.atribut.edit')->middleware('auth');
Route::put('/attributte/{id}', [AtributController::class, 'update'])->name('admin.menu.atribut.update')->middleware('auth');
Route::delete('/attributte/{id}', [AtributController::class, 'destroy'])->name('admin.menu.atribut.hapus')->middleware('auth');

// atributValue
Route::get('/attributteValue', [AtributValueController::class, 'index'])->name('admin.menu.attributteValue')->middleware('auth');
Route::get('/attributteValue/create', [AtributValueController::class, 'create'])->name('admin.menu.atributValue.tambah')->middleware('auth');
Route::post('/attributteValue', [AtributValueController::class, 'store'])->name('admin.menu.atributValue.store')->middleware('auth');
Route::get('/attributteValue/{id}/edit', [AtributValueController::class, 'edit'])->name('admin.menu.atributValue.edit')->middleware('auth');
Route::put('/attributteValue/{id}', [AtributValueController::class, 'update'])->name('admin.menu.atributValue.update')->middleware('auth');
Route::delete('/attributteValue/{id}', [AtributValueController::class, 'destroy'])->name('admin.menu.atributValue.hapus')->middleware('auth');

// generate case
Route::get('/generateCase', [CaseUserController::class, 'showGenerateCaseForm'])->name('generate.case.form')->middleware('auth');
Route::post('/generateCase', [CaseUserController::class, 'generateCase'])->name('generate.case')->middleware('auth');
Route::post('/generateCase/store', [CaseUserController::class, 'store'])->name('generate.case.store')->middleware('auth');
Route::get('/generateCase/new', [CaseUserController::class, 'create'])->name('generate.case.create')->middleware('auth');
Route::get('/generateCase/{case_id}/edit', [CaseUserController::class, 'edit'])->name('generate.case.edit')->middleware('auth');
Route::put('/generateCase/{case_id}', [CaseUserController::class, 'update'])->name('generate.case.update')->middleware('auth');
Route::delete('/generateCase/{case_id}', [CaseUserController::class, 'destroy'])->name('generate.case.destroy')->middleware('auth');

// tree
Route::get('/tree', [DecisionTreeController::class, 'showTree'])->name('tree.show')->middleware('auth');
Route::get('/tree/generate', [DecisionTreeController::class, 'generateTree'])->name('tree.generate')->middleware('auth');

// SVM Model
Route::get('/SupportVectorMachine', [SVMController::class, 'show'])->name('SVM.show')->middleware('auth');
Route::post('/SupportVectorMachine/generate', [SVMController::class, 'generateSVM'])->name('SVM.generate')->middleware('auth');
Route::post('/SupportVectorMachine/store', [SVMController::class, 'storeCaseAndTrain'])->name('SVM.storeCase')->middleware('auth');

// Random Forest
Route::get('/randomforest', [RandomForestController::class, 'index'])->name('randomforest.index')->middleware('auth');
Route::get('/randomforest/generate', [RandomForestController::class, 'generate'])->name('randomforest.generate')->middleware('auth');
Route::post('/randomforest/predict', [RandomForestController::class, 'predict'])->name('randomforest.predict')->middleware('auth');
Route::get('/randomforest/inference/{user_id}/{case_num}', [RandomForestController::class, 'inferenceFromConsultation'])
    ->name('randomforest.inference')
    ->middleware('auth');

// rule
Route::get('/rule', function () {
    return view('admin.menu.rule');
})->middleware('auth');
Route::get('/rule/{user_id}/{case_num}', [RuleController::class, 'generateRule'])->middleware('auth');

// consultation
Route::get('/consultation', [ConsultationController::class, 'showConsultationForm'])->name('test.case.form')->middleware('auth');
Route::post('/consultation/store', [ConsultationController::class, 'store'])->name('test.case.store')->middleware('auth');
Route::get('/consultation/new', [ConsultationController::class, 'create'])->name('test.case.create')->middleware('auth');
Route::get('/consultation/{case_id}/edit', [ConsultationController::class, 'edit'])->name('test.case.edit')->middleware('auth');
Route::put('/consultation/{case_id}', [ConsultationController::class, 'update'])->name('test.case.update')->middleware('auth');
Route::delete('/consultation/{case_id}', [ConsultationController::class, 'destroy'])->name('test.case.destroy')->middleware('auth');

// inference AKA History
Route::get('/history', function () {
    return view('admin.menu.inferensi');
})->middleware('auth');
Route::get('/history/{user_id}/{case_num}', [InferenceController::class, 'generateInference'])->middleware('auth');
Route::post('/history/{user_id}/{case_num}', [InferenceController::class, 'generate'])->name('inference.generate')->middleware('auth');
Route::post('/history/evaluate', [InferenceController::class, 'evaluate'])->name('inference.evaluate')->middleware('auth');

// fuzzy topsis
Route::post('/fuzzy-topsis/infer', [FuzzyTopsisController::class, 'infer'])
    ->name('fuzzy.topsis.infer')
    ->middleware('auth');

// fc
Route::get('/forwardChaining', function () {
    return view('admin.menu.fc');
})->middleware('auth');
Route::get('/forwardChaining/{user_id}/{case_num}', [FCController::class, 'generateFC'])->middleware('auth');
Route::post('/forwardChaining/{user_id}/{case_num}', [FCController::class, 'generateFC'])->name('inference.fc')->middleware('auth');

// bc
Route::get('/backward', function () {
    return view('admin.menu.bc');
})->middleware('auth');
Route::get('/backwardChaining/{user_id}/{case_num}', [BCController::class, 'generateBC'])->middleware('auth');
Route::post('/backwardChaining/{user_id}/{case_num}', [BCController::class, 'generateBC'])->name('inference.bc')->middleware('auth');

// hybrid similarity
Route::get('/hybridSimilarity', function () {
    return view('admin.menu.inferensi');
})->middleware('auth');
Route::get('/hybridSimilarity/{user_id}/{case_num}', [HSController::class, 'generateHS'])->middleware('auth');
Route::post('/hybridSimilarity/{user_id}/{case_num}', [HSController::class, 'generateHS'])->name('inference.hs')->middleware('auth');

// jaccard similarity
Route::get('/jaccardSimilarity', function () {
    return view('admin.menu.inferensi');
})->middleware('auth');
Route::get('/jaccardSimilarity/{user_id}/{case_num}', [JCController::class, 'generateJC'])->middleware('auth');
Route::post('/jaccardSimilarity/{user_id}/{case_num}', [JCController::class, 'generateJC'])->name('inference.jc')->middleware('auth');

// cosine similarity
Route::get('/cosineSimilarity', function () {
    return view('admin.menu.inferensi');
})->middleware('auth');
Route::get('/cosineSimilarity/{user_id}/{case_num}', [CSController::class, 'generateCS'])->middleware('auth');
Route::post('/cosineSimilarity/{user_id}/{case_num}', [CSController::class, 'generateCS'])->name('inference.cs')->middleware('auth');

//Hybrid Similarity DKK
Route::get('/HybridSim', [HybridSimController::class, 'show'])->name('HybridSim.show')->middleware('auth');
Route::post('/HybridSim/generate', [HybridSimController::class, 'generate'])->name('HybridSim.generate')->middleware('auth');

// Evaluasi Perbandingan (Fuzzy TOPSIS vs Hybrid Similarity)
Route::get('/evaluation', [EvaluationController::class, 'show'])->name('evaluation.show')->middleware('auth');
Route::post('/evaluation/run', [EvaluationController::class, 'run'])->name('evaluation.run')->middleware('auth');

// detail
Route::get('/detail', function () {
    return view('admin.menu.detail');
})->middleware('auth');

// profile
Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');

// logout
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// HALAMAN UTAMA
// login user biasa
Route::get('/', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/', [AuthController::class, 'login']);

// login admin
Route::get('/admin', [AuthController::class, 'showLoginAdminForm'])->name('login.admin');
Route::post('/admin', [AuthController::class, 'loginAdmin']);

// regis
Route::get('/registration', [AuthController::class, 'showRegistrationForm'])->name('registration');
Route::post('/registration', [AuthController::class, 'registration'])->name('registration');

// ADMIN
// user
Route::get('/user', [UserController::class, 'index'])->name('user.menu.user')->middleware('auth');
Route::put('/user/{id}/activate', [UserController::class, 'active'])->name('user.active');
Route::put('/user/{id}/inactivate', [UserController::class, 'inactive'])->name('user.inactive');

// profile admin
Route::get('/profile/admin', [ProfileAdminController::class, 'edit'])->name('profileAdmin.edit');
Route::post('/profile/admin/update', [ProfileAdminController::class, 'update'])->name('profileAdmin.update');

// Debug phpinfo (hapus jika tidak diperlukan)
Route::get('/phpinfo', function () {
    phpinfo();
})->middleware('auth');

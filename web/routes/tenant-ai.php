<?php

use Illuminate\Support\Facades\Route;
use Modules\Ai\Http\Controllers\AiAdminController as Admin;
use Modules\Ai\Http\Controllers\AiController as Ai;

/*
| AI assistants (signed-in staff). Included from routes/tenant.php. Each action checks its own permission.
*/
Route::prefix('ai')->name('ai.')->group(function () {
    Route::get('/', [Ai::class, 'index'])->name('index');
    Route::get('chat', [Ai::class, 'chat'])->name('chat');
    Route::post('chat', [Ai::class, 'send'])->middleware('throttle:30,1')->name('send');
    Route::get('approvals', [Ai::class, 'approvals'])->name('approvals');
    Route::post('approvals/{aiRequest}/approve', [Ai::class, 'approve'])->name('approvals.approve');
    Route::post('approvals/{aiRequest}/reject', [Ai::class, 'reject'])->name('approvals.reject');
    Route::get('documents', [Ai::class, 'documents'])->name('documents');
    Route::post('documents', [Ai::class, 'upload'])->middleware('throttle:20,1')->name('documents.upload');
    Route::get('documents/{aiDocument}', [Ai::class, 'document'])->name('documents.show');
    Route::post('documents/{aiDocument}/review', [Ai::class, 'review'])->name('documents.review');
    Route::get('anomalies', [Ai::class, 'anomalies'])->name('anomalies');
    Route::get('calls', [Ai::class, 'calls'])->name('calls');

    Route::get('settings', [Admin::class, 'settings'])->name('settings');
    Route::put('settings', [Admin::class, 'saveSettings'])->name('settings.save');
    Route::get('prompts', [Admin::class, 'prompts'])->name('prompts');
    Route::post('prompts', [Admin::class, 'savePrompt'])->name('prompts.save');
    Route::post('prompts/{aiPrompt}/activate', [Admin::class, 'activatePrompt'])->name('prompts.activate');
    Route::post('prompts/default', [Admin::class, 'defaultPrompt'])->name('prompts.default');
    Route::get('knowledge', [Admin::class, 'knowledge'])->name('knowledge');
    Route::post('knowledge', [Admin::class, 'saveKnowledge'])->name('knowledge.save');
    Route::post('knowledge/{aiSource}/reindex', [Admin::class, 'reindex'])->name('knowledge.reindex');
    Route::delete('knowledge/{aiSource}', [Admin::class, 'deleteKnowledge'])->name('knowledge.delete');
    Route::get('usage', [Admin::class, 'usage'])->name('usage');
});

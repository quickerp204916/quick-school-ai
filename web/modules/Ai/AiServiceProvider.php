<?php

namespace Modules\Ai;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(base_path('config/ai.php'), 'ai');
        $this->app->scoped(Services\ToolContext::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(base_path('modules/Ai/resources/views'), 'ai');
        Route::model('aiRequest', Models\AiActionRequest::class);
        Route::model('aiDocument', Models\AiDocument::class);
        Route::model('aiPrompt', Models\AiPrompt::class);
        Route::model('aiSource', Models\AiKnowledgeSource::class);
        Route::model('aiConversation', Models\AiConversation::class);
        view()->composer('ai::*', fn ($view) => $view->with('money', fn (int $p) => \Modules\Accounting\Support\Amount::format($p)));
    }
}

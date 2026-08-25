<?php

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Pijler\LaravelTags\TagCreator;
use Workbench\App\Models\Tag;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        TagCreator::useTagModel(Tag::class);
    }
}

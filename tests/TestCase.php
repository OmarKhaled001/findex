<?php

declare(strict_types=1);

namespace OmarKhaled\Findex\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use OmarKhaled\Findex\Facades\Findex;
use OmarKhaled\Findex\FindexServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Override;

abstract class TestCase extends Orchestra
{
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [FindexServiceProvider::class];
    }

    #[Override]
    protected function getPackageAliases($app): array
    {
        return ['Findex' => Findex::class];
    }

    #[Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.locale', 'en');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    #[Override]
    protected function defineDatabaseMigrations(): void
    {
        Schema::create('categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('parent_id')->nullable();
            $table->string('name');
            $table->findex();
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->nullable();
            $table->string('name');
            $table->string('sku')->nullable();
            $table->text('description')->nullable();
            $table->unsignedInteger('stock')->default(0);
            $table->findex();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('status')->nullable();
            $table->json('meta')->nullable();
            $table->text('raw')->nullable();
            $table->boolean('published')->default(false);
            $table->findex('needle');
        });

        Schema::create('tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id');
            $table->string('name');
        });

        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->json('title');
            $table->text('body')->nullable();
            $table->findex();
        });

        Schema::create('notes', function (Blueprint $table): void {
            $table->id();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->findex();
        });
    }

    /**
     * Change the package configuration and rebuild the singleton that captured it.
     *
     * @param  array<string, mixed>  $config
     */
    protected function configureFindex(array $config): void
    {
        foreach ($config as $key => $value) {
            config()->set('findex.'.$key, $value);
        }

        $this->app->forgetInstance(\OmarKhaled\Findex\Findex::class);
        Findex::clearResolvedInstances();
    }
}

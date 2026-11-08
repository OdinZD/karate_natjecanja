<?php

namespace App\Providers;

use App\Models\Tatami;
use App\Models\User;
use App\Support\Croatian;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureNameOrdering();
    }

    /**
     * Add orderByName(), which sorts names in Croatian alphabetical order on every database the app runs on.
     */
    protected function configureNameOrdering(): void
    {
        QueryBuilder::macro('orderByName', function (string $column, string $direction = 'asc') {
            /** @var QueryBuilder $this */
            $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';
            $wrapped = $this->getGrammar()->wrap($column);

            return match ($this->getConnection()->getDriverName()) {
                'sqlite' => tap($this, fn () => Croatian::registerCollation($this->getConnection()->getPdo()))->orderByRaw($wrapped.' collate hr '.$direction),
                'mysql', 'mariadb' => $this->orderByRaw($wrapped.' collate utf8mb4_croatian_ci '.$direction),
                default => $this->orderBy($column, $direction),
            };
        });
    }

    /**
     * Configure authorization gates.
     */
    protected function configureGates(): void
    {
        Gate::define('admin', fn (User $user): bool => $user->isAdmin());
        Gate::define('operate-tatami', fn (User $user, Tatami $tatami): bool => $user->isAdmin() || $user->tatamis()->whereKey($tatami->id)->exists());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}

<?php

namespace App\Providers;

use App\Http\Presenter\PortfolioPresenter;
use Illuminate\Support\ServiceProvider;
use JFlahaut\DevFullStack\Domain\Presenter\PortfolioPresenterInterface;

class PortfolioServiceProvider extends ServiceProvider
{

    public $bindings = [
        PortfolioPresenterInterface::class => PortfolioPresenter::class,
    ];

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {

    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller as BaseController;
use JFlahaut\DevFullStack\Domain\Presenter\PortfolioPresenterInterface;
use JFlahaut\DevFullStack\Domain\Request\PortfolioRequest;
use JFlahaut\DevFullStack\Domain\UseCase\Portfolio;

/**
 * Class PortfolioController
 * @package App\Http\Controllers
 */
class PortfolioController extends BaseController
{
    /**
     * @param PortfolioRequest $request
     * @param Portfolio $portfolio
     * @param PortfolioPresenterInterface $portfolioPresenter
     * @return JsonResponse
     */
    public function index(PortfolioRequest $request, Portfolio $portfolio, PortfolioPresenterInterface $portfolioPresenter): JsonResponse
    {
        $response = $portfolio->execute($request);

        return $portfolioPresenter->present($response);
    }
}

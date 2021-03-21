<?php


namespace App\Http\Presenter;


use App\Http\ViewModel\PortfolioViewModel;
use Illuminate\Http\JsonResponse;
use JFlahaut\DevFullStack\Domain\Presenter\PortfolioPresenterInterface;
use JFlahaut\DevFullStack\Domain\Response\PortfolioResponse;

/**
 * Class PortfolioPresenter
 * @package App\Http\Presenter
 */
class PortfolioPresenter implements PortfolioPresenterInterface
{
    /**
     * @param PortfolioResponse $portfolioResponse
     * @return JsonResponse
     */
    public function present(PortfolioResponse $portfolioResponse): JsonResponse
    {
        $portfolioViewModel = new PortfolioViewModel($portfolioResponse->getPortfolios());

        return new JsonResponse($portfolioViewModel->getPortfolios());
    }
}

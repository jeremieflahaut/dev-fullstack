<?php


namespace JFlahaut\DevFullStack\Domain\Presenter;


use JFlahaut\DevFullStack\Domain\Response\PortfolioResponse;

/**
 * Interface PortfolioPresenterInterface
 * @package JFlahaut\DevFullStack\Domain\Presenter
 */
interface PortfolioPresenterInterface
{
    /**
     * @param PortfolioResponse $portfolioResponse
     * @return mixed
     */
    public function present(PortfolioResponse $portfolioResponse);
}

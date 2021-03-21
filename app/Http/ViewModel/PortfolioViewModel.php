<?php


namespace App\Http\ViewModel;

/**
 * Class PortfolioViewModel
 * @package App\Http\ViewModel
 */
class PortfolioViewModel
{
    /**
     * @var array
     */
    private $portfolios = [];

    /**
     * PortfolioViewModel constructor.
     * @param array $portfolios
     */
    public function __construct(array $portfolios)
    {
        $this->portfolios = $portfolios;
    }

    /**
     * @return array
     */
    public function getPortfolios(): array
    {
        return $this->portfolios;
    }
}

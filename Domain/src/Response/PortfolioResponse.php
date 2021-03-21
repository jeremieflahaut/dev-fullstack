<?php


namespace JFlahaut\DevFullStack\Domain\Response;

/**
 * Class PortfolioResponse
 * @package JFlahaut\DevFullStack\Domain\Response
 */
class PortfolioResponse
{
    /**
     * @var array
     */
    private $portfolios = [];

    /**
     * PortfolioResponse constructor.
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

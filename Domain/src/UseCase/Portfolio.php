<?php

namespace JFlahaut\DevFullStack\Domain\UseCase;

use JFlahaut\DevFullStack\Domain\Request\PortfolioRequest;
use JFlahaut\DevFullStack\Domain\Response\PortfolioResponse;

/**
 * Class Portfolio
 * @package JFlahaut\DevFullStack\Domain\UseCase
 */
class Portfolio
{
    /**
     * @param PortfolioRequest $request
     * @return PortfolioResponse
     */
    public function execute(PortfolioRequest $request): PortfolioResponse
    {
        return new PortfolioResponse([
            [
                'title' => 'projet 1',
                'description' => 'Description projet 1',
                'url' => 'https://www.projet1.fr',
                'git' => 'https://github.com/projet1'
            ],
            [
                'title' => 'projet 2',
                'description' => 'Description projet 2',
                'url' => 'https://www.projet2.fr',
                'git' => 'https://github.com/projet2'
            ]
        ]);
    }
}

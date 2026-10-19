<?php

declare(strict_types=1);

namespace NITSAN\NsT3AL\Tests\Unit\Support;

use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * RequestFactory double that records every request in $requests.
 */
trait RecordingRequestFactoryTrait
{
    /** @var list<array{uri: string, method: string, options: array}> */
    private array $requests = [];

    /**
     * @param \Closure(string $uri, string $method, array $options): ResponseInterface $respond Throw to simulate failures
     */
    private function createRequestFactory(\Closure $respond): RequestFactory
    {
        $requestFactory = $this->createStub(RequestFactory::class);
        $requestFactory->method('request')->willReturnCallback(
            function (string $uri, string $method = 'GET', array $options = []) use ($respond): ResponseInterface {
                $this->requests[] = ['uri' => $uri, 'method' => $method, 'options' => $options];
                return $respond($uri, $method, $options);
            }
        );
        return $requestFactory;
    }
}

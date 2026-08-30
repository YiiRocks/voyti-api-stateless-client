<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Http\Message\ResponseInterface;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;

/**
 * Provides `expectResponse()` for controller tests that mock `DataResponseFactoryInterface`.
 *
 * The consuming test class must declare `private DataResponseFactoryInterface&MockObject $responseFactory;`
 * and pass it to the controller under test.
 */
trait ExpectsResponseTrait
{
    private function expectResponse(mixed $with, int $status): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
        $this->responseFactory->expects($this->once())
            ->method('createResponse')
            ->with($with, $status)
            ->willReturn($response);

        return $response;
    }
}

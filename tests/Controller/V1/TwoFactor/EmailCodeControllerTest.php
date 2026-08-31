<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\TwoFactor;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\EmailCodeController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\MailCapture;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Email\Service\EmailCodeGeneratorService;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;

#[AllowMockObjectsWithoutExpectations]
final class EmailCodeControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private MailCapture $mailCapture;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mailCapture = new MailCapture();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testSendCode(): void
    {
        $user = $this->createUser('emailcodeuser', 'emailcode@example.com');

        $response = $this->expectResponse(['message' => 'Verification code sent.'], Status::OK);
        self::assertSame($response, $this->createController($user)->sendCode());

        // The code is persisted as the pending secret and emailed, but never returned in the response.
        $secret = UserTwoFactor::forUser($user)->getSecret();
        self::assertNotNull($secret);
        self::assertMatchesRegularExpression('/^\d{6}$/', $secret);
        $sentMessage = $this->mailCapture->getLastMessage();
        self::assertNotNull($sentMessage);
        self::assertSame('emailcode@example.com', $sentMessage->getTo());

        // Already enabled: no fresh code is generated or emailed.
        $this->enableTwoFactor($user, 'email');
        $this->mailCapture->clear();

        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->sendCode());
        self::assertSame($secret, UserTwoFactor::forUser($user)->getSecret());
        self::assertNull($this->mailCapture->getLastMessage());
    }

    private function createController(User $user): EmailCodeController
    {
        return new EmailCodeController(
            $this->createCurrentUser($user),
            $this->responseFactory,
            new EmailCodeGeneratorService($this->mailCapture, $this->createTranslator()),
            $this->createTranslator(),
        );
    }
}

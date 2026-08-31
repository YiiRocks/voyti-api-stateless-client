<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\Gdpr;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Gdpr\GdprController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\TestPasswordHasherFactory;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Gdpr\Service\AnonymizeUserService;
use YiiRocks\Voyti\Gdpr\Service\GdprExportService;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Model\UserToken;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Security\PasswordHasher;

#[AllowMockObjectsWithoutExpectations]
final class GdprControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private ApiTokenService $apiTokenService;
    private PasswordHasher $passwordHasher;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiTokenService = new ApiTokenService(new SystemClock());
        $this->passwordHasher = TestPasswordHasherFactory::create();
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testAnonymize(): void
    {
        $user = $this->createUser('anonuser', 'anon@example.com', $this->passwordHasher->hash('correct-password'));
        $rawToken = $this->apiTokenService->generate($user);

        // Wrong password: no changes
        // Asserts the raw, untranslated key rather than resolved text: the real fix lives on
        // voyti-gdpr's `voyti.view.anonymize.invalid_password` (category 'voyti-gdpr'), which only
        // exists on that package's main branch so far - no tagged release contains it yet, so this
        // reflects what actually ships until voyti-gdpr cuts one. TODO: expect 'Incorrect password'
        // once voyti-gdpr 1.0.3 is released.
        $response = $this->expectResponse(['error' => 'voyti.view.anonymize.invalid_password'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->anonymize(password: 'wrong-password'));
        self::assertSame('anon@example.com', $user->getEmail());
        self::assertNotNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $rawToken, UserToken::TYPE_API_ACCESS));

        // Correct password: anonymizes and revokes every bearer token
        $response = $this->expectResponse(['message' => 'Your account has been anonymized'], Status::OK);
        self::assertSame($response, $this->createController($user)->anonymize(password: 'correct-password'));
        self::assertNotSame('anon@example.com', $user->getEmail());
        self::assertTrue($user->isBlocked());
        self::assertNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $rawToken, UserToken::TYPE_API_ACCESS));
    }

    public function testExport(): void
    {
        $user = $this->createUser('exportuser', 'export@example.com');

        $response = $this->expectResponse(['email' => 'export@example.com', 'username' => 'exportuser'], Status::OK);
        self::assertSame($response, $this->createController($user)->export());
    }

    private function createController(User $user): GdprController
    {
        return new GdprController(
            new AnonymizeUserService(new EventCaptureDispatcher(), 'GDPR'),
            $this->apiTokenService,
            $this->createCurrentUser($user),
            new GdprExportService(['email', 'username']),
            $this->passwordHasher,
            $this->responseFactory,
            $this->createTranslator(),
        );
    }
}

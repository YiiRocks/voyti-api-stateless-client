<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\TwoFactor;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\Service\User\ApiTokenService;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\ChallengeController;
use YiiRocks\Voyti\Api\StatelessClient\Service\ApiLoginCompletionService;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\EventCaptureDispatcher;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\FakeTwoFactorMethod;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Event\Auth\FailedLoginEvent;
use YiiRocks\Voyti\Model\UserToken;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Security\PasswordHasher;

#[AllowMockObjectsWithoutExpectations]
final class ChallengeControllerTest extends DatabaseTestCase
{
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private ApiTokenService $apiTokenService;
    private BackupCodeService $backupCodeService;
    private EventCaptureDispatcher $eventDispatcher;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->apiTokenService = new ApiTokenService(new SystemClock());
        $this->backupCodeService = new BackupCodeService(new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testVerify(): void
    {
        $user = $this->createUser('challengeuser', 'challenge@example.com');
        $this->enableTwoFactor($user, 'fake');

        $challengeToken = 'raw-challenge-token';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);

        // Invalid/unknown challenge token
        $response = $this->expectResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: 'wrong'));
        $this->assertFailedVerificationRecorded();

        // Wrong code: the method's own error message is used verbatim
        $response = $this->expectResponse(['error' => 'Fake method error.'], Status::UNAUTHORIZED);
        self::assertSame(
            $response,
            $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: 'wrong-code'),
        );
        $this->assertFailedVerificationRecorded();

        // Correct code: issues a real token and consumes the challenge
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['status'] === 'ok' && strlen($data['token']) === 64),
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: 'correct-code'),
        );
        self::assertNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $challengeToken, UserToken::TYPE_API_CHALLENGE));

        // Reusing the (now-deleted) challenge token fails
        $response = $this->expectResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: 'correct-code'));
        $this->assertFailedVerificationRecorded();
    }

    public function testVerifyClientCollectedMethod(): void
    {
        $user = $this->createUser('webauthnuser', 'webauthn@example.com');
        $this->enableTwoFactor($user, 'client-collected');

        $challengeToken = 'raw-webauthn-challenge';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['status'] === 'ok'),
            Status::OK,
        );
        $eventDispatcher = new EventCaptureDispatcher();
        $controller = new ChallengeController(
            new ApiLoginCompletionService($this->apiTokenService, $eventDispatcher, []),
            $this->backupCodeService,
            $this->responseFactory,
            new TwoFactorMethodRegistry([$this->fakeClientCollectedMethod()]),
            $this->createTranslator(),
        );
        $request = (new ServerRequest('POST', 'https://example.com/v1/auth/challenge/verify'));
        self::assertSame($response, $controller->verify($request, challengeToken: $challengeToken, payload: 'expected-payload'));
    }

    public function testVerifyFailureWithoutEventDispatcher(): void
    {
        $response = $this->expectResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        $controller = new ChallengeController(
            new ApiLoginCompletionService($this->apiTokenService, new EventCaptureDispatcher(), []),
            $this->backupCodeService,
            $this->responseFactory,
            new TwoFactorMethodRegistry([$this->fakeMethod()]),
            $this->createTranslator(),
        );

        self::assertSame($response, $controller->verify(new ServerRequest('POST', '/'), challengeToken: 'wrong'));
    }

    public function testVerifyFallsBackToBackupCode(): void
    {
        $user = $this->createUser('backupuser', 'backup@example.com');
        $this->enableTwoFactor($user, 'fake');

        $challengeToken = 'raw-backup-challenge';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);

        $codes = $this->backupCodeService->generate($user);
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['status'] === 'ok'),
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: $codes[0]),
        );
    }

    public function testVerifyFallsBackToGenericMessageWhenMethodHasNone(): void
    {
        $user = $this->createUser('noerroruser', 'noerror@example.com');
        $this->enableTwoFactor($user, 'silent');

        $challengeToken = 'raw-silent-challenge';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);

        $response = $this->expectResponse(['error' => 'Invalid verification code.'], Status::UNAUTHORIZED);
        $eventDispatcher = new EventCaptureDispatcher();
        $controller = new ChallengeController(
            new ApiLoginCompletionService($this->apiTokenService, $eventDispatcher, []),
            $this->backupCodeService,
            $this->responseFactory,
            new TwoFactorMethodRegistry([$this->fakeSilentMethod()]),
            $this->createTranslator(),
            $eventDispatcher,
        );
        self::assertSame($response, $controller->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: 'wrong'));
        self::assertTrue($eventDispatcher->hasEvent(FailedLoginEvent::class));
    }

    public function testVerifyMethodNoLongerAvailable(): void
    {
        $user = $this->createUser('goneuser', 'gone@example.com');
        $this->enableTwoFactor($user, 'no-longer-registered');

        $challengeToken = 'raw-gone-challenge';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);

        $response = $this->expectResponse(['error' => 'Two-factor method is no longer available.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken));
        $this->assertFailedVerificationRecorded();
    }

    public function testVerifyOrphanedChallengeToken(): void
    {
        // A challenge token whose user has since been deleted: rejected, not a fatal error.
        $orphanToken = 'raw-orphan-challenge';
        $this->createChallengeToken(999999, $orphanToken);

        $response = $this->expectResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $orphanToken));
        $this->assertFailedVerificationRecorded();
    }

    public function testVerifyRejectsChallengeThatWasConsumedConcurrently(): void
    {
        $user = $this->createUser('concurrentchallenge', 'concurrent-challenge@example.com');
        $this->enableTwoFactor($user, 'fake');

        $challengeToken = 'raw-concurrent-challenge';
        $this->createChallengeToken((int) $user->getId(), $challengeToken);
        $this->executeRawDatabaseCommand(
            'CREATE TRIGGER ignore_api_challenge_consumption BEFORE DELETE ON user_token '
            . 'WHEN OLD.type = ' . UserToken::TYPE_API_CHALLENGE
            . ' BEGIN SELECT RAISE(IGNORE); END;',
        );

        $response = $this->expectResponse(['error' => 'Challenge is invalid or expired.'], Status::BAD_REQUEST);
        self::assertSame(
            $response,
            $this->createController()->verify(new ServerRequest('POST', '/'), challengeToken: $challengeToken, code: 'correct-code'),
        );
        $this->assertFailedVerificationRecorded();
        self::assertNotNull(UserToken::findByUserIdAndCodeAndType((int) $user->getId(), $challengeToken, UserToken::TYPE_API_CHALLENGE));
    }

    private function assertFailedVerificationRecorded(): void
    {
        /** @var ?FailedLoginEvent $event */
        $event = $this->eventDispatcher->getEvent(FailedLoginEvent::class);
        self::assertInstanceOf(FailedLoginEvent::class, $event);
        self::assertSame('invalid_two_factor', $event->getReason());
    }

    private function createController(): ChallengeController
    {
        $this->eventDispatcher = new EventCaptureDispatcher();

        return new ChallengeController(
            new ApiLoginCompletionService($this->apiTokenService, $this->eventDispatcher, []),
            $this->backupCodeService,
            $this->responseFactory,
            new TwoFactorMethodRegistry([$this->fakeMethod()]),
            $this->createTranslator(),
            $this->eventDispatcher,
        );
    }

    private function fakeClientCollectedMethod(): FakeTwoFactorMethod
    {
        return new FakeTwoFactorMethod(
            name: 'client-collected',
            buttonLabel: 'Client-collected',
            errorMessage: 'Verification failed.',
            isCodeBased: false,
            verify: static fn(mixed $user, array $data): bool => ($data['payload'] ?? '') === 'expected-payload' && ($data['domain'] ?? '') !== '',
        );
    }

    private function fakeMethod(): FakeTwoFactorMethod
    {
        return new FakeTwoFactorMethod(
            verify: static fn(mixed $user, array $data): bool => ($data['code'] ?? '') === 'correct-code',
        );
    }

    private function fakeSilentMethod(): FakeTwoFactorMethod
    {
        return new FakeTwoFactorMethod(
            name: 'silent',
            buttonLabel: 'Silent',
            errorMessage: '',
        );
    }
}

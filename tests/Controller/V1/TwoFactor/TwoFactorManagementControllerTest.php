<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\TwoFactor;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\TwoFactorManagementController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\FakeTwoFactorMethod;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\Service\TwoFactorDisableService;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodInterface;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodRegistry;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Security\PasswordHasher;

#[AllowMockObjectsWithoutExpectations]
final class TwoFactorManagementControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private BackupCodeService $backupCodeService;
    private DataResponseFactoryInterface&MockObject $responseFactory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupCodeService = new BackupCodeService(new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
    }

    public function testDisable(): void
    {
        $user = $this->createUser('disableuser', 'disable@example.com');

        // Not enabled
        $response = $this->expectResponse(['error' => 'Two-factor authentication is not enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->disable(new ServerRequest('POST', '/')));

        $this->enableTwoFactor($user, 'fake');
        $codes = $this->backupCodeService->generate($user);

        // Wrong code
        $response = $this->expectResponse(['error' => 'Fake method error.'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController($user)->disable(new ServerRequest('POST', '/'), code: 'wrong'));

        // A backup code also satisfies re-authentication
        $response = $this->expectResponse(['message' => 'Two-factor authentication has been disabled'], Status::OK);
        self::assertSame($response, $this->createController($user)->disable(new ServerRequest('POST', '/'), code: $codes[0]));
        self::assertFalse(UserTwoFactor::forUser($user)->isEnabled());
        self::assertFalse($this->backupCodeService->hasUnused($user));
    }

    public function testDisableClientCollectedMethod(): void
    {
        $user = $this->createUser('webauthndisableuser', 'webauthndisable@example.com');
        $this->enableTwoFactor($user, 'client-collected');

        $request = new ServerRequest('POST', 'https://example.com/v1/2fa/disable');

        $response = $this->expectResponse(['error' => 'Verification failed.'], Status::UNAUTHORIZED);
        $controller = $this->createController($user, methods: [$this->fakeClientCollectedMethod()]);
        self::assertSame($response, $controller->disable($request, payload: 'wrong-payload'));

        $response = $this->expectResponse(['message' => 'Two-factor authentication has been disabled'], Status::OK);
        $controller = $this->createController($user, methods: [$this->fakeClientCollectedMethod()]);
        self::assertSame($response, $controller->disable($request, payload: 'expected-payload'));
    }

    public function testDisableResolvesTheStoredMethodNotTheDefault(): void
    {
        // Two methods registered: "fake" is first (the default), "second-fake" is stored on the
        // account. Only "second-fake"'s code must be accepted, proving the stored method - not the
        // default - is the one actually resolved and verified against.
        $user = $this->createUser('seconduser', 'second@example.com');
        $this->enableTwoFactor($user, 'second-fake');
        $methods = [$this->fakeMethod(), $this->fakeSecondMethod()];

        $response = $this->expectResponse(['error' => 'Second fake method error.'], Status::UNAUTHORIZED);
        $controller = $this->createController($user, methods: $methods);
        self::assertSame($response, $controller->disable(new ServerRequest('POST', '/'), code: 'correct-code'));

        $response = $this->expectResponse(['message' => 'Two-factor authentication has been disabled'], Status::OK);
        $controller = $this->createController($user, methods: $methods);
        self::assertSame($response, $controller->disable(new ServerRequest('POST', '/'), code: 'second-correct-code'));
    }

    public function testEnable(): void
    {
        $user = $this->createUser('enableuser', 'enable@example.com');

        // Wrong code
        $response = $this->expectResponse(['error' => 'Fake method error.'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController($user)->enable(method: 'fake', code: 'wrong'));

        // Unknown method name falls back to the default
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['message'] === 'Two-factor authentication has been enabled' && count($data['backupCodes']) === 10),
            Status::CREATED,
        );
        self::assertSame($response, $this->createController($user)->enable(method: 'unknown-method', code: 'correct-code'));
        self::assertTrue(UserTwoFactor::forUser($user)->isEnabled());
        self::assertSame('fake', UserTwoFactor::forUser($user)->getMethod());

        // Already enabled
        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->enable(method: 'fake', code: 'correct-code'));
    }

    public function testEnableNoMethodAvailable(): void
    {
        $user = $this->createUser('nomethoduser', 'nomethod@example.com');

        $response = $this->expectResponse(['error' => 'No two-factor method is available.'], Status::BAD_REQUEST);
        $controller = $this->createController($user, methods: []);
        self::assertSame($response, $controller->enable(method: 'fake', code: 'correct-code'));
    }

    public function testEnableRejectsNonCodeBasedMethod(): void
    {
        $user = $this->createUser('webauthnenableuser', 'webauthnenable@example.com');

        $response = $this->expectResponse(['error' => 'This method must be set up through its own endpoint.'], Status::BAD_REQUEST);
        $controller = $this->createController($user, methods: [$this->fakeClientCollectedMethod()]);
        self::assertSame($response, $controller->enable(method: 'client-collected', code: 'anything'));
    }

    public function testRegenerateBackupCodes(): void
    {
        $user = $this->createUser('regenuser', 'regen@example.com');

        // Not enabled
        $response = $this->expectResponse(['error' => 'Two-factor authentication is not enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->regenerateBackupCodes(new ServerRequest('POST', '/')));

        $this->enableTwoFactor($user, 'fake');

        // Wrong code
        $response = $this->expectResponse(['error' => 'Fake method error.'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController($user)->regenerateBackupCodes(new ServerRequest('POST', '/'), code: 'wrong'));

        // Success
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['message'] === 'Backup codes regenerated.' && count($data['backupCodes']) === 10),
            Status::OK,
        );
        self::assertSame(
            $response,
            $this->createController($user)->regenerateBackupCodes(new ServerRequest('POST', '/'), code: 'correct-code'),
        );
        self::assertTrue($this->backupCodeService->hasUnused($user));
    }

    public function testStatus(): void
    {
        $user = $this->createUser('statususer', 'status@example.com');

        // Not enabled yet
        $response = $this->expectResponse([
            'enabled' => false,
            'method' => null,
            'hasUnusedBackupCodes' => false,
            'availableMethods' => [['name' => 'fake', 'isCodeBased' => true, 'requiresCodeDelivery' => false]],
        ], Status::OK);
        self::assertSame($response, $this->createController($user)->status());

        // Enabled, with backup codes
        $this->enableTwoFactor($user, 'fake');
        $this->backupCodeService->generate($user);

        $response = $this->expectResponse([
            'enabled' => true,
            'method' => 'fake',
            'hasUnusedBackupCodes' => true,
            'availableMethods' => [['name' => 'fake', 'isCodeBased' => true, 'requiresCodeDelivery' => false]],
        ], Status::OK);
        self::assertSame($response, $this->createController($user)->status());
    }

    /**
     * @param list<TwoFactorMethodInterface>|null $methods
     */
    private function createController(User $user, ?array $methods = null): TwoFactorManagementController
    {
        $registry = new TwoFactorMethodRegistry($methods ?? [$this->fakeMethod()]);

        return new TwoFactorManagementController(
            $this->backupCodeService,
            $this->createCurrentUser($user),
            $this->responseFactory,
            new TwoFactorDisableService($registry, $this->backupCodeService),
            $registry,
            $this->createTranslator(),
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

    private function fakeSecondMethod(): FakeTwoFactorMethod
    {
        return new FakeTwoFactorMethod(
            name: 'second-fake',
            buttonLabel: 'Second fake',
            errorMessage: 'Second fake method error.',
            verify: static fn(mixed $user, array $data): bool => ($data['code'] ?? '') === 'second-correct-code',
        );
    }
}

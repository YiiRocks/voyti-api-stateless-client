<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Controller\V1\TwoFactor;

use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use ReportUri\Passkeys\WebAuthn;
use ReportUri\Passkeys\WebAuthnException;
use stdClass;
use YiiRocks\Voyti\Api\StatelessClient\Controller\V1\TwoFactor\WebauthnEnrollmentController;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\CurrentUserTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\DatabaseTestCase;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\ExpectsResponseTrait;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\FakeSession;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\StubWebAuthn;
use YiiRocks\Voyti\Api\StatelessClient\tests\Support\UserFactoryTrait;
use YiiRocks\Voyti\Clock\SystemClock;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\Model\UserTwoFactor;
use YiiRocks\Voyti\TwoFactor\Service\BackupCodeService;
use YiiRocks\Voyti\TwoFactor\Webauthn\Model\UserWebauthnCredential;
use YiiRocks\Voyti\TwoFactor\Webauthn\Service\WebauthnService;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Security\PasswordHasher;

#[AllowMockObjectsWithoutExpectations]
final class WebauthnEnrollmentControllerTest extends DatabaseTestCase
{
    use CurrentUserTrait;
    use ExpectsResponseTrait;
    use UserFactoryTrait;

    private BackupCodeService $backupCodeService;
    private DataResponseFactoryInterface&MockObject $responseFactory;
    private StubWebAuthn $webAuthn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupCodeService = new BackupCodeService(new PasswordHasher(PASSWORD_BCRYPT, ['cost' => 4]));
        $this->responseFactory = $this->createMock(DataResponseFactoryInterface::class);
        $this->webAuthn = new StubWebAuthn();
    }

    public function testFinish(): void
    {
        $user = $this->createUser('webauthnfinish', 'webauthnfinish@example.com');

        // No pending registration
        $response = $this->expectResponse(['error' => 'No pending WebAuthn registration was found.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->finish(new ServerRequest('POST', '/')));

        // Start a registration to have a pending challenge to finish.
        $response = $this->expectResponse($this->callback(static fn(array $data): bool => isset($data['requestOptions'])), Status::OK);
        self::assertSame($response, $this->createController($user)->start(new ServerRequest('GET', 'https://example.com/')));
        $challenge = UserTwoFactor::forUser($user)->getSecret();
        self::assertNotNull($challenge);

        // Verification failure surfaces the service's error message and leaves the pending state
        $this->webAuthn->createException = new WebAuthnException('boom');
        $response = $this->expectResponse(['error' => 'voyti-2fa-webauthn.error.verification_failed'], Status::UNAUTHORIZED);
        self::assertSame($response, $this->createController($user)->finish(new ServerRequest('POST', '/')));
        self::assertSame($challenge, UserTwoFactor::forUser($user)->getSecret());

        // Success persists the credential, enables 2FA, clears the pending challenge, and returns
        // fresh backup codes.
        $this->webAuthn->createException = null;
        $this->webAuthn->createResult = $this->validCreateResult();
        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => $data['message'] === 'Two-factor authentication enabled.' && count($data['backupCodes']) === 10),
            Status::CREATED,
        );
        self::assertSame($response, $this->createController($user)->finish(
            new ServerRequest('POST', '/'),
            clientDataJSON: base64_encode('{}'),
            attestationObject: base64_encode('raw'),
        ));
        self::assertSame($challenge, $this->webAuthn->lastCreateChallenge);
        self::assertSame('{}', $this->webAuthn->lastCreateClientDataJSON);
        self::assertSame('raw', $this->webAuthn->lastCreateAttestationObject);

        $twoFactor = UserTwoFactor::forUser($user);
        self::assertTrue($twoFactor->isEnabled());
        self::assertSame('webauthn', $twoFactor->getMethod());
        self::assertNull($twoFactor->getSecret());
        self::assertNotNull(UserWebauthnCredential::findByUserIdAndCredentialId($user->getIdOrZero(), base64_encode('credential-id-binary')));

        // Already enabled
        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->finish(new ServerRequest('POST', '/')));
    }

    public function testStart(): void
    {
        $user = $this->createUser('webauthnstart', 'webauthnstart@example.com');

        $response = $this->expectResponse(
            $this->callback(static fn(array $data): bool => isset($data['requestOptions'])),
            Status::OK,
        );
        self::assertSame($response, $this->createController($user)->start(new ServerRequest('GET', 'https://example.com/')));
        self::assertNotNull(UserTwoFactor::forUser($user)->getSecret());

        // Already enabled
        $this->enableTwoFactor($user, 'webauthn');

        $response = $this->expectResponse(['error' => 'Two-factor authentication is already enabled.'], Status::BAD_REQUEST);
        self::assertSame($response, $this->createController($user)->start(new ServerRequest('GET', '/')));
    }

    private function createController(User $user): WebauthnEnrollmentController
    {
        $webauthnService = new WebauthnService(
            new FakeSession(),
            $this->createTranslator(),
            new SystemClock(),
            fn(): WebAuthn => $this->webAuthn,
        );

        return new WebauthnEnrollmentController(
            $this->backupCodeService,
            $this->createCurrentUser($user),
            $this->responseFactory,
            $webauthnService,
        );
    }

    private function validCreateResult(): stdClass
    {
        $result = new stdClass();
        $result->credentialId = 'credential-id-binary';
        $result->credentialPublicKey = '-----BEGIN PUBLIC KEY-----';
        $result->signatureCounter = 3;
        $result->AAGUID = 'aaguid-hex';
        $result->isBackupEligible = true;
        $result->isBackedUp = true;

        return $result;
    }
}

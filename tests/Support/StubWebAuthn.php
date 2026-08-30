<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use ReportUri\Passkeys\WebAuthn;
use ReportUri\Passkeys\WebAuthnException;
use stdClass;

/**
 * Test double for the WebAuthn server library: real constructor (so `getCreateArgs()` and challenge
 * generation work exactly as in production) with the registration verification entry point stubbed,
 * so tests can simulate successful and failed ceremonies deterministically.
 */
final class StubWebAuthn extends WebAuthn
{
    public ?WebAuthnException $createException = null;
    public ?stdClass $createResult = null;
    public mixed $lastCreateAttestationObject = null;
    public mixed $lastCreateChallenge = null;
    public mixed $lastCreateClientDataJSON = null;

    public function __construct()
    {
        parent::__construct('Voyti Test', 'localhost', true);
    }

    public function processCreate(
        mixed $clientDataJSON,
        mixed $attestationObject,
        mixed $challenge,
        mixed $requireUserVerification = false,
        mixed $requireUserPresent = true,
    ): mixed {
        $this->lastCreateClientDataJSON = $clientDataJSON;
        $this->lastCreateAttestationObject = $attestationObject;
        $this->lastCreateChallenge = $challenge;

        if ($this->createException !== null) {
            throw $this->createException;
        }

        return $this->createResult;
    }
}

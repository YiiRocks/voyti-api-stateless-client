<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use Closure;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\TwoFactor\TwoFactorMethodInterface;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Translator\TranslatorInterface;

/**
 * Configurable fake `TwoFactorMethodInterface`, for tests that need to control 2FA verification
 * without exercising a real method implementation (TOTP, email code, WebAuthn, ...).
 */
final class FakeTwoFactorMethod implements TwoFactorMethodInterface
{
    public bool $stepStarted = false;

    public function __construct(
        private readonly string $name = 'fake',
        private readonly string $buttonLabel = 'Fake',
        private readonly string $errorMessage = 'Fake method error.',
        private readonly bool $isCodeBased = true,
        private readonly bool $requiresCodeDelivery = false,
        private readonly ?Closure $verify = null,
    ) {}

    public function getButtonLabel(TranslatorInterface $translator): string
    {
        return $this->buttonLabel;
    }

    public function getConfirmFragmentUrl(UrlGeneratorInterface $url): ?string
    {
        return null;
    }

    public function getEnabledWithMethodName(TranslatorInterface $translator): string
    {
        return $this->buttonLabel;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getReauthFragmentUrl(UrlGeneratorInterface $url): ?string
    {
        return null;
    }

    public function getSettingsUrl(UrlGeneratorInterface $url): string
    {
        return '';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function isCodeBased(): bool
    {
        return $this->isCodeBased;
    }

    public function onAuthenticationStepStart(User $user): void
    {
        $this->stepStarted = true;
    }

    public function onDisable(User $user): void {}

    public function requiresCodeDelivery(): bool
    {
        return $this->requiresCodeDelivery;
    }

    public function verify(User $user, array $data): bool
    {
        return $this->verify !== null && ($this->verify)($user, $data);
    }
}

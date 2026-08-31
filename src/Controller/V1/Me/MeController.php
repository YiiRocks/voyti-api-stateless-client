<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Me;

use Psr\Http\Message\ResponseInterface;
use YiiRocks\Voyti\Exception\ActionPreventedException;
use YiiRocks\Voyti\Model\Form\Settings\SettingsForm;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\EmailChangeService;
use YiiRocks\Voyti\Service\Password\PasswordHistoryService;
use YiiRocks\Voyti\Service\User\UserUpdateHelper;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\User\CurrentUser;

/**
 * The logged-in user's own profile for the SPA API: view and update username/email/password.
 * Reuses core's {@see UserUpdateHelper} (the same helper `voyti-api-user`'s admin endpoint uses) and
 * {@see EmailChangeService} - an email change is never applied immediately, it goes through the same
 * confirmation-link flow core's HTML `AccountController` uses, so self-service and admin API updates
 * can't silently diverge on that behavior.
 */
final readonly class MeController
{
    public function __construct(
        private VoytiConfig $config,
        private CurrentUser $currentUser,
        private EmailChangeService $emailChangeService,
        private PasswordHistoryService $passwordHistoryService,
        private UserUpdateHelper $userUpdateHelper,
        private DataResponseFactoryInterface $responseFactory,
        private TranslatorInterface $translator,
    ) {}

    public function show(): ResponseInterface
    {
        $user = $this->currentUserOrFail();

        return $this->responseFactory->createResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'unconfirmedEmail' => $user->getUnconfirmedEmail(),
            'createdAt' => $user->getCreatedAt(),
            'confirmedAt' => $user->getConfirmedAt(),
            'lastLoginAt' => $user->getLastLoginAt(),
        ]);
    }

    public function update(
        #[Body('username')]
        ?string $username = null,
        #[Body('email')]
        ?string $email = null,
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        $user = $this->currentUserOrFail();

        if ($password !== '' && $this->passwordHistoryService->wasUsedRecently($user, $password)) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.settings.password_previously_used', category: 'voyti')],
                Status::BAD_REQUEST,
            );
        }

        $changedFields = $this->userUpdateHelper->changedFields($user, $username, $email, $password);

        try {
            $this->userUpdateHelper->apply(
                $user,
                $changedFields,
                function (User $user) use ($username, $email): void {
                    if ($username !== null) {
                        $user->setUsername($username);
                    }

                    if ($email !== null && $email !== $user->getEmail()) {
                        $form = new SettingsForm($this->config, $this->translator);
                        $form->setUser($user);
                        $form->email = $email;
                        $this->emailChangeService->initiate($this->config->emailChangeConfirmation, $form);
                    }
                },
                $password,
            );
        } catch (ActionPreventedException $exception) {
            return $this->responseFactory->createResponse(['error' => $exception->getMessage()], Status::BAD_REQUEST);
        }

        return $this->responseFactory->createResponse([
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'unconfirmedEmail' => $user->getUnconfirmedEmail(),
            'message' => $this->translator->translate('voyti.admin.account_updated', category: 'voyti'),
        ]);
    }

    private function currentUserOrFail(): User
    {
        /** @var User $user */
        $user = $this->currentUser->getIdentity();

        return $user;
    }
}

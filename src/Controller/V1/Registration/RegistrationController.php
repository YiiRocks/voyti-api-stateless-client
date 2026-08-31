<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\Controller\V1\Registration;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use YiiRocks\Voyti\Auth\PostRegistrationHookInterface;
use YiiRocks\Voyti\Model\User;
use YiiRocks\Voyti\Service\User\ConfirmationService;
use YiiRocks\Voyti\Service\User\RegisterService;
use YiiRocks\Voyti\VoytiConfig;
use Yiisoft\DataResponse\ResponseFactory\DataResponseFactoryInterface;
use Yiisoft\Http\Status;
use Yiisoft\Input\Http\Attribute\Parameter\Body;
use Yiisoft\Router\HydratorAttribute\RouteArgument;
use Yiisoft\Translator\TranslatorInterface;

/**
 * New-account registration for the SPA API: self-registration, email confirmation, and resending
 * the confirmation email. Delegates all domain logic to core's {@see RegisterService} and
 * {@see ConfirmationService} - the same services core's HTML `RegistrationController` uses - so
 * uniqueness checks, `BeforeRegisterEvent`, and email sending behave identically regardless of
 * whether registration came from the HTML app or this API.
 */
final readonly class RegistrationController
{
    public function __construct(
        private VoytiConfig $config,
        private ConfirmationService $confirmationService,
        /** @var iterable<PostRegistrationHookInterface> */
        private iterable $postRegistrationHooks,
        private RegisterService $registerService,
        private DataResponseFactoryInterface $responseFactory,
        private TranslatorInterface $translator,
    ) {}

    public function confirm(#[RouteArgument] int $id, #[RouteArgument] string $code): ResponseInterface
    {
        $user = User::findById($id);

        if ($user === null || !$this->config->enableEmailConfirmation) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.registration.invalid_confirmation_link', category: 'voyti')],
                Status::BAD_REQUEST,
            );
        }

        if ($user->isConfirmed()) {
            return $this->responseFactory->createResponse([
                'message' => $this->translator->translate(
                    'voyti-api-stateless-client.registration.account_already_confirmed',
                    category: 'voyti-api-stateless-client',
                ),
            ]);
        }

        if ($this->confirmationService->confirmWithCode($code, $user)) {
            return $this->responseFactory->createResponse([
                'message' => $this->translator->translate(
                    'voyti-api-stateless-client.registration.account_confirmed',
                    category: 'voyti-api-stateless-client',
                ),
            ]);
        }

        return $this->responseFactory->createResponse(
            ['error' => $this->translator->translate('voyti.registration.confirmation_link_invalid', category: 'voyti')],
            Status::BAD_REQUEST,
        );
    }

    public function register(
        ServerRequestInterface $request,
        #[Body('username')]
        string $username = '',
        #[Body('email')]
        string $email = '',
        #[Body('password')]
        string $password = '',
    ): ResponseInterface {
        if (!$this->config->enableRegistration) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.registration.disabled', category: 'voyti')],
                Status::FORBIDDEN,
            );
        }

        $result = $this->registerService->run(
            ['username' => $username, 'email' => $email, 'password' => $password],
            $request->getServerParams(),
        );

        if (!$result->isSuccess()) {
            return $this->responseFactory->createResponse(
                ['error' => $result->getMessage(), 'errors' => $result->getErrors()],
                Status::BAD_REQUEST,
            );
        }

        $user = User::findByEmail($email);
        if ($user !== null) {
            foreach ($this->postRegistrationHooks as $postRegistrationHook) {
                $postRegistrationHook->handle($user);
            }
        }

        return $this->responseFactory->createResponse(
            ['message' => $this->translator->translate($result->getMessage(), category: 'voyti')],
            Status::CREATED,
        );
    }

    public function resend(
        #[Body('email')]
        string $email = '',
    ): ResponseInterface {
        if (!$this->config->enableEmailConfirmation) {
            return $this->responseFactory->createResponse(
                ['error' => $this->translator->translate('voyti.registration.email_confirmation_disabled', category: 'voyti')],
                Status::FORBIDDEN,
            );
        }

        $user = User::findByEmail($email);
        if ($user !== null) {
            $this->confirmationService->resend($user);
        }

        // Always the same response whether the address exists or is already confirmed, so this
        // endpoint can't be used to enumerate accounts.
        return $this->responseFactory->createResponse([
            'message' => $this->translator->translate(
                'voyti-api-stateless-client.registration.confirmation_email_sent',
                category: 'voyti-api-stateless-client',
            ),
        ]);
    }
}

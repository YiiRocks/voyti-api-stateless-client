<?php

declare(strict_types=1);

namespace YiiRocks\Voyti\Api\StatelessClient\tests\Support;

use Throwable;
use Yiisoft\Mailer\MailerInterface;
use Yiisoft\Mailer\MessageInterface;
use Yiisoft\Mailer\SendResults;

final class MailCapture implements MailerInterface
{
    /** @var array<array-key, MessageInterface> */
    private array $sentMessages = [];

    public function clear(): void
    {
        $this->sentMessages = [];
    }

    public function compose(): void {}

    public function getLastMessage(): ?MessageInterface
    {
        $messages = $this->sentMessages;

        return $messages !== [] ? $messages[array_key_last($messages)] : null;
    }

    /** @return array<array-key, MessageInterface> */
    public function getSentMessages(): array
    {
        return $this->sentMessages;
    }

    public function send(MessageInterface $message): void
    {
        $this->sentMessages[] = $message;
    }

    public function sendMultiple(array $messages): SendResults
    {
        $success = [];
        $fail = [];

        foreach ($messages as $message) {
            try {
                $this->send($message);
                $success[] = $message;
            } catch (Throwable $e) {
                $fail[] = ['message' => $message, 'error' => $e];
            }
        }

        return new SendResults($success, $fail);
    }
}

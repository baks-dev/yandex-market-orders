<?php

declare(strict_types=1);

namespace BaksDev\Yandex\Market\Orders\Messenger\Orders\Cancel;


final readonly class CancelYaMarketOrderMessage
{
    public function __construct(
        private string|int $number,
        private string $comment
    ) {}

    /**
     * Number
     */
    public function getOrderNumber(): string
    {
        return $this->number;
    }

    /**
     * Comment
     */
    public function getComment(): string
    {
        return $this->comment;
    }
}

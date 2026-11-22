<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown anywhere to end the request with a JSON error response. */
class HttpException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
        public readonly array $errors = [],
        public readonly array $extra = []
    ) {
        parent::__construct($message, $status);
    }

    public static function forbidden(string $msg = 'You do not have permission to perform this action.'): self
    {
        return new self($msg, 403);
    }

    public static function notFound(string $msg = 'Record not found.'): self
    {
        return new self($msg, 404);
    }

    public static function validation(array $errors, string $msg = 'Please correct the highlighted fields.'): self
    {
        return new self($msg, 422, $errors);
    }
}

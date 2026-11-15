<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Someone else saved the record after this user opened it.
 */
class StaleRecordException extends RuntimeException
{
    public function __construct(string $message = 'Someone else changed this record while you were editing it. The page now shows their latest version. Check it, then make your change again.')
    {
        parent::__construct($message);
    }
}

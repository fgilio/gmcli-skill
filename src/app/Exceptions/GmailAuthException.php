<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when Google rejects the credentials of a request.
 *
 * Covers a refresh token Google no longer accepts and an API
 * call answered with 401 or 403. Re-running accounts:add for
 * the address is the fix for both.
 */
class GmailAuthException extends RuntimeException {}

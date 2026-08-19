<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when a request never reaches Google.
 */
class GmailConnectionException extends RuntimeException {}

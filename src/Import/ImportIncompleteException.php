<?php

namespace App\Import;

/**
 * Every page was fetched, but the result cannot be trusted as a complete picture of the API
 * (total changed while paginating, or fewer submissions than announced).
 */
final class ImportIncompleteException extends \RuntimeException
{
}

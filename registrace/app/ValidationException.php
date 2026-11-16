<?php
declare(strict_types=1);

namespace Bkl;

/** Chyba vstupu od uživatele – zpráva se smí zobrazit. */
final class ValidationException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(public readonly array $errors, string $message = 'Formulář obsahuje chyby.')
    {
        parent::__construct($message);
    }
}

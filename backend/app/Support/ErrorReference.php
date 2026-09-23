<?php

namespace App\Support;

/**
 * The id of the error_logs row written for the failure being rendered.
 *
 * The 500 page tells the person their error has already reached us. That is a
 * promise, so the page has to be able to check it rather than assert it: the
 * reporter in bootstrap/app.php sets this only after the row is committed, and
 * the view says nothing about reporting unless it finds a reference here. If
 * the database is the thing that broke, the report failed too, and the page
 * falls back to asking the person to contact support themselves.
 *
 * Static because it is passing one integer between the report hook and the view
 * within one dying request. Reset between requests by the process itself, and
 * explicitly by forget() so a test can assert both branches.
 */
class ErrorReference
{
    private static ?int $id = null;

    public static function set(int $id): void
    {
        self::$id = $id;
    }

    public static function get(): ?int
    {
        return self::$id;
    }

    public static function forget(): void
    {
        self::$id = null;
    }
}

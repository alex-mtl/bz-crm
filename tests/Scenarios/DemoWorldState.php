<?php

namespace Tests\Scenarios;

/**
 * Shared across all scenario test classes of one run: the demo world is seeded once per process
 * (a static property of the trait would be per class).
 */
final class DemoWorldState
{
    public static bool $seeded = false;
}

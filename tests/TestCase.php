<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case for plain unit tests.
 *
 * Deliberately identical in every package: all packages share the `Tests\`
 * namespace, so the aggregated monorepo run loads only one copy. Tests that
 * need a bootstrapped Application extend `EzPhp\Testing\ApplicationTestCase`
 * (or the package's `Tests\ApplicationTestCase`) explicitly instead.
 *
 * @package Tests
 */
abstract class TestCase extends BaseTestCase
{
}

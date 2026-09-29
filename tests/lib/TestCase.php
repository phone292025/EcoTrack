<?php
/**
 * Minimal assertion base class. Deliberately tiny: the project runs on a
 * stock XAMPP install with no Composer, so the test suite cannot assume
 * PHPUnit is available.
 */

final class AssertionFailed extends Exception
{
}

abstract class TestCase
{
    /** Runs before every test method. */
    protected function setUp(): void
    {
    }

    /** Runs after every test method, even when it fails. */
    protected function tearDown(): void
    {
    }

    public function runSetUp(): void
    {
        $this->setUp();
    }

    public function runTearDown(): void
    {
        $this->tearDown();
    }

    protected function fail(string $message): never
    {
        throw new AssertionFailed($message);
    }

    protected function assertTrue(mixed $actual, string $message = ''): void
    {
        if ($actual !== true) {
            $this->fail($message ?: 'Expected true, got ' . var_export($actual, true));
        }
    }

    protected function assertFalse(mixed $actual, string $message = ''): void
    {
        if ($actual !== false) {
            $this->fail($message ?: 'Expected false, got ' . var_export($actual, true));
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            $this->fail(($message !== '' ? $message . ': ' : '')
                . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            $this->fail($message ?: 'Expected to find "' . $needle . '" in: ' . substr($haystack, 0, 400));
        }
    }

    protected function assertNotContains(string $needle, string $haystack, string $message = ''): void
    {
        if (str_contains($haystack, $needle)) {
            $this->fail($message ?: 'Did not expect to find "' . $needle . '"');
        }
    }

    protected function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        if (!preg_match($pattern, $subject)) {
            $this->fail($message ?: 'Expected ' . $pattern . ' to match: ' . substr($subject, 0, 400));
        }
    }
}

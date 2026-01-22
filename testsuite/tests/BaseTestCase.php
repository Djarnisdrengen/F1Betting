<?php
use PHPUnit\Framework\TestCase;

abstract class BaseTestCase extends TestCase {
    protected $client;

    protected function setUp(): void {
        if (!isset($GLOBALS['authClient'])) {
            throw new Exception("AuthClient not initialized. Please run via run-tests.php");
        }
        $this->client = $GLOBALS['authClient'];
    }
}
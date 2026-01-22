<?php
require_once __DIR__ . '/BaseTestCase.php';
use PHPUnit\Framework\TestCase;

class GatedAreaTest extends TestCase
{
    public function testAuthenticatedAccess()
    {
        $client = $GLOBALS['authClient'];
        list($status, $body) = $client->get('index.php');

        $this->assertEquals(200, $status);
        $this->assertStringContainsString('Log ud', $body);
        $this->assertStringContainsString('Placer Bet', $body);

    }
}
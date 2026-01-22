<?php
require_once __DIR__ . '/BaseTestCase.php';
use PHPUnit\Framework\TestCase;

class PublicNavigationTest extends BaseTestCase
{
    /**
     * Test that the homepage returns a 200 OK status.
     */
    public function testHomePageLoads()
    {
        // Safety check: ensure the authenticated client was created
        if (!$this->client) {
            $this->fail("Authenticated client was not initialized.");
        }

        // Perform the GET request
        list($status, $body) = $this->client->get('index.php');

        // Assertions
        $this->assertEquals(200, $status, "Homepage failed to load with status 200.");
        $this->assertStringContainsString('<body', $body, "Homepage body content is missing.");
    }

    /**
     * Test that the contact page is accessible.
     */
    public function testContactPageLoads()
    {
        list($status, $body) = $this->client->get('kontakt.php');
        $this->assertEquals(200, $status, "Contact page is missing or broken.");
    }
}
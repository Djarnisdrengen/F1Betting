<?php
require_once __DIR__ . '/BaseTestCase.php';
use PHPUnit\Framework\TestCase;

class GatedAreaTest extends TestCase
{
    private $client;

    protected function setUp(): void{
        $this->client = $GLOBALS['authClient'];    
    }

    public function testAuthenticatedAccess()
    {       
        list($status, $body) = $this->client->get('index.php');

        $this->assertEquals(200, $status);
        $this->assertStringContainsString('Log ud', $body);      

    }

    public function testPlaceBet()
    {       
       
        list($status, $body) = $this->client->get('index.php');

        //Validate bet button is available
        $this->assertEquals(200, $status);
        $this->assertStringContainsString('Placer Bet', $body); 
        
        //Validate bet page is accessible
        list($status, $body) = $this->client->get('bet.php');
        $this->assertEquals(200, $status);
        $this->assertStringContainsString('Placer Bet', $body); 
        $this->assertStringContainsString('Annuller', $body); 



    }

}
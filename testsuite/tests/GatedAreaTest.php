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
        
        //Validate if index.php have either "Placer Bet" or "Rediger" links
        $betLink = null;
        if (strpos($body, 'Placer Bet') !== false) {
            $betLink = 'Placer Bet';
        } elseif (strpos($body, 'Rediger') !== false) {
            $betLink = 'Rediger';
        }   
        $this->assertTrue($betLink === 'Placer Bet' || $betLink === 'Rediger', "Button text was expected to be 'Placer Bet' or 'Rediger', but got: $betLink");
        
/*
        $dom = new \DOMDocument();
        @$dom->loadHTML($body);
        $xpath = new \DOMXPath($dom);

        // IMPROVED XPATH: Looks for <a> tags containing EITHER "placer bet" OR "rediger bet"
        // Using 'or' inside the XPath predicate []
        $linkQuery = "//a[
            contains(translate(., 'BETRIDGEPLAC', 'betridgeplac'), 'placer bet') or 
            contains(translate(., 'BETRIDGEPLAC', 'betridgeplac'), 'rediger bet')
        ]";
        
        $linkNode = $xpath->query($linkQuery)->item(0);

        // Check if we found ANY button
        if (!$linkNode) {
            $this->fail("Could not find a 'Placer Bet' or 'Rediger Bet' link on index.php");
        }

        $betUrl = $linkNode->getAttribute('href');
            
        // 2. Navigate to the betting page (the URL will be the same regardless of button text)
        list($status, $betPageHtml) = $this->client->get($betUrl);
        $this->assertEquals(200, $status);

        // 3. Parse drivers from dropdowns
        @$dom->loadHTML($betPageHtml);
        $xpath = new \DOMXPath($dom);
        
        // Scrape drivers
        $optionNodes = $xpath->query('//select[@name="p1"]/option[@value!=""]');
        $driverIds = [];
        foreach ($optionNodes as $node) {
            $driverIds[] = $node->getAttribute('value');
        }

        if (count($driverIds) < 3) {
            $this->markTestSkipped("Need at least 3 drivers in dropdown.");
        }

        shuffle($driverIds);

        // 4. Submit the bet
        list($postStatus, $postBody) = $this->client->post($betUrl, [
            'p1' => $driverIds[0],
            'p2' => $driverIds[1],
            'p3' => $driverIds[2],
            'csrf_token' => 'test_token' // Ensure this is handled by your auth system
        ]);

        // 5. Assert success (Redirect)
        $this->assertEquals(302, $postStatus);
*/
    }

}
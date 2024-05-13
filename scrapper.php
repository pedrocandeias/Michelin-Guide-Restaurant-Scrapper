<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);
function fetchUrl($url)
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36');
    $output = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($httpcode >= 400) {
        curl_close($ch);
        return false; // Handle HTTP error codes as needed
    }
    if (curl_errno($ch)) {
        echo 'cURL error: ' . curl_error($ch);
        curl_close($ch);
        return false;
    }
    curl_close($ch);
    return $output;
}

function findNumberOfPages($html)
{ 
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $paginationNodes = $xpath->query("//div[contains(@class, 'js-restaurant__bottom-pagination')]//a[contains(@class, 'btn-outline-secondary')]");

    if ($paginationNodes->length == 0) { 
        return 1; // No pagination found, assume only one page
    }

    // Ensure there are enough nodes to use the second to last index safely
    if ($paginationNodes->length > 1) {
        $lastPageNode = $paginationNodes->item($paginationNodes->length - 2); // Second to last link which should be the last numbered page
        $href = $lastPageNode->getAttribute('href');
        preg_match('/page\/(\d+)$/', $href, $matches);
        return $matches[1] ?? 1;
    } else {
        // Safe fallback if only one page node exists
        $lastPageNode = $paginationNodes->item(0); // Use the first (and only) item
        $href = $lastPageNode->getAttribute('href');
        preg_match('/page\/(\d+)$/', $href, $matches);
        return $matches[1] ?? 1;
    }
}

function parseRestaurants($html)
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $restaurants = $xpath->query("//div[contains(@class, 'js-restaurant__list_item')]");

    $data = [];
    if ($restaurants->length == 0) {
        return false; // No restaurants found
    }
    foreach ($restaurants as $restaurant) {
        $nameNode = $xpath->query(".//h3[contains(@class, 'card__menu-content--title')]/a", $restaurant);
        $name = $nameNode->length > 0 ? trim($nameNode->item(0)->nodeValue) : "Name not found";
        $linkNode = $xpath->query(".//h3[contains(@class, 'card__menu-content--title')]/a", $restaurant);
        $link = $linkNode->length > 0 ? 'https://guide.michelin.com' . trim($linkNode->item(0)->getAttribute('href')) : "#";
        $details = fetchRestaurantDetails($link);

        $data[] = [
            'name' => $name,
            'link' => $link,
            'details' => $details
        ];
    }
    // var_dump($data);
    return $data;
}


function scrapeAllPages($baseUrl)
{
    $firstPageHtml = fetchUrl($baseUrl);
    $numPages = findNumberOfPages($firstPageHtml);
    $allData = [];

    for ($i = 1; $i <= $numPages; $i++) {
        $pageUrl = $baseUrl . "/page/" . $i;
        $pageHtml = fetchUrl($pageUrl);
        $pageData = parseRestaurants($pageHtml);
        $allData = array_merge($allData, $pageData);
    }
    return $allData;
}

function fetchRestaurantDetails($url)
{
    $html = fetchUrl($url);

    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    // Fetch description
    $descriptionNode = $xpath->query("//div[@class='restaurant-details__description--text ']");
    $description = $descriptionNode->length > 0 ? trim($descriptionNode->item(0)->nodeValue) : "No description available";

    // Fetch address
    $addressNode = $xpath->query("//li[@class='restaurant-details__heading--address']");
    $address = $addressNode->length > 0 ? trim($addressNode->item(0)->nodeValue) : "No address available";

    // Fetch timetable
    $timetableNode = $xpath->query("//div[@class='open__time']");
    $timetable = $timetableNode->length > 0 ? trim($timetableNode->item(0)->nodeValue) : "No timetable available";

    // Fetch GPS coordinates
    $gpsLinkNode = $xpath->query("//div[@class='restaurant-details__button--mobile']/a[contains(@href, 'lat')]");
    $gpsLink = $gpsLinkNode->length > 0 ? $gpsLinkNode->item(0)->getAttribute('href') : "";
    $lat = $lon = '';
    if (!empty($gpsLink)) {
        parse_str(parse_url($gpsLink, PHP_URL_QUERY), $params);
        $lat = $params['lat'] ?? 'No latitude';
        $lon = $params['lon'] ?? 'No longitude';
    }

    // Fetch images from each 'masthead__gallery-image-item' within 'masthead__gallery-image'
    $imageNodes = $xpath->query("//div[contains(@class, 'masthead__gallery-image-item')]/img");
    //print_r($imageNodes[0]->attributes[0]->value);
    $images = [];
    foreach ($imageNodes as $node) {
        // The image URL might be in a data attribute or a style, depending on how it's structured
        $style = $node->attributes[0]->value;
           $images[] = $style; // Directly take the URL if not in a style format
       
    }
    
    //print_r($images[0]);
    $restimage1 = isset($images[0]) ? $images[0] : "No image available";
    $restimage2 = isset($images[1]) ? $images[1] : "No image available";
    $restimage3 = isset($images[2]) ? $images[2] : "No image available";    

    return [
        'description' => $description,
        'address' => $address,
        'timetable' => $timetable,
        'latitude' => $lat,
        'longitude' => $lon,
        'image1' => $restimage1,
        'image2' => $restimage2,
        'image3' => $restimage3,
    ];
}

function updateProgress($current, $total) 
{
    if ($total > 0) {
        $_SESSION['progress'] = intval(($current / $total) * 100);
    } else {
        $_SESSION['progress'] = 0;
    }
}

function getProgress() 
{
    return $_SESSION['progress'] ?? 0;
}

function displayXML($restaurants, $returnAsString = false)
{
    //header('Content-type: text/xml');
    $xml = new SimpleXMLElement('<restaurants/>');
    foreach ($restaurants as $restaurant) {
        $xmlRestaurant = $xml->addChild('restaurant');
        $xmlRestaurant->addChild('name', htmlspecialchars($restaurant['name']));
        $xmlRestaurant->addChild('link', htmlspecialchars($restaurant['link']));
        $xmlRestaurant->addChild('description', htmlspecialchars($restaurant['details']['description']));
        $xmlRestaurant->addChild('address', htmlspecialchars($restaurant['details']['address']));
        $xmlRestaurant->addChild('timetable', htmlspecialchars($restaurant['details']['timetable']));
        $xmlRestaurant->addChild('latitude', htmlspecialchars($restaurant['details']['latitude']));
        $xmlRestaurant->addChild('longitude', htmlspecialchars($restaurant['details']['longitude']));
        $xmlRestaurant->addChild('image1', htmlspecialchars($restaurant['details']['image1']));
        $xmlRestaurant->addChild('image2', htmlspecialchars($restaurant['details']['image2']));
        $xmlRestaurant->addChild('image3', htmlspecialchars($restaurant['details']['image3']));

    }  
    if ($returnAsString) {
        return $xml->asXML(); // Return XML string
    } else {
        header('Content-type: text/xml');
        echo $xml->asXML(); // Output directly
    }
}

// $url = "https://guide.michelin.com/tw/en/selection/taiwan/restaurants/bib-gourmand/affordable";

if ($_SERVER["REQUEST_METHOD"] == "POST" && !empty($_POST['url'])) {
    $url = filter_var($_POST['url'], FILTER_SANITIZE_URL);
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        die('Invalid URL');
    }

    $initialHtml = fetchUrl($url);
    $totalPages = findNumberOfPages($initialHtml);
    $allRestaurants = [];

    for ($i = 1; $i <= $totalPages; $i++) {
        updateProgress($i, $totalPages); // Update progress.
        $pageUrl = $url . "/page/" . $i;
        $pageHtml = fetchUrl($pageUrl); // Assume this includes throttling.
        $restaurants = parseRestaurants($pageHtml);
        if ($restaurants) {
            $allRestaurants = array_merge($allRestaurants, $restaurants);
        }
        sleep(1); // Throttling by slowing down the loop.
    } 
    if (!$allRestaurants) {
        echo "No restaurants found";
    } else {
        $xmlContent = displayXML($allRestaurants, true); // Generate XML and return as string
        $filename = "restaurants_" . time() . ".xml"; // Unique filename for each session
        $filePath = "/tmp/" . $filename; // Make sure the directory exists and is writable
        file_put_contents($filePath, $xmlContent); // Save to file
        $_SESSION['downloadLink'] = $filePath;
    }

} elseif (isset($_GET['progress'])) {
    echo getProgress();
    exit;
}
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Michelin Guide Scraper</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <h1>Michelin Guide Restaurant Scraper</h1>
    
    
    <form method="post">
        <label for="url">Enter Michelin Guide URL:</label> <?php

?>

        <input type="text" id="url" name="url" required>
        <button type="submit">Scrape</button>
    </form>
    <script>
document.addEventListener("DOMContentLoaded", function() {
   
    const form = document.querySelector("form");
    form.addEventListener("submit", function(event) {
        document.getElementById("result").innerHTML = " ";
        event.preventDefault();
        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData
        }).then(response => response.text())
          .then(data => {
            console.log(data);
              console.log("Scraping complete");
              console.log(data); // Log the response data to console
                //document.getElementById("result").innerHTML = data; // Set innerHTML to render the link
                document.getElementById("result").innerHTML = "Scraping complete. <a href='download.php'>Download XML</a>";
          });

        // Function to update progress
        function updateProgress() {
            document.getElementById('progress').textContent = "Processing..."; // Initial message
            fetch('?progress')
                .then(response => response.text())
                .then(progress => {
                    document.getElementById('progress').textContent = "Progress: " + progress + "%";
                    if (parseInt(progress) < 100) {
                        setTimeout(updateProgress, 1000);
                    }
                });
        }

        updateProgress();
    });
});
</script>
<div id="progress"></div>
<div id="result"></div>

</body>
</html>
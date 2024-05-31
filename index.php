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

    // Fetch price
    $priceNode = $xpath->query("//li[@class='restaurant-details__heading-price']");
    $price = $priceNode->length > 0 ? trim($priceNode->item(0)->nodeValue) : "No price available";

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
    $images = [];
    foreach ($imageNodes as $node) {
        $style = $node->attributes[0]->value;
        $images[] = $style; // Directly take the URL if not in a style format
    }

    $restimage1 = isset($images[0]) ? $images[0] : "No image available";
    $restimage2 = isset($images[1]) ? $images[1] : "No image available";
    $restimage3 = isset($images[2]) ? $images[2] : "No image available";

    return [
        'description' => $description,
        'address' => $address,
        'timetable' => $timetable,
        'price' => $price,
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
function generateKML($restaurants)
{
    $kml = new SimpleXMLElement('<kml/>');
    $kml->addAttribute('xmlns', 'http://www.opengis.net/kml/2.2');
    $document = $kml->addChild('Document');

    // Define the custom icon style
    $style = $document->addChild('Style');
    $style->addAttribute('id', 'customIconStyle');
    $iconStyle = $style->addChild('IconStyle');
    $icon = $iconStyle->addChild('Icon');
    $icon->addChild('href', 'http://maps.google.com/mapfiles/kml/paddle/red-circle.png'); // URL to your custom icon

    foreach ($restaurants as $restaurant) {
        $placemark = $document->addChild('Placemark');
        $placemark->addChild('name', htmlspecialchars($restaurant['name']));
        
        // Apply the custom icon style to the placemark
        $placemark->addChild('styleUrl', '#customIconStyle');
        
        // Creating a rich description with images, address, and price
        $descriptionContent = '<![CDATA[';
        $descriptionContent .= '<p><bold>Address:</bold> ' . htmlspecialchars($restaurant['details']['address']) . '</p>';
        $descriptionContent .= '<p></p>';
        $descriptionContent .= '<p>' . htmlspecialchars($restaurant['details']['description']) . '<br></p>';
        $descriptionContent .= '<p><strong>Timetable:</strong> ' . htmlspecialchars($restaurant['details']['timetable']) . '</p>';
        if (isset($restaurant['details']['price']) && $restaurant['details']['price'] != "No price available") {
            $descriptionContent .= '<p><strong>Price:</strong> ' . htmlspecialchars($restaurant['details']['price']) . '</p>';
        }
        
        // Add images if available
        if ($restaurant['details']['image1'] != "No image available") {
            $descriptionContent .= '<p>' . htmlspecialchars($restaurant['details']['image1']) . '</p>';
        }
        if ($restaurant['details']['image1'] != "No image available") {
            $descriptionContent .= '<description><![CDATA[<img src="' . htmlspecialchars($restaurant['details']['image1']) . '" height="200" width="auto" />]]>' . '</description>';
        }
        if ($restaurant['details']['image2'] != "No image available") {
            $descriptionContent .= '<p>' . htmlspecialchars($restaurant['details']['image2']) . '</p>';
        }
        if ($restaurant['details']['image3'] != "No image available") {
            $descriptionContent .= '<p>' . htmlspecialchars($restaurant['details']['image3']) . '</p>';
        }

        
        $placemark->addChild('description', $descriptionContent);
        $point = $placemark->addChild('Point');
        $coordinates = htmlspecialchars($restaurant['details']['longitude'] . ',' . $restaurant['details']['latitude']);
        $point->addChild('coordinates', $coordinates);
    }

    return $kml->asXML();
}

function generateKMZ($kmlContent, $filename)
{
    $zip = new ZipArchive();
    $kmzFile = '/tmp/' . $filename;

    if ($zip->open($kmzFile, ZipArchive::CREATE) === TRUE) {
        $zip->addFromString('doc.kml', $kmlContent);
        $zip->close();
        return $kmzFile;
    } else {
        return false;
    }
}

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
        $filename = "restaurants_" . time();
        $xmlFilePath = "/tmp/" . $filename . ".xml"; // Unique filename for each session
        file_put_contents($xmlFilePath, $xmlContent); // Save to file

        // Generate KML
        $kmlContent = generateKML($allRestaurants);
        $kmlFilePath = "/tmp/" . $filename . ".kml";
        file_put_contents($kmlFilePath, $kmlContent);

        // Generate KMZ
        $kmzFilePath = generateKMZ($kmlContent, $filename . ".kmz");

        $_SESSION['xmlDownloadLink'] = $xmlFilePath;
        $_SESSION['kmlDownloadLink'] = $kmlFilePath;
        $_SESSION['kmzDownloadLink'] = $kmzFilePath;
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
        <label for="url">Enter Michelin Guide URL:</label>
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
              document.getElementById("result").innerHTML = "Scraping complete. <a href='download.php?type=xml'>Download XML</a> | <a href='download.php?type=kml'>Download KML</a> | <a href='download.php?type=kmz'>Download KMZ</a>";
          });

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
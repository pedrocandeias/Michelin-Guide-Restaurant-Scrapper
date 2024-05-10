<?php

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36');
    $output = curl_exec($ch);
    if (curl_errno($ch)) {
        die('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);
    return $output;
}

function parseRestaurants($html) {
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

function fetchRestaurantDetails($url) {
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
   $restimage = $images[0];

    return [
        'description' => $description,
        'address' => $address,
        'timetable' => $timetable,
        'latitude' => $lat,
        'longitude' => $lon,
        'images' => $restimage,
    ];
}

function displayXML($restaurants) {
    header('Content-type: text/xml');
    $xml = new SimpleXMLElement('<restaurants/>');
    foreach ($restaurants as $restaurant) {
        $xmlRestaurant = $xml->addChild('restaurant');
        $xmlRestaurant->addChild('images', htmlspecialchars($restaurant['details']['images']));
        $xmlRestaurant->addChild('name', htmlspecialchars($restaurant['name']));
        $xmlRestaurant->addChild('link', htmlspecialchars($restaurant['link']));
        $xmlRestaurant->addChild('description', htmlspecialchars($restaurant['details']['description']));
        $xmlRestaurant->addChild('address', htmlspecialchars($restaurant['details']['address']));
        $xmlRestaurant->addChild('timetable', htmlspecialchars($restaurant['details']['timetable']));
        $xmlRestaurant->addChild('latitude', htmlspecialchars($restaurant['details']['latitude']));
        $xmlRestaurant->addChild('longitude', htmlspecialchars($restaurant['details']['longitude']));
        $xmlRestaurant->addChild('images', htmlspecialchars($restaurant['details']['images']));

    }

    echo $xml->asXML();
}

// Main
$url = "https://guide.michelin.com/tw/en/selection/taiwan/restaurants/bib-gourmand/affordable";
$html = fetchUrl($url);
$restaurants = parseRestaurants($html);
if (!$restaurants) {
    echo "No restaurants found";
} else {
    displayXML($restaurants);
}
?>

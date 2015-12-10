<?php
// use Composer autoloader
require 'vendor/autoload.php';
require 'config.php';

// load classes
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Silex\Application;

// initialize Silex application
$app = new Application();

// load configuration from file
$app->config = $config;

// register Twig template provider
$app->register(new Silex\Provider\TwigServiceProvider(), array(
  'twig.path' => __DIR__.'/views',
));

// register URL generator
$app->register(new Silex\Provider\UrlGeneratorServiceProvider());

// configure MongoDB client
$dbn = substr(parse_url($app->config['db_uri'], PHP_URL_PATH), 1);
$mongo = new MongoClient($app->config['db_uri'], array("connectTimeoutMS" => 30000));
$db = $mongo->selectDb($dbn);

// if BlueMix VCAP_SERVICES environment available
// overwrite with credentials from BlueMix
if ($services = getenv("VCAP_SERVICES")) {
  $services_json = json_decode($services, true);
  $app->config['weather_uri'] = $services_json["weatherinsights"][0]["credentials"]["url"];
} 

// index page handlers
$app->get('/', function () use ($app) {
  return $app->redirect($app["url_generator"]->generate('index'));
});

$app->get('/index', function () use ($app, $db) {
  // get list of locations from database
  // for each location, get current weather from Weather service
  $collection = $db->locations;  
  $locations = iterator_to_array($collection->find());
  foreach ($locations as &$location) {
    $uri = $app->config['weather_uri'] . '/api/weather/v2/observations/current?units=m&language=en-US&geocode=' . urlencode(sprintf('%f,%f', $location['lat'], $location['lng']));
    $json = file_get_contents($uri);
    if ($json === FALSE) {
     throw new Exception("Could not connect to Weather API.");  
    }
    $location['weather'] = json_decode($json, true);
  }
  return $app['twig']->render('index.twig', array('locations' => $locations));
})
->bind('index');

// search form
$app->get('/search', function () use ($app) {
  return $app['twig']->render('search.twig', array());
})
->bind('search');

// search processor
$app->post('/search', function (Request $request) use ($app) {
  // search for location string against Geonames database
  // for each result, store location name, country and Geonames ID
  $query = urlencode(strip_tags($request->get('query')));
  $sxml = simplexml_load_file('http://api.geonames.org/search?q=' . $query . '&maxRows=20&username=' . $app->config['geonames_uid']);
  if ($sxml === FALSE) {
    throw new Exception("Could not connect to Geonames API.");  
  }
  $data = array();
  foreach ($sxml->geoname as $g) {
    $data[] = array(
      'gid' => (int)$g->geonameId,
      'location' => (string)$g->name,
      'country' => (string)$g->countryName,
    );
  }
  return $app['twig']->render('search.twig', array('data' => $data, 'query' => urldecode($query)));
});

// handler to add location to database
$app->get('/add/{gid}', function ($gid) use ($app, $db) {
  // use Geonames ID to get location latitude/longitude from Geonames service
  // connect to MongoDB and save in database
  $collection = $db->locations;
  $query = (int)urlencode(strip_tags($gid));
  $sxml = simplexml_load_file('http://api.geonames.org/get?geonameId=' . $query . '&username=' . $app->config['geonames_uid']);
  if ($sxml === FALSE) {
    throw new Exception("Could not connect to Geonames API.");  
  }
  $location = new stdClass;
  $location->gid = trim(strip_tags($sxml->geonameId));
  $location->location = trim(strip_tags($sxml->name));
  $location->country = trim(strip_tags($sxml->countryCode));
  $location->lat = trim(strip_tags($sxml->lat));
  $location->lng = trim(strip_tags($sxml->lng));
  $cursor = iterator_to_array($collection->find());
  // disallow if 5 locations already exist
  if (count($cursor) >= 5) {
    throw new Exception("A maximum of 5 locations are supported. Please remove a location and try again.");
  }
  // disallow if selected location already exists
  foreach ($cursor as $key => $value) {
    if ($value['gid'] == $location->gid) {
      throw new Exception("The selected location already exists in the location list.");  
    }
  }
  $collection->save($location);
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('add');

// handler to remove location from database
$app->get('/delete/{id}', function ($id) use ($app, $db) {
  $collection = $db->locations;
  $collection->remove(array('_id' => new MongoId($id)));
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('delete');

// handler to display 7-day forecast for selected location
$app->get('/forecast/{id}', function ($id) use ($app, $db) {
  // look up location record in database
  // connect and get forecast from Weather service
  $collection = $db->locations;
  $location = (array)$collection->findOne(array('_id' => new MongoId($id)));
  $uri = $app->config['weather_uri'] . '/api/weather/v2/forecast/daily/10day?units=m&language=en-US&geocode=' . urlencode(sprintf('%f,%f', $location['lat'], $location['lng']));
  $json = file_get_contents($uri);
  if ($json === FALSE) {
    throw new Exception("Could not connect to Weather API.");  
  }
  $location['weather'] = json_decode($json, true);
  return $app['twig']->render('forecast.twig', array('data' => $location));
})
->bind('forecast');

// error page handler
$app->error(function (\Exception $e, $code) use ($app) {
  return $app['twig']->render('error.twig', array('error' => $e->getMessage()));
});

$app->run();
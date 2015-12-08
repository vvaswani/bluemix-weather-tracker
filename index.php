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

// index page handlers
$app->get('/', function () use ($app) {
  return $app->redirect($app["url_generator"]->generate('index'));
});

$app->get('/index', function () use ($app, $db) {
  // get list of cities from database
  // for each city, get current weather from Weather service
  $collection = $db->cities;  
  $cities = iterator_to_array($collection->find());
  foreach ($cities as &$city) {
    $uri = $app->config['weather_uri'] . '/api/weather/v2/observations/current?units=m&language=en-US&geocode=' . urlencode(sprintf('%f,%f', $city['lat'], $city['lng']));
    $json = file_get_contents($uri);
    if ($json === FALSE) {
     throw new Exception("Could not connect to Weather API.");  
    }
    $city['weather'] = json_decode($json, true);
  }
  return $app['twig']->render('index.twig', array('cities' => $cities));
})
->bind('index');

// search form
$app->get('/search', function () use ($app) {
  return $app['twig']->render('search.twig', array());
})
->bind('search');

// search processor
$app->post('/search', function (Request $request) use ($app) {
  // search for city string against Geonames database
  // for each result, store city name, country and Geonames ID
  $query = urlencode(strip_tags($request->get('query')));
  $sxml = simplexml_load_file('http://api.geonames.org/search?q=' . $query . '&maxRows=20&username=' . $app->config['geonames_uid']);
  if ($sxml === FALSE) {
    throw new Exception("Could not connect to Geonames API.");  
  }
  $data = array();
  foreach ($sxml->geoname as $g) {
    $data[] = array(
      'gid' => (int)$g->geonameId,
      'city' => (string)$g->name,
      'country' => (string)$g->countryName,
    );
  }
  return $app['twig']->render('search.twig', array('data' => $data, 'query' => $query));
});

// handler to add city to database
$app->get('/add/{gid}', function ($gid) use ($app, $db) {
  // use Geonames ID to get city latitude/longitude from Geonames service
  // connect to MongoDB and save in database
  $collection = $db->cities;
  $query = (int)urlencode(strip_tags($gid));
  $sxml = simplexml_load_file('http://api.geonames.org/get?geonameId=' . $query . '&username=' . $app->config['geonames_uid']);
  if ($sxml === FALSE) {
    throw new Exception("Could not connect to Geonames API.");  
  }
  $city = new stdClass;
  $city->gid = trim(strip_tags($sxml->geonameId));
  $city->city = trim(strip_tags($sxml->name));
  $city->country = trim(strip_tags($sxml->countryCode));
  $city->lat = trim(strip_tags($sxml->lat));
  $city->lng = trim(strip_tags($sxml->lng));
  $cursor = iterator_to_array($collection->find());
  // disallow if 5 cities already exist
  if (count($cursor) >= 5) {
    throw new Exception("A maximum of 5 cities are supported. Please remove a city and try again.");
  }
  // disallow if selected city already exists
  foreach ($cursor as $key => $value) {
    if ($value['gid'] == $city->gid) {
      throw new Exception("The selected city already exists in the city list.");  
    }
  }
  $collection->save($city);
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('add');

// handler to remove city from database
$app->get('/delete/{id}', function ($id) use ($app, $db) {
  $collection = $db->cities;
  $collection->remove(array('_id' => new MongoId($id)));
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('delete');

// handler to display 7-day forecast for selected city
$app->get('/forecast/{id}', function ($id) use ($app, $db) {
  // look up city record in database
  // connect and get forecast from Weather service
  $collection = $db->cities;
  $city = (array)$collection->findOne(array('_id' => new MongoId($id)));
  $uri = $app->config['weather_uri'] . '/api/weather/v2/forecast/daily/10day?units=m&language=en-US&geocode=' . urlencode(sprintf('%f,%f', $city['lat'], $city['lng']));
  $json = file_get_contents($uri);
  if ($json === FALSE) {
    throw new Exception("Could not connect to Weather API.");  
  }
  $city['weather'] = json_decode($json, true);
  return $app['twig']->render('forecast.twig', array('data' => $city));
})
->bind('forecast');

// error page handler
$app->error(function (\Exception $e, $code) use ($app) {
  return $app['twig']->render('error.twig', array('error' => $e->getMessage()));
});

$app->run();
<?php
// use Composer autoloader
require 'vendor/autoload.php';
require 'config.php';

// load classes
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
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
  $collection = $db->cities;  
  $cities = $collection->find();
  return $app['twig']->render('index.twig', array('cities' => $cities));
})
->bind('index');

$app->get('/search', function () use ($app) {
  return $app['twig']->render('search.twig', array());
})
->bind('search');

$app->post('/search', function (Request $request) use ($app) {
  $query = urlencode(strip_tags($request->get('query')));
  $sxml = simplexml_load_file('http://api.geonames.org/search?q=' . $query . '&maxRows=20&username=' . $app->config['geonames_uid']);
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

$app->get('/add/{gid}', function ($gid) use ($app, $db) {
  $collection = $db->cities;
  $query = (int)urlencode(strip_tags($gid));
  $sxml = simplexml_load_file('http://api.geonames.org/get?geonameId=' . $query . '&username=' . $app->config['geonames_uid']);
  $city = new stdClass;
  $city->gid = trim(strip_tags($sxml->geonameId));
  $city->city = trim(strip_tags($sxml->name));
  $city->country = trim(strip_tags($sxml->countryCode));
  $city->lat = trim(strip_tags($sxml->lat));
  $city->lng = trim(strip_tags($sxml->lng));
  $cursor = $collection->find(array('gid' => $city->gid));
  if ($cursor->count() == 0) {
    $collection->save($city);
  }
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('add');


$app->get('/delete/{id}', function ($id) use ($app, $db) {
  $collection = $db->cities;
  $collection->remove(array('_id' => new MongoId($id)));
  return $app->redirect($app["url_generator"]->generate('index'));
})
->bind('delete');


// error page handler
$app->error(function (\Exception $e, $code) use ($app) {
  return $app['twig']->render('error.twig', array('error' => $e->getMessage()));
});

$app->run();
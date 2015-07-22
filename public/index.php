<?php

header('Content-Type: text/plain');

define ('ROOT', dirname(dirname(__FILE__)));

require_once ROOT.'/vendor/autoload.php';

//use Symfony\Component\HttpFoundation\Request;
//use Symfony\Component\HttpFoundation\Response;
//use Symfony\Component\HttpFoundation\JsonResponse;
//use Symfony\Component\HttpFoundation\ParameterBag;

//use Silex\Application\SwiftmailerTrait;
//use Silex\Provider\SwiftmailerServiceProvider;


$Mockapi = (object) [
    'endpoint' => [],
    'constrains' => []
];

$_SERVER['REQUEST_URI'] = explode('/', trim(array_shift(explode('?', $_SERVER['REQUEST_URI'])), '/'));

/*
    Extract API endpoint subdirectory

    When API runs within subdirectory it inerits some parts of the system
    directory structure. Some because dirs can be skippec using .htaccess.
*/
foreach (explode('/', trim(dirname(__FILE__), '/')) as $s) {
    if ($s === $_SERVER['REQUEST_URI'][0]) {
        array_shift($_SERVER['REQUEST_URI']);
        $Mockapi->endpoint[] = $s;
    }
}

$Mockapi->endpoint = 'http://'.$_SERVER['HTTP_HOST'].'/'.implode('/', $Mockapi->endpoint);

// Set the generic factory for resource services

$services = new \Mockapi\ResourceService\ResourceServiceFactory([
    // Default Resource Service factory
    [
        // Default Resource Service class name
        '\Mockapi\ResourceService\ResourceService',
        // Default Resource Service class constructor arguments
        [
            'provider' => [
                '\Mockapi\ResourceProvider\ResourceProviderFactory',
                [
                    [
                        '\Mockapi\ResourceProvider\FlatFileImplementation',
                        [
                            'root' => ROOT.'/storage',
                            'serializer' => new \Mockapi\ResourceProvider\FlatFileImplementation\YamlSerializer
                        ]
                    ]
                ]
            ],
            'endpoint' => $Mockapi->endpoint
        ]
    ]
]);

/*
    Lets say The resource form MUST stay within 3 URI parts:

    /(resource.type)/(resource.id|resource.name|resource.index)/(resource.id.attribute)

    But whenever you ask api for https://api/users/martin_adamko/projects/882cdf3a-72d0-4d1f-b5e2-4cf90f5f4eab/symbols/23
    you actually mean:

    host: api
    resource:           # resource (object) you actualy ask for
        type: symbol    # resource is of type 'symbol'
        offset: 23      # lets assume UUID v4 is the id form
                        # any integer is just array offset
        attr: null
    constrains:
        -
            type: users
            slug: martin_adamko # URL sanitized version of `name`
        -
            type: projects
            id: 882cdf3a-72d0-4d1f-b5e2-4cf90f5f4eab

    In plain English "symbol object which is 23rd in order"

    WHERE

    this symbol belongs to project with id 882cdf3a-72d0-4d1f-b5e2-4cf90f5f4eab
    which also belongs to user with slug 'martin_adamko'

    Conditions to understand URI parts as constrain. Constrain MUST:

    1. be 2 consequential URL parts
    2. 1st must be plural
    3. 1st must be a valid resource service
*/

while (count($_SERVER['REQUEST_URI']) > 2
       && \Mockapi\Validate\Validate::isPlural($_SERVER['REQUEST_URI'][0], false)
       && $services->get($_SERVER['REQUEST_URI'][0])
       && \Mockapi\Validate\Validate::isPlural($_SERVER['REQUEST_URI'][2], false)
      ) {
    $Mockapi->constrains[array_shift($_SERVER['REQUEST_URI'])] = array_shift($_SERVER['REQUEST_URI']);
}

$_SERVER['REQUEST_URI'] = '/'.implode('/', array_filter($_SERVER['REQUEST_URI']));

// Ready to start Silex App

class APIApplication extends Silex\Application {
    use Silex\Application\SwiftmailerTrait;
}

$app = new APIApplication();
$app->register(new Silex\Provider\SwiftmailerServiceProvider());

$app['debug'] = true;
$app['swiftmailer.options'] = array(
    'host' => 'smtp.websupport.sk',
    'port' => '465',
    'username' => 'api@attitude.sk',
    'password' => 'Auc6OsIs.',
    'encryption' => 'ssl',
    'auth_mode' => null
);

$app->before(function (\Symfony\Component\HttpFoundation\Request $request) use ($app) {
    if (in_array($request->getMethod(), ['POST', 'PUT']) && 0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
        $data = json_decode($request->getContent());

        if ($data === null) {
            return $app->json((object) [
                'message' => 'Payload must be a valid JSON'
            ], 400);
        }

        $request->request->replace((array) $data);
    }
});

//print_r([$Mockapi, $_SERVER]);

$app['Mockapi'] = $Mockapi;

\Mockapi\Mockapi\Router::$app = $app;
\Mockapi\Mockapi\Router::$services = $services;

$app->get('/', function() use ($services, $app) {
    return $app->json($services->index());
});

$app->get('/{type}',              '\Mockapi\Mockapi\Router::get');
$app->get('/{type}/{id}',         '\Mockapi\Mockapi\Router::get');
$app->get('/{type}/{id}/{attr}',  '\Mockapi\Mockapi\Router::get');

$app->post('/{type}',             '\Mockapi\Mockapi\Router::post');
$app->post('/{type}/{id}',        '\Mockapi\Mockapi\Router::post');
$app->post('/{type}/{id}/{attr}', '\Mockapi\Mockapi\Router::post');

$app->run();

exit;

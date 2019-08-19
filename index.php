<?php
/*
 * TODO Description
 * (C) 2019, AII (Alexey Ilyin)
 */

// use Psr\Container\ContainerInterface;
use Ailixter\Puzzle\Application as PuzzleApplication;
use Ailixter\Puzzle\Dispatcher;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Sunrise\Http\ServerRequest\ServerRequestFactory;
use Sunrise\Http\Message\ResponseFactory;

require 'vendor/autoload.php';

/**
 * Test application class.
 * Note virtual properties and methods defined
 * for contained services.
 * @property ServerRequestInterface $request
 * @property ResponseFactory $responseFactory
 * @property Dispatcher $dispatcher
 * @method Application log($msg)
 */
class Application extends PuzzleApplication
{
    public function run()
    {
        if (\php_sapi_name() === 'cli') {
            $_SERVER['REQUEST_URI'] = '/resourse-path';
        }
        try {
            return $this->dispatcher->handle($this->request);
        } catch (\Throwable $throwable) {
            echo "*** Caught $throwable";
        }
    }
}

/**
 * Define global access to application instance.
 */
function app(): Application
{
    static $instance;
    return $instance ?? $instance = new Application();
}

app()
// defer request creation until the application run
->add('request', function () {
    static $var;
    return $var ?? $var = ServerRequestFactory::fromGlobals();
})
// put response factory...
->add('responseFactory', new ResponseFactory)
// ...and middleware Dispatcher with fallback stub
->add('dispatcher', new Dispatcher(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        app()->log('fallback')->log($request->getUri());
        return app()->responseFactory->createResponse(404, 'not found');
    }
}))
// put demo logger as callable
->add('log', function (Application $app) {
    return function (string $msg) use ($app) {
        file_put_contents('php://stderr', $msg . PHP_EOL);
        return $app; // allow chaining
    };
})
;
app()->dispatcher
// 3rd party ---------------------------
// echo response headers and body to client
->enqueue(new Middlewares\Emitter)
// detect a clinet IP and save it a request attribute
->enqueue(new Middlewares\ClientIp)
// add cntent-length to response
->enqueue(new Middlewares\ContentLength)
// custom ------------------------------
->enqueue(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        app()->log('demo3: catch a client ip from Middlewares\ClientIp');
        $response = $handler->handle($request);
        if ($ip = $request->getAttribute('client-ip')) {
            $response = $response->withHeader('X-Client-Ip', $ip);
            app()->log('X-Client-Ip: ' . $ip);
        }
        return $response;
    }
})
->enqueue(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        app()->log('demo2: some advertisement')->log('X-Powered-By: ailixter/puzzle');
        return $handler->handle($request)->withHeader('X-Powered-By', 'ailixter/puzzle');
    }
})
->enqueue(new class implements MiddlewareInterface {
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        app()->log('demo1: make some content');
        $response = app()->responseFactory->createResponse(200, "OK")->withHeader('Content-Type', 'text/plain');
        $body = $response->getBody();
        $body->write("http method:  {$request->getMethod()}\n");
        $body->write("http version: {$request->getProtocolVersion()}\n");
        $body->write("request uri:  " . print_r($request->getUri(), true));
        $body->write("body stream:  " . print_r($body->getMetadata(), true));
        return $response;
    }
})
;
app()->run();

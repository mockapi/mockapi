<?php

namespace Mockapi\Mockapi;

use \Exception;
use \Symfony\Component\HttpKernel\Exception\HttpException;

use \Symfony\Component\HttpFoundation\Response;
use \Symfony\Component\HttpFoundation\JsonResponse;

use \Mockapi\Interfaces\HttpInterface;

class Router implements HttpInterface
{
    public static $app;
    public static $services;

    protected static function identifierType($v)
    {
        if (preg_match('|^[\d]+$|', $v, $m)) {
            return 'index';
        }

        if (preg_match('|[\dabcdef]{8}-[\dabcdef]{4}-[\dabcdef]{4}-[\dabcdef]{4}-[\dabcdef]{12}|', $v, $m)) {
            return 'id';
        }

        if (preg_match('|^[\d\w-_@]+$|', $v, $m)) {
            return 'slug';
        }

        throw new HttpException(500, "Unknown identificator type in URL: `{$v}`");
    }

    protected function respond($response, $code = 200)
    {
        if ($response instanceof JsonResponse || $response instanceof Response) {
            return $response;
        }

        if ($response instanceof HttpException) {
            $code = $response->getStatusCode();
            $status = $code ? constant('self::HTTP_'.$code) : false;

            if (!$status) {
                $code = 500;
                $status = constant('self::HTTP_500');
            }

            return static::$app->json((object) [
                'status' => "HTTP/1.1 {$code} {$status}",
                'title' => $response->getMessage()
            ], $code);
        }

        if ($response instanceof Exception) {
            $code = $response->getCode();
            $status = $code ? constant('self::HTTP_'.$code) : false;

            if (!$status) {
                $code = 500;
                $status = constant('self::HTTP_500');
            }

            return static::$app->json((object) [
                'status' => "HTTP/1.1 {$code} {$status}",
                'title' => $response->getMessage()
            ], $code);
        }

        return static::$app->json($response, $code);
    }

    protected function getConstrains()
    {
        $Mockapi = static::$app['Mockapi'];

        foreach ($Mockapi->constrains as $constrainResource => &$constrainIdentifier) {
            $constrainResource = static::$services->get($constrainResource);

            foreach (explode(',', $constrainIdentifier) as $constrainIdentifierPart) {
                $constrainIdentifier = $constrainResource->get([static::identifierType($constrainIdentifierPart) => $constrainIdentifierPart], null, 1)->data->id;
            }
        }

        return $Mockapi->constrains;
    }

    public function getPayload()
    {
        return json_decode(json_encode(static::$app['request']->request->all()));
    }

    public function get($type = null, $id = null, $attr = null)
    {
        try {
            $constrains = $this->getConstrains();
            $service = static::$services->get($type);

            $limitDefault = null;

            if (!empty($id)) {
                $identifierType = static::identifierType($id);
                $limitDefault = 1;

                if ($identifierType !== 'id') {
                    $id = [$identifierType => $id];
                }
            }

            $where = empty($id) ? array_merge($constrains, static::$app['request']->get('filter', [])) : $id;

            return $this->respond($service->get(
                $where,
                $attr,
                static::$app['request']->get('limit', $limitDefault),
                static::$app['request']->get('offset', 0),
                static::$app['request']->get('sort', null)
            ));
        } catch (Exception $e) {
            return $this->respond($e);
        }
    }

    public function post($type = null, $id = null, $attr = null)
    {
        try {
            if ($id !== null) {
                $identifier = static::identifierType($id);

                if ($identifier !== 'id') {
                    throw new HttpException(self::HTTP_BAD_REQUEST, sprintf("Second argument must be UUID v4, %s given.", $identifier));
                }
            }

            $payload = $this->getPayload();
            $service = static::$services->get($type);

            return $this->respond($service->post($payload, static::$app['Mockapi']->constrains, $attr), self::HTTP_CREATED);
        } catch (Exception $e) {
            return $this->respond($e);
        }
    }
}
